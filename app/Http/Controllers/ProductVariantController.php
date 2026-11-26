<?php

namespace App\Http\Controllers;

use App\Models\AccountingPeriod;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class ProductVariantController extends Controller
{
    public function index(Request $request, string $id): View
    {
        $tenant = $request->attributes->get('tenant');
        $outlet = $request->attributes->get('outlet');
        $product = $this->product($tenant->id, $id);
        $baseUnit = DB::table('units')->where('id', $product->base_unit_id)->first();
        $variants = DB::table('product_variants')->where('tenant_id', $tenant->id)->where('product_id', $id)->where('is_active', true)->orderBy('created_at')->get();
        $stocks = DB::table('stock_balances')->where('tenant_id', $tenant->id)->where('outlet_id', $outlet->id)->whereIn('product_variant_id', $variants->pluck('id'))->pluck('quantity_on_hand', 'product_variant_id');
        $prices = DB::table('product_prices')->where('tenant_id', $tenant->id)->whereIn('product_variant_id', $variants->pluck('id'))->where('price_type', 'retail')->where('is_active', true)
            ->where(fn ($query) => $query->whereNull('outlet_id')->orWhere('outlet_id', $outlet->id))->get()
            ->sortByDesc(fn ($price) => $price->outlet_id === $outlet->id ? 1 : 0)->groupBy(fn ($price) => $price->product_variant_id.'|'.$price->unit_id)->map->first();
        $conversions = DB::table('product_unit_conversions')->join('units', 'units.id', '=', 'product_unit_conversions.unit_id')->where('product_unit_conversions.product_id', $id)
            ->orderBy('product_unit_conversions.base_quantity')->get(['product_unit_conversions.*', 'units.name as unit_name', 'units.symbol']);
        $units = DB::table('units')->where('tenant_id', $tenant->id)->where('id', '!=', $product->base_unit_id)->whereNotIn('id', $conversions->pluck('unit_id'))->orderBy('name')->get();

        return view('products.variants', compact('product', 'baseUnit', 'variants', 'stocks', 'prices', 'conversions', 'units'));
    }

    public function storeVariant(Request $request, string $id): RedirectResponse
    {
        $tenant = $request->attributes->get('tenant');
        $outlet = $request->attributes->get('outlet');
        $product = $this->product($tenant->id, $id);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'], 'sku' => ['nullable', 'string', 'max:80'], 'price' => ['required', 'numeric', 'min:0', 'max:99999999999999'],
            'cost_price' => ['nullable', 'numeric', 'min:0', 'max:99999999999999'], 'initial_stock' => ['nullable', 'numeric', 'min:0', 'max:999999999'], 'barcode' => ['nullable', 'string', 'max:128'],
        ]);
        if (DB::table('product_variants')->where('tenant_id', $tenant->id)->where('product_id', $id)->where('is_active', true)->whereRaw('LOWER(name) = ?', [mb_strtolower($data['name'])])->exists()) {
            throw ValidationException::withMessages(['name' => 'Nama varian sudah ada untuk produk ini.']);
        }
        $sku = trim($data['sku'] ?? '') ?: $product->sku.'-'.Str::upper(Str::random(4));
        if (DB::table('product_variants')->where('tenant_id', $tenant->id)->where('sku', $sku)->exists() || DB::table('products')->where('tenant_id', $tenant->id)->where('sku', $sku)->exists()) {
            throw ValidationException::withMessages(['sku' => 'SKU sudah dipakai.']);
        }
        if (! empty($data['barcode']) && DB::table('product_barcodes')->where('tenant_id', $tenant->id)->where('barcode', $data['barcode'])->exists()) {
            throw ValidationException::withMessages(['barcode' => 'Barcode ini sudah dipakai.']);
        }
        $cost = (float) ($data['cost_price'] ?? 0);
        $opening = $product->track_stock ? (float) ($data['initial_stock'] ?? 0) : 0.0;
        $openingValue = round($opening * $cost, 2);
        if ($openingValue > 0) {
            AccountingPeriod::assertOpen($tenant->id, now($tenant->timezone)->toDateString());
        }

        DB::transaction(function () use ($tenant, $outlet, $product, $data, $sku, $cost, $opening, $openingValue) {
            $now = now($tenant->timezone);
            $variantId = (string) Str::uuid();
            DB::table('product_variants')->insert(['id' => $variantId, 'tenant_id' => $tenant->id, 'product_id' => $product->id, 'name' => $data['name'], 'sku' => $sku, 'cost_price' => $cost, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now]);
            DB::table('product_prices')->insert([
                'id' => (string) Str::uuid(), 'tenant_id' => $tenant->id, 'product_variant_id' => $variantId, 'outlet_id' => $outlet->id, 'unit_id' => $product->base_unit_id,
                'price_type' => 'retail', 'price' => $data['price'], 'valid_from' => $now, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now,
            ]);
            if (! empty($data['barcode'])) {
                DB::table('product_barcodes')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $tenant->id, 'product_variant_id' => $variantId, 'unit_id' => $product->base_unit_id, 'barcode' => $data['barcode'], 'type' => 'internal', 'is_primary' => true, 'created_at' => $now, 'updated_at' => $now]);
            }
            DB::table('stock_balances')->insert([
                'id' => (string) Str::uuid(), 'tenant_id' => $tenant->id, 'outlet_id' => $outlet->id, 'product_variant_id' => $variantId, 'quantity_on_hand' => $opening,
                'inventory_value' => $openingValue, 'average_cost' => $cost, 'version' => 0, 'created_at' => $now, 'updated_at' => $now,
            ]);
            if ($opening > 0) {
                DB::table('stock_movements')->insert([
                    'id' => (string) Str::uuid(), 'tenant_id' => $tenant->id, 'outlet_id' => $outlet->id, 'product_variant_id' => $variantId, 'occurred_at' => $now, 'movement_type' => 'opening',
                    'quantity_delta' => $opening, 'unit_cost' => $cost, 'value_delta' => $openingValue, 'source_type' => 'product_opening', 'source_id' => $product->id,
                    'idempotency_key' => 'opening:'.$variantId, 'created_by_user_id' => auth()->id(), 'reason' => 'Stok awal varian', 'created_at' => $now, 'updated_at' => $now,
                ]);
            }
            if ($openingValue > 0) {
                $inventory = $this->accountId($tenant->id, '1100', 'Persediaan', 'asset', 'debit', $now);
                $equity = $this->accountId($tenant->id, '3010', 'Modal Pemilik', 'equity', 'credit', $now);
                $entryId = (string) Str::uuid();
                DB::table('journal_entries')->insert([
                    'id' => $entryId, 'tenant_id' => $tenant->id, 'outlet_id' => $outlet->id, 'entry_number' => 'OPN-'.Str::upper(Str::random(8)), 'entry_date' => $now->toDateString(), 'status' => 'posted',
                    'description' => 'Stok awal '.$product->name.' - '.$data['name'], 'source_type' => 'product_opening', 'source_id' => $product->id, 'idempotency_key' => 'opening:'.$variantId.':journal',
                    'posted_at' => $now, 'posted_by_user_id' => auth()->id(), 'created_by_user_id' => auth()->id(), 'created_at' => $now, 'updated_at' => $now,
                ]);
                foreach ([[$inventory, $openingValue, 0], [$equity, 0, $openingValue]] as [$accountId, $debit, $credit]) {
                    DB::table('journal_lines')->insert([
                        'id' => (string) Str::uuid(), 'tenant_id' => $tenant->id, 'journal_entry_id' => $entryId, 'account_id' => $accountId, 'product_variant_id' => $variantId,
                        'description' => 'Stok awal '.$product->name.' - '.$data['name'], 'debit' => $debit, 'credit' => $credit, 'created_at' => $now, 'updated_at' => $now,
                    ]);
                }
            }
            $this->audit($tenant->id, $outlet->id, 'product.variant_added', $product->id, ['variant' => $data['name'], 'sku' => $sku], $now);
        });

        return back()->with('success', 'Varian ditambahkan.');
    }

    public function archiveVariant(Request $request, string $id, string $variantId): RedirectResponse
    {
        $tenant = $request->attributes->get('tenant');
        $this->product($tenant->id, $id);
        $variants = DB::table('product_variants')->where('tenant_id', $tenant->id)->where('product_id', $id)->where('is_active', true)->get();
        abort_unless($variants->contains('id', $variantId), 404);
        abort_if($variants->count() < 2, 422, 'Produk harus punya minimal satu varian aktif.');
        DB::table('product_variants')->where('id', $variantId)->update(['is_active' => false, 'updated_at' => now()]);
        $this->audit($tenant->id, $request->attributes->get('outlet')->id, 'product.variant_archived', $id, ['variant_id' => $variantId], now());

        return back()->with('success', 'Varian diarsipkan. Riwayat dan stok tetap tersimpan.');
    }

    public function storeUnit(Request $request, string $id): RedirectResponse
    {
        $tenant = $request->attributes->get('tenant');
        $outlet = $request->attributes->get('outlet');
        $product = $this->product($tenant->id, $id);
        $data = $request->validate([
            'unit_id' => ['nullable', 'uuid'], 'new_unit_name' => ['nullable', 'string', 'max:64'], 'new_unit_symbol' => ['nullable', 'string', 'max:16'],
            'base_quantity' => ['required', 'numeric', 'gt:0', 'max:999999'], 'prices' => ['required', 'array'], 'prices.*' => ['nullable', 'numeric', 'min:0', 'max:99999999999999'],
        ]);
        $prices = collect($data['prices'])->filter(fn ($price) => (float) $price > 0);
        if ($prices->isEmpty()) {
            throw ValidationException::withMessages(['prices' => 'Isi harga jual untuk minimal satu varian.']);
        }
        $variantIds = DB::table('product_variants')->where('tenant_id', $tenant->id)->where('product_id', $id)->where('is_active', true)->pluck('id')->all();
        if ($prices->keys()->diff($variantIds)->isNotEmpty()) {
            abort(422, 'Varian tidak valid.');
        }

        DB::transaction(function () use ($tenant, $outlet, $product, $data, $prices) {
            $now = now($tenant->timezone);
            $unitId = $data['unit_id'] ?? null;
            if (! $unitId) {
                if (blank($data['new_unit_name'] ?? null) || blank($data['new_unit_symbol'] ?? null)) {
                    throw ValidationException::withMessages(['unit_id' => 'Pilih satuan atau isi nama dan simbol satuan baru.']);
                }
                if (DB::table('units')->where('tenant_id', $tenant->id)->where('symbol', $data['new_unit_symbol'])->exists()) {
                    throw ValidationException::withMessages(['new_unit_symbol' => 'Simbol satuan sudah dipakai.']);
                }
                $unitId = (string) Str::uuid();
                DB::table('units')->insert(['id' => $unitId, 'tenant_id' => $tenant->id, 'name' => $data['new_unit_name'], 'symbol' => $data['new_unit_symbol'], 'precision' => 0, 'created_at' => $now, 'updated_at' => $now]);
            }
            if ($unitId === $product->base_unit_id || ! DB::table('units')->where('tenant_id', $tenant->id)->where('id', $unitId)->exists()) {
                throw ValidationException::withMessages(['unit_id' => 'Satuan tidak valid atau sama dengan satuan dasar.']);
            }
            if (DB::table('product_unit_conversions')->where('product_id', $product->id)->where('unit_id', $unitId)->exists()) {
                throw ValidationException::withMessages(['unit_id' => 'Satuan ini sudah terdaftar untuk produk.']);
            }
            DB::table('product_unit_conversions')->insert([
                'id' => (string) Str::uuid(), 'tenant_id' => $tenant->id, 'product_id' => $product->id, 'unit_id' => $unitId, 'base_quantity' => $data['base_quantity'],
                'is_purchase_unit' => false, 'is_sell_unit' => true, 'created_at' => $now, 'updated_at' => $now,
            ]);
            $this->setUnitPrices($tenant->id, $unitId, $prices->all(), $now);
            $this->audit($tenant->id, $outlet->id, 'product.unit_added', $product->id, ['unit_id' => $unitId, 'base_quantity' => $data['base_quantity']], $now);
        });

        return back()->with('success', 'Satuan jual ditambahkan.');
    }

    public function updateUnitPrices(Request $request, string $id, string $unitId): RedirectResponse
    {
        $tenant = $request->attributes->get('tenant');
        $product = $this->product($tenant->id, $id);
        abort_unless(DB::table('product_unit_conversions')->where('product_id', $id)->where('unit_id', $unitId)->exists(), 404);
        $data = $request->validate(['prices' => ['required', 'array'], 'prices.*' => ['nullable', 'numeric', 'min:0', 'max:99999999999999']]);
        $variantIds = DB::table('product_variants')->where('tenant_id', $tenant->id)->where('product_id', $id)->where('is_active', true)->pluck('id')->all();
        $prices = collect($data['prices'])->only($variantIds)->filter(fn ($price) => $price !== null && $price !== '');
        $now = now($tenant->timezone);
        DB::transaction(function () use ($tenant, $unitId, $prices, $variantIds, $now) {
            DB::table('product_prices')->where('tenant_id', $tenant->id)->where('unit_id', $unitId)->whereIn('product_variant_id', $variantIds)->where('price_type', 'retail')->where('is_active', true)
                ->update(['is_active' => false, 'valid_until' => $now, 'updated_at' => $now]);
            $this->setUnitPrices($tenant->id, $unitId, $prices->filter(fn ($price) => (float) $price > 0)->all(), $now);
        });
        $this->audit($tenant->id, $request->attributes->get('outlet')->id, 'product.unit_prices_changed', $product->id, ['unit_id' => $unitId], $now);

        return back()->with('success', 'Harga satuan diperbarui.');
    }

    public function destroyUnit(Request $request, string $id, string $unitId): RedirectResponse
    {
        $tenant = $request->attributes->get('tenant');
        $this->product($tenant->id, $id);
        $variantIds = DB::table('product_variants')->where('tenant_id', $tenant->id)->where('product_id', $id)->pluck('id')->all();
        $now = now($tenant->timezone);
        DB::transaction(function () use ($id, $unitId, $tenant, $variantIds, $now) {
            DB::table('product_unit_conversions')->where('product_id', $id)->where('unit_id', $unitId)->delete();
            DB::table('product_prices')->where('tenant_id', $tenant->id)->where('unit_id', $unitId)->whereIn('product_variant_id', $variantIds)->where('is_active', true)->update(['is_active' => false, 'valid_until' => $now, 'updated_at' => $now]);
        });
        $this->audit($tenant->id, $request->attributes->get('outlet')->id, 'product.unit_removed', $id, ['unit_id' => $unitId], $now);

        return back()->with('success', 'Satuan jual dihapus. Transaksi lama tidak berubah.');
    }

    /**
     * @param  array<string, mixed>  $prices
     */
    private function setUnitPrices(string $tenantId, string $unitId, array $prices, $now): void
    {
        foreach ($prices as $variantId => $price) {
            DB::table('product_prices')->insert([
                'id' => (string) Str::uuid(), 'tenant_id' => $tenantId, 'product_variant_id' => $variantId, 'outlet_id' => null, 'unit_id' => $unitId, 'price_type' => 'retail',
                'price' => round((float) $price, 2), 'valid_from' => $now, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now,
            ]);
        }
    }

    private function product(string $tenantId, string $id): object
    {
        $product = DB::table('products')->where('tenant_id', $tenantId)->where('id', $id)->whereNull('deleted_at')->first();
        abort_unless($product, 404);

        return $product;
    }

    private function accountId(string $tenantId, string $code, string $name, string $type, string $normal, $now): string
    {
        $existing = DB::table('chart_of_accounts')->where('tenant_id', $tenantId)->where('code', $code)->value('id');
        if ($existing) {
            return $existing;
        }
        $id = (string) Str::uuid();
        DB::table('chart_of_accounts')->insert(['id' => $id, 'tenant_id' => $tenantId, 'code' => $code, 'name' => $name, 'account_type' => $type, 'normal_balance' => $normal, 'is_system' => true, 'allow_manual_posting' => false, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now]);

        return $id;
    }

    /**
     * @param  array<string, mixed>  $after
     */
    private function audit(string $tenantId, ?string $outletId, string $action, string $productId, array $after, $now): void
    {
        DB::table('audit_logs')->insert([
            'id' => (string) Str::uuid(), 'tenant_id' => $tenantId, 'outlet_id' => $outletId, 'actor_user_id' => auth()->id(), 'action' => $action,
            'subject_type' => 'product', 'subject_id' => $productId, 'after' => json_encode($after), 'occurred_at' => $now, 'created_at' => $now,
        ]);
    }
}
