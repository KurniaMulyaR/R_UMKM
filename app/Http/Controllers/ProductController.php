<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\View\View;

class ProductController extends Controller
{
    public function index(Request $request): View
    {
        $tenant = $request->attributes->get('tenant');
        $outlet = $request->attributes->get('outlet');
        $products = DB::table('products')->join('product_variants', 'product_variants.product_id', '=', 'products.id')
            ->leftJoin('product_categories', 'product_categories.id', '=', 'products.category_id')
            ->leftJoin('product_prices', function ($join) use ($outlet) {
                $join->on('product_prices.product_variant_id', '=', 'product_variants.id')->where('product_prices.price_type', '=', 'retail')->where('product_prices.is_active', '=', true)->whereColumn('product_prices.unit_id', 'products.base_unit_id')
                    ->where(function ($q) use ($outlet) { $q->whereNull('product_prices.outlet_id')->orWhere('product_prices.outlet_id', $outlet->id); });
            })->leftJoin('stock_balances', function ($join) use ($outlet) { $join->on('stock_balances.product_variant_id', '=', 'product_variants.id')->where('stock_balances.outlet_id', '=', $outlet->id); })
            ->where('products.tenant_id', $tenant->id)->whereNull('products.deleted_at')->where('product_variants.is_active', true)
            ->select('products.*', 'product_variants.name as variant_name', 'product_variants.id as variant_id', 'product_variants.cost_price', 'product_categories.name as category_name', 'product_prices.price', 'stock_balances.quantity_on_hand')
            ->orderBy('products.name')->paginate(15);
        $categories = DB::table('product_categories')->where('tenant_id', $tenant->id)->where('is_active', true)->orderBy('sort_order')->get();
        $units = DB::table('units')->where('tenant_id', $tenant->id)->orderBy('name')->get();
        return view('products.index', compact('products', 'categories', 'units'));
    }

