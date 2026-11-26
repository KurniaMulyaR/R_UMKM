<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class ProductController extends Controller
{
    public function index(Request $request): View
    {
        $tenant = $request->attributes->get('tenant');
        $outlet = $request->attributes->get('outlet');
        $archived = $request->boolean('archived');
        $productQuery = DB::table('products')->join('product_variants', 'product_variants.product_id', '=', 'products.id')
            ->leftJoin('product_categories', 'product_categories.id', '=', 'products.category_id')
            ->leftJoin('product_prices', function ($join) use ($outlet) {
                $join->on('product_prices.product_variant_id', '=', 'product_variants.id')->where('product_prices.price_type', '=', 'retail')->where('product_prices.is_active', '=', true)->whereColumn('product_prices.unit_id', 'products.base_unit_id')
                    ->where(function ($q) use ($outlet) { $q->whereNull('product_prices.outlet_id')->orWhere('product_prices.outlet_id', $outlet->id); });
            })->leftJoin('stock_balances', function ($join) use ($outlet) { $join->on('stock_balances.product_variant_id', '=', 'product_variants.id')->where('stock_balances.outlet_id', '=', $outlet->id); })
            ->where('products.tenant_id', $tenant->id)
            ->when($archived, fn ($query) => $query->whereNotNull('products.deleted_at'), fn ($query) => $query->whereNull('products.deleted_at'))
            ->select('products.*', 'product_variants.name as variant_name', 'product_variants.id as variant_id', 'product_variants.cost_price', 'product_categories.name as category_name', 'product_prices.price', 'stock_balances.quantity_on_hand')
            ->selectSub(DB::table('product_barcodes')->select('barcode')->whereColumn('product_barcodes.product_variant_id', 'product_variants.id')->orderByDesc('is_primary')->limit(1), 'barcode')
            ->orderBy('products.name');
        $products = $productQuery->paginate(15)->withQueryString();
        $archivedCount = DB::table('products')->where('tenant_id', $tenant->id)->whereNotNull('deleted_at')->count();
        $categories = DB::table('product_categories')->where('tenant_id', $tenant->id)->whereNull('deleted_at')->where('is_active', true)->orderBy('sort_order')->orderBy('name')->get();
        $units = DB::table('units')->where('tenant_id', $tenant->id)->orderBy('name')->get();
        return view('products.index', compact('products', 'categories', 'units', 'archived', 'archivedCount'));
    }

    public function storeCategory(Request $request): RedirectResponse
    {
        $tenant = $request->attributes->get('tenant');
        $request->merge(['category_name' => trim((string) $request->input('category_name'))]);
        $data = $request->validate([
            'category_name' => [
                'required', 'string', 'max:100',
                Rule::unique('product_categories', 'name')->where(fn ($query) => $query
                    ->where('tenant_id', $tenant->id)->whereNull('deleted_at')),
            ],
        ], [
            'category_name.unique' => 'Kategori dengan nama tersebut sudah ada.',
        ]);

        $now = now();
        DB::table('product_categories')->insert([
            'id' => (string) Str::uuid(),
            'tenant_id' => $tenant->id,
            'parent_id' => null,
            'name' => trim($data['category_name']),
            'sort_order' => ((int) DB::table('product_categories')->where('tenant_id', $tenant->id)->max('sort_order')) + 10,
            'is_active' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return redirect()->route('products.index')->with('success', 'Kategori produk berhasil dibuat. Sekarang kategori bisa dipilih saat menambah atau mengedit produk.');
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

    public function show(Request $request, string $id): View
    {
        $tenant = $request->attributes->get('tenant');
        $outlet = $request->attributes->get('outlet');
        $membership = $request->attributes->get('tenant_membership');
        $product = DB::table('products')
            ->leftJoin('product_categories', 'product_categories.id', '=', 'products.category_id')
            ->leftJoin('units', 'units.id', '=', 'products.base_unit_id')
            ->where('products.tenant_id', $tenant->id)->where('products.id', $id)->whereNull('products.deleted_at')
            ->select('products.*', 'product_categories.name as category_name', 'units.name as unit_name', 'units.symbol as unit_symbol')
            ->first();
        abort_unless($product, 404);

        $variants = DB::table('product_variants')->where('tenant_id', $tenant->id)->where('product_id', $product->id)->orderBy('name')->get();
        abort_unless($variants->isNotEmpty(), 404);
        $variantIds = $variants->pluck('id');
        $allowedOutletIds = DB::table('user_outlet')->where('tenant_user_id', $membership->id)->where('status', 'active')->pluck('outlet_id');

        $balances = DB::table('stock_balances')->join('outlets', 'outlets.id', '=', 'stock_balances.outlet_id')
            ->where('stock_balances.tenant_id', $tenant->id)->whereIn('stock_balances.product_variant_id', $variantIds)
            ->when($allowedOutletIds->isNotEmpty(), fn ($query) => $query->whereIn('stock_balances.outlet_id', $allowedOutletIds))
            ->select('outlets.id as outlet_id', 'outlets.name as outlet_name', 'outlets.code as outlet_code')
            ->selectRaw('SUM(stock_balances.quantity_on_hand) as quantity_on_hand, SUM(stock_balances.inventory_value) as inventory_value')
            ->groupBy('outlets.id', 'outlets.name', 'outlets.code')->orderBy('outlets.name')->get();

        $balanceRows = DB::table('stock_balances')->where('tenant_id', $tenant->id)->whereIn('product_variant_id', $variantIds)
            ->when($allowedOutletIds->isNotEmpty(), fn ($query) => $query->whereIn('outlet_id', $allowedOutletIds))
            ->get(['outlet_id', 'product_variant_id', 'quantity_on_hand']);

        $movementRows = DB::table('stock_movements')
            ->join('outlets', 'outlets.id', '=', 'stock_movements.outlet_id')
            ->join('product_variants', 'product_variants.id', '=', 'stock_movements.product_variant_id')
            ->leftJoin('users', 'users.id', '=', 'stock_movements.created_by_user_id')
            ->leftJoin('sales as source_sales', function ($join) {
                $join->on('source_sales.id', '=', 'stock_movements.source_id')->whereIn('stock_movements.source_type', ['sale', 'sale_void']);
            })
            ->leftJoin('goods_receipts as source_receipts', function ($join) {
                $join->on('source_receipts.id', '=', 'stock_movements.source_id')->where('stock_movements.source_type', '=', 'goods_receipt');
            })
            ->leftJoin('purchase_returns as source_purchase_returns', function ($join) {
                $join->on('source_purchase_returns.id', '=', 'stock_movements.source_id')->where('stock_movements.source_type', '=', 'purchase_return');
            })
            ->leftJoin('sale_returns as source_sale_returns', function ($join) {
                $join->on('source_sale_returns.id', '=', 'stock_movements.source_id')->where('stock_movements.source_type', '=', 'sale_return');
            })
            ->leftJoin('stock_counts as source_counts', function ($join) {
                $join->on('source_counts.id', '=', 'stock_movements.source_id')->where('stock_movements.source_type', '=', 'stock_count');
            })
            ->leftJoin('stock_transfers as source_transfers', function ($join) {
                $join->on('source_transfers.id', '=', 'stock_movements.source_id')->where('stock_movements.source_type', '=', 'stock_transfer');
            })
            ->where('stock_movements.tenant_id', $tenant->id)->whereIn('stock_movements.product_variant_id', $variantIds)
            ->when($allowedOutletIds->isNotEmpty(), fn ($query) => $query->whereIn('stock_movements.outlet_id', $allowedOutletIds))
            ->orderBy('stock_movements.occurred_at')->orderBy('stock_movements.id')
            ->select(
                'stock_movements.*', 'outlets.name as outlet_name', 'outlets.code as outlet_code',
                'product_variants.name as variant_name', 'users.name as actor_name',
            )
            ->selectRaw('COALESCE(source_sales.sale_number, source_receipts.receipt_number, source_purchase_returns.return_number, source_sale_returns.return_number, source_counts.count_number, source_transfers.transfer_number, stock_movements.reason, stock_movements.source_id) as reference_number')
            ->get();

        $balanceByKey = $balanceRows->keyBy(fn ($row) => $row->outlet_id.'|'.$row->product_variant_id);
        $deltaByKey = $movementRows->groupBy(fn ($row) => $row->outlet_id.'|'.$row->product_variant_id)
            ->map(fn ($rows) => (float) $rows->sum('quantity_delta'));
        $running = [];
        foreach ($balanceByKey as $key => $row) {
            $running[$key] = (float) $row->quantity_on_hand - (float) ($deltaByKey[$key] ?? 0);
        }

        $movementLabels = [
            'opening' => 'Stok awal', 'sale' => 'Penjualan', 'sale_void' => 'Pembatalan penjualan',
            'purchase_receipt' => 'Pembelian masuk', 'purchase_return' => 'Retur pembelian', 'sale_return' => 'Retur penjualan',
            'count_adjustment' => 'Opname / penyesuaian', 'transfer_out' => 'Transfer keluar',
            'transfer_in' => 'Transfer masuk', 'transfer_cancel' => 'Pembatalan transfer',
        ];
        foreach ($movementRows as $movement) {
            $key = $movement->outlet_id.'|'.$movement->product_variant_id;
            $running[$key] ??= -(float) ($deltaByKey[$key] ?? 0);
            $running[$key] += (float) $movement->quantity_delta;
            $movement->balance_after = $running[$key];
            $movement->activity_label = $movementLabels[$movement->movement_type] ?? Str::headline($movement->movement_type);
        }
        $movements = $movementRows->reverse()->values();

        $prices = DB::table('product_prices')->leftJoin('outlets', 'outlets.id', '=', 'product_prices.outlet_id')
            ->where('product_prices.tenant_id', $tenant->id)->whereIn('product_prices.product_variant_id', $variantIds)
            ->where('product_prices.price_type', 'retail')->where('product_prices.is_active', true)
            ->where(fn ($query) => $query->whereNull('product_prices.outlet_id')->orWhereIn('product_prices.outlet_id', $allowedOutletIds->isEmpty() ? DB::table('outlets')->where('tenant_id', $tenant->id)->select('id') : $allowedOutletIds))
            ->select('product_prices.*', 'outlets.name as outlet_name')->orderBy('outlets.name')->get();
        $barcodes = DB::table('product_barcodes')->where('tenant_id', $tenant->id)->whereIn('product_variant_id', $variantIds)
            ->orderByDesc('is_primary')->orderBy('barcode')->get();
        $editVariant = $variants->first();
        $editPrice = $prices->firstWhere('outlet_id', $outlet->id) ?? $prices->firstWhere('outlet_id', null) ?? $prices->first();
        $editBarcode = $barcodes->firstWhere('product_variant_id', $editVariant->id)?->barcode;
        $categories = DB::table('product_categories')->where('tenant_id', $tenant->id)->whereNull('deleted_at')->where('is_active', true)->orderBy('sort_order')->orderBy('name')->get();
        $units = DB::table('units')->where('tenant_id', $tenant->id)->orderBy('name')->get();

        return view('products.show', compact('product', 'variants', 'balances', 'movements', 'prices', 'barcodes', 'tenant', 'categories', 'units', 'editVariant', 'editPrice', 'editBarcode'));
    }

    public function toggleStatus(Request $request, string $id): RedirectResponse
    {
        $tenant = $request->attributes->get('tenant');
        $product = DB::table('products')->where('tenant_id', $tenant->id)->where('id', $id)->whereNull('deleted_at')->first();
        abort_unless($product, 404);
        $isActive = ! (bool) $product->is_active;
        $now = now();

        DB::transaction(function () use ($tenant, $product, $isActive, $now) {
            DB::table('products')->where('tenant_id', $tenant->id)->where('id', $product->id)->update(['is_active' => $isActive, 'updated_at' => $now]);
            DB::table('product_variants')->where('tenant_id', $tenant->id)->where('product_id', $product->id)->update(['is_active' => $isActive, 'updated_at' => $now]);

        });

        return back()->with('success', $isActive ? 'Produk diaktifkan kembali.' : 'Produk dinonaktifkan dan tidak tampil di kasir.');
    }

    public function destroy(Request $request, string $id): RedirectResponse
    {
        $tenant = $request->attributes->get('tenant');
        $product = DB::table('products')->where('tenant_id', $tenant->id)->where('id', $id)->first();
        abort_unless($product, 404);
        $variantIds = DB::table('product_variants')->where('tenant_id', $tenant->id)->where('product_id', $product->id)->pluck('id');

        if ($variantIds->isNotEmpty() && DB::table('stock_movements')->where('tenant_id', $tenant->id)->whereIn('product_variant_id', $variantIds)->exists()) {
            return back()->withErrors(['product' => 'Produk sudah punya histori stok. Arsipkan produk agar histori transaksi tetap aman.']);
        }

        try {
            DB::table('products')->where('tenant_id', $tenant->id)->where('id', $product->id)->delete();
        } catch (QueryException) {
            return back()->withErrors(['product' => 'Produk masih terhubung ke data lain. Arsipkan produk untuk menyembunyikannya tanpa menghapus riwayat.']);
        }

        return redirect()->route('products.index')->with('success', 'Produk dihapus permanen.');
    }

    public function archive(Request $request, string $id): RedirectResponse
    {
        $tenantId = $request->attributes->get('tenant')->id;
        $product = DB::table('products')->where('tenant_id', $tenantId)->where('id', $id)->whereNull('deleted_at')->first();
        abort_unless($product, 404);
        $now = now();
        DB::transaction(function () use ($tenantId, $product, $now) {
            DB::table('products')->where('tenant_id', $tenantId)->where('id', $product->id)
                ->update(['is_active' => false, 'deleted_at' => $now, 'updated_at' => $now]);
            DB::table('product_variants')->where('tenant_id', $tenantId)->where('product_id', $product->id)
                ->update(['is_active' => false, 'updated_at' => $now]);
        });
        return back()->with('success', 'Produk diarsipkan. Riwayat transaksi tetap tersimpan.');
    }

    public function restore(Request $request, string $id): RedirectResponse
    {
        $tenantId = $request->attributes->get('tenant')->id;
        $product = DB::table('products')->where('tenant_id', $tenantId)->where('id', $id)->whereNotNull('deleted_at')->first();
        abort_unless($product, 404);
        $now = now();
        DB::transaction(function () use ($tenantId, $product, $now) {
            DB::table('products')->where('tenant_id', $tenantId)->where('id', $product->id)
                ->update(['is_active' => true, 'deleted_at' => null, 'updated_at' => $now]);
            DB::table('product_variants')->where('tenant_id', $tenantId)->where('product_id', $product->id)
                ->update(['is_active' => true, 'updated_at' => $now]);
        });

        return redirect()->route('products.index')->with('success', 'Produk dipulihkan dan diaktifkan kembali.');
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
        $categories = DB::table('product_categories')->where('tenant_id', $tenant->id)->whereNull('deleted_at')->where('is_active', true)->orderBy('sort_order')->orderBy('name')->get();
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
                'occurred_at' => $now,
            ]);

        });
        return redirect()->route('products.index')->with('success', 'Produk diperbarui. Harga lama tetap tersimpan sebagai riwayat.');
    }
}