    public function store(Request $request): RedirectResponse
    {
        $tenant = $request->attributes->get('tenant');
        $outlet = $request->attributes->get('outlet');
        $data = $request->validate([
            'name' => ['required', 'string', 'max:160'], 'sku' => ['nullable', 'string', 'max:80'],
            'category_id' => ['nullable', 'uuid', 'exists:product_categories,id'], 'unit_id' => ['required', 'uuid', 'exists:units,id'],
            'price' => ['required', 'numeric', 'min:0'], 'cost_price' => ['nullable', 'numeric', 'min:0'],
            'initial_stock' => ['nullable', 'numeric', 'min:0'], 'min_stock' => ['nullable', 'numeric', 'min:0'],
            'barcode' => ['nullable', 'string', 'max:128'], 'track_stock' => ['nullable', 'boolean'],
        ]);
        if (! empty($data['category_id'])) abort_unless(DB::table('product_categories')->where('tenant_id', $tenant->id)->where('id', $data['category_id'])->exists(), 404);
        abort_unless(DB::table('units')->where('tenant_id', $tenant->id)->where('id', $data['unit_id'])->exists(), 422);
        $sku = trim($data['sku'] ?? '') ?: 'SKU-'.Str::upper(Str::random(8));
        if (DB::table('products')->where('tenant_id', $tenant->id)->where('sku', $sku)->exists()) return back()->withErrors(['sku' => 'SKU sudah dipakai usaha ini.'])->withInput();
        if (! empty($data['barcode']) && DB::table('product_barcodes')->where('tenant_id', $tenant->id)->where('barcode', $data['barcode'])->exists()) return back()->withErrors(['barcode' => 'Barcode ini sudah dipakai produk lain.'])->withInput();

        DB::transaction(function () use ($data, $tenant, $outlet, $sku, $request) {
            $now = now(); $productId = (string) Str::uuid(); $variantId = (string) Str::uuid();
            $trackStock = $request->boolean('track_stock', true);
            DB::table('products')->insert([
                'id' => $productId, 'tenant_id' => $tenant->id, 'category_id' => $data['category_id'] ?? null,
                'base_unit_id' => $data['unit_id'], 'sku' => $sku, 'name' => $data['name'], 'product_type' => 'stocked',
                'track_stock' => $trackStock, 'allow_negative_stock' => false, 'is_weighed' => false, 'is_serialized' => false,
                'min_stock' => $data['min_stock'] ?? 0, 'is_favorite' => false, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now,
            ]);
            DB::table('product_variants')->insert([
                'id' => $variantId, 'tenant_id' => $tenant->id, 'product_id' => $productId, 'name' => 'Default', 'sku' => $sku,
                'cost_price' => $data['cost_price'] ?? 0, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now,
            ]);
            DB::table('product_prices')->insert([
                'id' => (string) Str::uuid(), 'tenant_id' => $tenant->id, 'product_variant_id' => $variantId, 'outlet_id' => $outlet->id,
                'unit_id' => $data['unit_id'], 'price_type' => 'retail', 'price' => $data['price'], 'is_active' => true,
                'created_at' => $now, 'updated_at' => $now,
            ]);
            if (! empty($data['barcode'])) DB::table('product_barcodes')->insert([
                'id' => (string) Str::uuid(), 'tenant_id' => $tenant->id, 'product_variant_id' => $variantId,
                'unit_id' => $data['unit_id'], 'barcode' => $data['barcode'], 'type' => 'internal', 'is_primary' => true, 'created_at' => $now, 'updated_at' => $now,
            ]);
            $opening = $trackStock ? (float) ($data['initial_stock'] ?? 0) : 0;
            DB::table('stock_balances')->insert([
                'id' => (string) Str::uuid(), 'tenant_id' => $tenant->id, 'outlet_id' => $outlet->id, 'product_variant_id' => $variantId,
                'quantity_on_hand' => $opening, 'inventory_value' => $opening * (float) ($data['cost_price'] ?? 0),
                'average_cost' => $data['cost_price'] ?? 0, 'version' => 0, 'created_at' => $now, 'updated_at' => $now,
            ]);
            if ($opening > 0) DB::table('stock_movements')->insert([
                'id' => (string) Str::uuid(), 'tenant_id' => $tenant->id, 'outlet_id' => $outlet->id, 'product_variant_id' => $variantId,
                'occurred_at' => $now, 'movement_type' => 'opening', 'quantity_delta' => $opening, 'unit_cost' => $data['cost_price'] ?? 0,
                'value_delta' => $opening * (float) ($data['cost_price'] ?? 0), 'source_type' => 'product_opening', 'source_id' => $productId,
                'idempotency_key' => 'opening:'.$variantId, 'created_by_user_id' => auth()->id(), 'reason' => 'Stok awal produk', 'created_at' => $now, 'updated_at' => $now,
            ]);
            $openingValue = round($opening * (float) ($data['cost_price'] ?? 0), 2);
            if ($openingValue > 0) {
                $inventoryAccount = DB::table('chart_of_accounts')->where('tenant_id', $tenant->id)->where('code', '1100')->first();
                $equityAccount = DB::table('chart_of_accounts')->where('tenant_id', $tenant->id)->where('code', '3010')->first();
                if (! $equityAccount) {
                    $equityId = (string) Str::uuid();
                    DB::table('chart_of_accounts')->insert([
                        'id' => $equityId, 'tenant_id' => $tenant->id, 'code' => '3010', 'name' => 'Modal Pemilik', 'account_type' => 'equity',
                        'normal_balance' => 'credit', 'is_system' => true, 'allow_manual_posting' => false, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now,
                    ]);
                    $equityAccount = (object) ['id' => $equityId];
                }
                if ($inventoryAccount) {
                    $entryId = (string) Str::uuid();
                    DB::table('journal_entries')->insert([
                        'id' => $entryId, 'tenant_id' => $tenant->id, 'outlet_id' => $outlet->id, 'entry_number' => 'OPN-'.Str::upper(Str::random(8)),
                        'entry_date' => $now->toDateString(), 'status' => 'posted', 'description' => 'Stok awal '.$data['name'], 'source_type' => 'product_opening',
                        'source_id' => $productId, 'idempotency_key' => 'opening:'.$variantId.':journal', 'posted_at' => $now,
                        'posted_by_user_id' => auth()->id(), 'created_by_user_id' => auth()->id(), 'created_at' => $now, 'updated_at' => $now,
                    ]);
                    foreach ([[$inventoryAccount->id, $openingValue, 0], [$equityAccount->id, 0, $openingValue]] as [$accountId, $debit, $credit]) {
                        DB::table('journal_lines')->insert([
                            'id' => (string) Str::uuid(), 'tenant_id' => $tenant->id, 'journal_entry_id' => $entryId, 'account_id' => $accountId,
                            'product_variant_id' => $variantId, 'description' => 'Stok awal '.$data['name'], 'debit' => $debit, 'credit' => $credit,
                            'created_at' => $now, 'updated_at' => $now,
                        ]);
                    }
                }
            }
        });

        return redirect()->route('products.index')->with('success', 'Produk berhasil ditambahkan.');
    }

    public function archive(Request $request, string $id): RedirectResponse
    {
        DB::table('products')->where('tenant_id', $request->attributes->get('tenant')->id)->where('id', $id)
            ->update(['is_active' => false, 'deleted_at' => now(), 'updated_at' => now()]);
        return back()->with('success', 'Produk diarsipkan. Riwayat transaksi tetap tersimpan.');
    }
    public function edit(Request $request, string $id): View
    {
        $tenant = $request->attributes->get('tenant');
        $outlet = $request->attributes->get('outlet');
        $product = DB::table('products')->where('tenant_id', $tenant->id)->where('id', $id)->whereNull('deleted_at')->first();
        abort_unless($product, 404);
        $variant = DB::table('product_variants')->where('tenant_id', $tenant->id)->where('product_id', $id)->orderBy('created_at')->first();
        abort_unless($variant, 404);
        $price = DB::table('product_prices')->where('tenant_id', $tenant->id)->where('product_variant_id', $variant->id)
            ->where('price_type', 'retail')->where('is_active', true)
            ->where(fn ($q) => $q->whereNull('outlet_id')->orWhere('outlet_id', $outlet->id))
            ->orderByRaw('outlet_id = ? desc', [$outlet->id])->first();
        $barcode = DB::table('product_barcodes')->where('tenant_id', $tenant->id)->where('product_variant_id', $variant->id)->value('barcode');
        $categories = DB::table('product_categories')->where('tenant_id', $tenant->id)->where('is_active', true)->orderBy('sort_order')->get();
        $units = DB::table('units')->where('tenant_id', $tenant->id)->orderBy('name')->get();
        return view('products.edit', compact('product', 'variant', 'price', 'barcode', 'categories', 'units'));
    }

    public function update(Request $request, string $id): RedirectResponse
    {
        $tenant = $request->attributes->get('tenant');
        $outlet = $request->attributes->get('outlet');
        $product = DB::table('products')->where('tenant_id', $tenant->id)->where('id', $id)->whereNull('deleted_at')->first();
        abort_unless($product, 404);
        $variant = DB::table('product_variants')->where('tenant_id', $tenant->id)->where('product_id', $id)->orderBy('created_at')->first();
        abort_unless($variant, 404);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:160'], 'sku' => ['required', 'string', 'max:80'],
            'category_id' => ['nullable', 'uuid'], 'unit_id' => ['required', 'uuid'],
            'price' => ['required', 'numeric', 'min:0'], 'cost_price' => ['nullable', 'numeric', 'min:0'],
            'min_stock' => ['nullable', 'numeric', 'min:0'], 'barcode' => ['nullable', 'string', 'max:128'],
            'track_stock' => ['nullable', 'boolean'],
        ]);
        if (DB::table('products')->where('tenant_id', $tenant->id)->where('sku', $data['sku'])->where('id', '<>', $id)->exists()) {
            return back()->withErrors(['sku' => 'SKU sudah dipakai produk lain.'])->withInput();
        }
        abort_unless(DB::table('units')->where('tenant_id', $tenant->id)->where('id', $data['unit_id'])->exists(), 422);
        if (! empty($data['category_id'])) abort_unless(DB::table('product_categories')->where('tenant_id', $tenant->id)->where('id', $data['category_id'])->exists(), 422);
        if (! empty($data['barcode']) && DB::table('product_barcodes')->where('tenant_id', $tenant->id)->where('barcode', $data['barcode'])->where('product_variant_id', '<>', $variant->id)->exists()) {
            return back()->withErrors(['barcode' => 'Barcode sudah dipakai produk lain.'])->withInput();
        }

        DB::transaction(function () use ($data, $tenant, $outlet, $product, $variant, $request) {
            $now = now();
            DB::table('products')->where('id', $product->id)->update([
                'name' => $data['name'], 'sku' => $data['sku'], 'category_id' => $data['category_id'] ?? null,
                'base_unit_id' => $data['unit_id'], 'track_stock' => $request->boolean('track_stock'),
                'min_stock' => $data['min_stock'] ?? 0, 'updated_at' => $now,
            ]);
            DB::table('product_variants')->where('id', $variant->id)->update([
                'sku' => $data['sku'], 'cost_price' => $data['cost_price'] ?? 0, 'updated_at' => $now,
            ]);
            DB::table('product_prices')->where('tenant_id', $tenant->id)->where('product_variant_id', $variant->id)
                ->where('outlet_id', $outlet->id)->where('price_type', 'retail')->where('is_active', true)
                ->update(['is_active' => false, 'valid_until' => $now, 'updated_at' => $now]);
            DB::table('product_prices')->insert([
                'id' => (string) Str::uuid(), 'tenant_id' => $tenant->id, 'product_variant_id' => $variant->id,
                'outlet_id' => $outlet->id, 'unit_id' => $data['unit_id'], 'price_type' => 'retail',
                'price' => $data['price'], 'valid_from' => $now, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now,
            ]);
            if (! empty($data['barcode'])) {
                $existing = DB::table('product_barcodes')->where('tenant_id', $tenant->id)->where('product_variant_id', $variant->id)->first();
                if ($existing) DB::table('product_barcodes')->where('id', $existing->id)->update(['barcode' => $data['barcode'], 'unit_id' => $data['unit_id'], 'updated_at' => $now]);
                else DB::table('product_barcodes')->insert([
                    'id' => (string) Str::uuid(), 'tenant_id' => $tenant->id, 'product_variant_id' => $variant->id,
                    'unit_id' => $data['unit_id'], 'barcode' => $data['barcode'], 'type' => 'internal', 'is_primary' => true,
                    'created_at' => $now, 'updated_at' => $now,
                ]);
            }
            DB::table('audit_logs')->insert([
                'id' => (string) Str::uuid(), 'tenant_id' => $tenant->id, 'outlet_id' => $outlet->id,
                'actor_user_id' => auth()->id(), 'action' => 'product.updated', 'subject_type' => 'product',
                'subject_id' => $product->id, 'after' => json_encode(['name' => $data['name'], 'sku' => $data['sku'], 'price' => $data['price']]),
                'occurred_at' => $now, 'created_at' => $now,
            ]);
        });
        return redirect()->route('products.index')->with('success', 'Produk diperbarui. Harga lama tetap tersimpan sebagai riwayat.');
    }
}
