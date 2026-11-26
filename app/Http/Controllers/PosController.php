<?php

namespace App\Http\Controllers;

use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class PosController extends Controller
{
    public function index(Request $request): View
    {
        $tenant = $request->attributes->get('tenant'); $outlet = $request->attributes->get('outlet');
        $relatedSale = null;
        $initialCustomerId = '';
        $initialNotes = '';
        if ($request->filled('add_to_sale')) {
            $relatedSale = DB::table('sales')->where('tenant_id', $tenant->id)->where('outlet_id', $outlet->id)
                ->where('id', $request->input('add_to_sale'))->where('status', 'completed')->first();
            abort_unless($relatedSale, 404);
            $initialCustomerId = $relatedSale->customer_id ?? '';
            $initialNotes = 'Pesanan tambahan dari '.$relatedSale->sale_number;
        }
        $variants = DB::table('product_variants')->join('products', 'products.id', '=', 'product_variants.product_id')
            ->join('units', 'units.id', '=', 'products.base_unit_id')
            ->leftJoin('product_categories', 'product_categories.id', '=', 'products.category_id')
            ->where('products.tenant_id', $tenant->id)->where('products.is_active', true)->where('product_variants.is_active', true)
            ->whereNull('products.deleted_at')->select('product_variants.id as variant_id', 'products.id as product_id', 'products.name', 'products.sku',
                'products.track_stock', 'products.base_unit_id', 'product_variants.name as variant_name', 'units.symbol as unit_symbol', 'product_categories.name as category_name', 'product_variants.cost_price')->orderBy('products.name')->orderBy('product_variants.created_at')->get();
        $variantIds = $variants->pluck('variant_id')->all();
        $now = now();
        $prices = DB::table('product_prices')->where('tenant_id', $tenant->id)->whereIn('product_variant_id', $variantIds)
            ->where('price_type', 'retail')->where('is_active', true)
            ->where(fn ($q) => $q->whereNull('valid_from')->orWhere('valid_from', '<=', $now))
            ->where(fn ($q) => $q->whereNull('valid_until')->orWhere('valid_until', '>=', $now))
            ->where(fn ($q) => $q->whereNull('outlet_id')->orWhere('outlet_id', $outlet->id))
            ->get()->sortByDesc(fn ($price) => $price->outlet_id === $outlet->id ? 1 : 0)->groupBy(fn ($price) => $price->product_variant_id.'|'.$price->unit_id)->map->first();
        $stocks = DB::table('stock_balances')->where('tenant_id', $tenant->id)->where('outlet_id', $outlet->id)
            ->whereIn('product_variant_id', $variantIds)->pluck('quantity_on_hand', 'product_variant_id');
        $barcodes = DB::table('product_barcodes')->where('tenant_id', $tenant->id)->whereIn('product_variant_id', $variantIds)
            ->pluck('barcode', 'product_variant_id');
        $conversions = DB::table('product_unit_conversions')->join('units', 'units.id', '=', 'product_unit_conversions.unit_id')->where('product_unit_conversions.tenant_id', $tenant->id)
            ->where('product_unit_conversions.is_sell_unit', true)->get(['product_unit_conversions.product_id', 'product_unit_conversions.unit_id', 'product_unit_conversions.base_quantity', 'units.name as unit_name', 'units.symbol'])->groupBy('product_id');
        $catalog = collect();
        foreach ($variants as $row) {
            $displayName = $row->name.($row->variant_name !== 'Default' ? ' — '.$row->variant_name : '');
            $basePrice = (float) ($prices[$row->variant_id.'|'.$row->base_unit_id]->price ?? 0);
            $stock = (float) ($stocks[$row->variant_id] ?? 0);
            $catalog->push((object) [
                'cart_key' => $row->variant_id.':', 'variant_id' => $row->variant_id, 'unit_id' => null, 'factor' => 1, 'product_id' => $row->product_id, 'name' => $displayName,
                'sku' => $row->sku, 'track_stock' => (bool) $row->track_stock, 'unit_symbol' => $row->unit_symbol, 'category_name' => $row->category_name,
                'price' => $basePrice, 'stock' => $stock, 'barcode' => $barcodes[$row->variant_id] ?? null,
            ]);
            foreach ($conversions[$row->product_id] ?? [] as $conversion) {
                $unitPrice = (float) ($prices[$row->variant_id.'|'.$conversion->unit_id]->price ?? 0);
                $factor = (float) $conversion->base_quantity;
                $catalog->push((object) [
                    'cart_key' => $row->variant_id.':'.$conversion->unit_id, 'variant_id' => $row->variant_id, 'unit_id' => $conversion->unit_id, 'factor' => $factor, 'product_id' => $row->product_id,
                    'name' => $displayName.' ('.$conversion->unit_name.')', 'sku' => $row->sku, 'track_stock' => (bool) $row->track_stock, 'unit_symbol' => $conversion->symbol, 'category_name' => $row->category_name,
                    'price' => $unitPrice, 'stock' => floor($stock / $factor * 10000) / 10000, 'barcode' => null,
                ]);
            }
        }
        $catalog = $catalog->filter(fn ($item) => $item->price > 0)->values();
        $customers = DB::table('customers')->where('tenant_id', $tenant->id)->where('is_active', true)->whereNull('deleted_at')->orderBy('name')->get();
        $shift = DB::table('cash_shifts')->where('tenant_id', $tenant->id)->where('outlet_id', $outlet->id)
            ->where('cashier_user_id', auth()->id())->where('status', 'open')->first();

        $xenditEnabled = DB::table('payment_gateway_settings')->where('tenant_id', $tenant->id)->where('provider', 'xendit')->where('is_active', true)->exists();
        $paidCharges = DB::table('payment_gateway_charges')->where('tenant_id', $tenant->id)->where('cashier_user_id', auth()->id())
            ->where('status', 'paid')->whereNull('sale_id')->orderBy('paid_at')->get(['id', 'amount', 'paid_at']);

        return view('pos.index', compact('catalog', 'customers', 'shift', 'xenditEnabled', 'paidCharges', 'relatedSale', 'initialCustomerId', 'initialNotes'));
    }

    /**
     * Gabungkan item per varian+satuan. Satu varian hanya boleh dijual dalam satu satuan per transaksi
     * agar pengecekan dan pemotongan stok tidak saling menimpa.
     *
     * @param  array<int, array<string, mixed>>  $items
     * @return array<int, array{variant_id: string, unit_id: ?string, quantity: float}>
     */
    public static function normalizeItems(array $items): array
    {
        $grouped = [];
        foreach ($items as $item) {
            $unitId = $item['unit_id'] ?? null;
            $unitId = $unitId === '' ? null : $unitId;
            $variantId = $item['variant_id'];
            if (isset($grouped[$variantId]) && $grouped[$variantId]['unit_id'] !== $unitId) {
                throw ValidationException::withMessages(['items' => 'Satu produk hanya boleh satu satuan per transaksi. Hapus salah satu baris.']);
            }
            $grouped[$variantId] = ['variant_id' => $variantId, 'unit_id' => $unitId, 'quantity' => ($grouped[$variantId]['quantity'] ?? 0) + (float) $item['quantity']];
        }

        return array_values($grouped);
    }

    public function checkout(Request $request): RedirectResponse|JsonResponse
    {
        $tenant = $request->attributes->get('tenant'); $outlet = $request->attributes->get('outlet');
        $data = $request->validate([
            'customer_id' => ['nullable', 'uuid'], 'discount' => ['nullable', 'numeric', 'min:0'],
            'cash_received' => ['nullable', 'numeric', 'min:0'], 'notes' => ['nullable', 'string', 'max:1000'],
            'payment_proof' => ['nullable', 'file', 'mimes:jpg,jpeg,png,pdf', 'max:5120'],
            'items' => ['required', 'array', 'min:1', 'max:100'], 'items.*.variant_id' => ['required', 'uuid'],
            'items.*.quantity' => ['required', 'numeric', 'gt:0', 'max:999999'], 'items.*.unit_id' => ['nullable', 'uuid'],
            'payments' => ['required', 'array', 'min:1', 'max:4'],
            'payments.*.method' => ['required', 'in:cash,bank_transfer,qris_external,credit'],
            'payments.*.amount' => ['required', 'numeric', 'gt:0'],
        ]);
        $data['items'] = self::normalizeItems($data['items']);

        if (! empty($data['customer_id'])) abort_unless(DB::table('customers')->where('tenant_id', $tenant->id)->where('id', $data['customer_id'])->where('is_active', true)->exists(), 404);
        if (collect($data['payments'])->contains(fn ($payment) => $payment['method'] === 'credit') && empty($data['customer_id'])) {
            throw ValidationException::withMessages(['customer_id' => 'Pilih pelanggan untuk transaksi kasbon.']);
        }
        $cashApplied = collect($data['payments'])->where('method', 'cash')->sum(fn ($payment) => (float) $payment['amount']);
        if ($cashApplied > 0 && (float) ($data['cash_received'] ?? 0) < $cashApplied) {
            throw ValidationException::withMessages(['cash_received' => 'Uang diterima harus sama atau lebih besar dari pembayaran tunai.']);
        }

        if ($request->hasFile('payment_proof')) {
            $hasManualTransfer = collect($data['payments'])->contains(fn ($payment) => in_array($payment['method'], ['bank_transfer', 'qris_external'], true));
            if (! $hasManualTransfer) {
                throw ValidationException::withMessages(['payment_proof' => 'Lampiran bukti hanya digunakan untuk transfer bank atau QRIS manual.']);
            }
            $extension = $request->file('payment_proof')->extension();
            $data['payment_proof_path'] = $request->file('payment_proof')->storeAs(
                'payment-proofs/'.$tenant->id,
                (string) Str::uuid().'.'.$extension,
                'local',
            );
        }

        try {
            $saleId = $this->processCheckout($tenant, $outlet, $data, $cashApplied);
        } catch (\Throwable $exception) {
            if (! empty($data['payment_proof_path'])) Storage::disk('local')->delete($data['payment_proof_path']);
            throw $exception;
        }

        if ($request->expectsJson()) {
            return response()->json(['receipt' => $this->receiptPayload($tenant, $outlet, $saleId)], 201);
        }

        if ($request->boolean('print_receipt')) {
            return redirect()->route('documents.thermal', ['id' => $saleId, 'auto' => 1]);
        }

        return redirect()->route('sales.show', $saleId)->with('success', 'Transaksi berhasil disimpan.');
    }

    public function receiptPayload(object $tenant, object $outlet, string $saleId): array
    {
        $sale = DB::table('sales')->where('tenant_id', $tenant->id)->where('id', $saleId)->first();
        abort_unless($sale, 404);
        $customerName = ! empty($sale->customer_id)
            ? DB::table('customers')->where('tenant_id', $tenant->id)->where('id', $sale->customer_id)->value('name')
            : null;
        $items = DB::table('sale_items')->leftJoin('units', 'units.id', '=', 'sale_items.unit_id')
            ->where('sale_items.tenant_id', $tenant->id)->where('sale_items.sale_id', $saleId)->orderBy('sale_items.created_at')
            ->get(['sale_items.name_snapshot', 'sale_items.variant_snapshot', 'sale_items.quantity', 'sale_items.unit_price', 'sale_items.line_subtotal', 'sale_items.line_total', 'units.symbol as unit_symbol'])
            ->map(fn ($item) => [
                'name' => $item->name_snapshot, 'variant' => $item->variant_snapshot, 'quantity' => (float) $item->quantity,
                'unit' => $item->unit_symbol, 'unit_price' => (float) $item->unit_price,
                'line_subtotal' => (float) $item->line_subtotal, 'line_total' => (float) $item->line_total,
            ])->all();
        $receiptPayments = DB::table('payment_allocations')->join('payments', 'payments.id', '=', 'payment_allocations.payment_id')
            ->where('payment_allocations.tenant_id', $tenant->id)->where('payment_allocations.sale_id', $saleId)
            ->orderBy('payments.created_at')->get(['payments.method', 'payments.status', 'payment_allocations.amount'])
            ->map(fn ($payment) => ['method' => $payment->method, 'status' => $payment->status, 'amount' => (float) $payment->amount])->all();
        $settings = json_decode((string) $tenant->settings, true) ?: [];

        return [
            'tenant_name' => $tenant->legal_name ?: $tenant->name,
            'outlet_name' => $outlet->name,
            'outlet_address' => $outlet->address,
            'outlet_phone' => $outlet->phone ?: $tenant->phone,
            'sale_number' => $sale->sale_number,
            'sold_at' => now($tenant->timezone)->format('d/m/Y H:i'),
            'cashier' => auth()->user()->name,
            'customer_name' => $customerName,
            'items' => $items,
            'payments' => $receiptPayments,
            'subtotal' => (float) $sale->subtotal,
            'discount' => (float) $sale->discount_total,
            'total' => (float) $sale->grand_total,
            'change' => (float) $sale->change_total,
            'credit' => max(0, (float) $sale->grand_total - (float) $sale->paid_total),
            'footer' => $settings['document_footer'] ?? 'Terima kasih',
        ];
    }

    /**
     * Run the whole checkout inside one transaction and return the new sale id.
     *
     * @param  array<string, mixed>  $data
     */
    public function processCheckout(object $tenant, object $outlet, array $data, float $cashApplied, ?string $chargeId = null, bool $allowPriceOverride = false): string
    {
        try {
            $saleId = DB::transaction(function () use ($data, $tenant, $outlet, $cashApplied, $chargeId, $allowPriceOverride) {
                $shift = DB::table('cash_shifts')->where('tenant_id', $tenant->id)->where('outlet_id', $outlet->id)
                    ->where('cashier_user_id', auth()->id())->where('status', 'open')->lockForUpdate()->first();
                if (! $shift) throw ValidationException::withMessages(['shift' => 'Buka shift sebelum memulai transaksi.']);

                $charge = null;
                if ($chargeId) {
                    $charge = DB::table('payment_gateway_charges')->where('tenant_id', $tenant->id)->where('id', $chargeId)->lockForUpdate()->first();
                    if (! $charge || $charge->status !== 'paid' || $charge->sale_id || $charge->cashier_user_id !== auth()->id()) {
                        throw ValidationException::withMessages(['payments' => 'Pembayaran QRIS tidak valid atau sudah dipakai.']);
                    }
                    $qrisTender = collect($data['payments'])->where('method', 'qris_external')->sum(fn ($payment) => (float) $payment['amount']);
                    if (abs($qrisTender - (float) $charge->amount) > 0.004) {
                        throw ValidationException::withMessages(['payments' => 'Nominal QRIS tidak sama dengan pembayaran yang masuk.']);
                    }
                }

                $lineRows = []; $subtotal = 0.0;
                foreach ($data['items'] as $item) {
                    $row = DB::table('product_variants')->join('products', 'products.id', '=', 'product_variants.product_id')
                        ->join('units', 'units.id', '=', 'products.base_unit_id')
                        ->where('product_variants.id', $item['variant_id'])->where('product_variants.tenant_id', $tenant->id)
                        ->where('product_variants.is_active', true)->where('products.is_active', true)->whereNull('products.deleted_at')
                        ->select('product_variants.*', 'products.id as product_id', 'products.name as product_name', 'products.sku as product_sku',
                            'products.track_stock', 'products.allow_negative_stock', 'units.id as unit_id', 'units.symbol as unit_symbol')->first();
                    if (! $row) throw ValidationException::withMessages(['items' => 'Ada produk yang sudah tidak aktif. Muat ulang kasir.']);
                    $sellUnitId = $item['unit_id'] ?? $row->unit_id;
                    $factor = 1.0; $unitSymbol = $row->unit_symbol;
                    if ($sellUnitId !== $row->unit_id) {
                        $conversion = DB::table('product_unit_conversions')->join('units', 'units.id', '=', 'product_unit_conversions.unit_id')->where('product_unit_conversions.tenant_id', $tenant->id)
                            ->where('product_unit_conversions.product_id', $row->product_id)->where('product_unit_conversions.unit_id', $sellUnitId)->where('product_unit_conversions.is_sell_unit', true)
                            ->first(['product_unit_conversions.base_quantity', 'units.symbol']);
                        if (! $conversion) throw ValidationException::withMessages(['items' => 'Satuan jual '.$row->product_name.' tidak valid. Muat ulang kasir.']);
                        $factor = (float) $conversion->base_quantity; $unitSymbol = $conversion->symbol;
                    }
                    $price = DB::table('product_prices')->where('tenant_id', $tenant->id)->where('product_variant_id', $row->id)->where('unit_id', $sellUnitId)->where('price_type', 'retail')->where('is_active', true)
                        ->where(fn ($q) => $q->whereNull('valid_from')->orWhere('valid_from', '<=', now()))
                        ->where(fn ($q) => $q->whereNull('valid_until')->orWhere('valid_until', '>=', now()))
                        ->where(fn ($q) => $q->whereNull('outlet_id')->orWhere('outlet_id', $outlet->id))
                        ->orderByRaw('outlet_id = ? desc', [$outlet->id])->first();
                    if (! $price) throw ValidationException::withMessages(['items' => 'Harga produk '.$row->product_name.' belum tersedia.']);
                    $balance = DB::table('stock_balances')->where('tenant_id', $tenant->id)->where('outlet_id', $outlet->id)
                        ->where('product_variant_id', $row->id)->lockForUpdate()->first();
                    $soldQuantity = (float) $item['quantity'];
                    $quantity = round($soldQuantity * $factor, 4);
                    if ($row->track_stock && ! $row->allow_negative_stock && (float) ($balance->quantity_on_hand ?? 0) < $quantity) {
                        throw ValidationException::withMessages(['items' => 'Stok '.$row->product_name.' tidak cukup. Tersedia '.number_format((float) ($balance->quantity_on_hand ?? 0), 2, ',', '.').'.']);
                    }
                    $unitCost = $row->track_stock && $balance ? (float) $balance->average_cost : (float) ($row->cost_price ?? 0);
                    $unitPrice = $allowPriceOverride && array_key_exists('unit_price', $item)
                        ? round((float) $item['unit_price'], 2)
                        : (float) $price->price;
                    $lineTotal = round($soldQuantity * $unitPrice, 2);
                    $subtotal += $lineTotal;
                    $lineNote = $factor != 1.0 ? rtrim(rtrim(number_format($soldQuantity, 4, ',', '.'), '0'), ',').' '.$unitSymbol.' @ Rp '.number_format($unitPrice, 0, ',', '.').' (1 '.$unitSymbol.' = '.rtrim(rtrim(number_format($factor, 4, ',', '.'), '0'), ',').' '.$row->unit_symbol.')' : null;
                    $lineRows[] = ['row' => $row, 'quantity' => $quantity, 'note' => $lineNote, 'price' => $factor != 1.0 ? round($unitPrice / $factor, 2) : $unitPrice,
                        'unit_cost' => $unitCost, 'line_total' => $lineTotal, 'balance' => $balance];
                }
                $subtotal = round($subtotal, 2);
                $discount = round((float) ($data['discount'] ?? 0), 2);
                if ($discount > $subtotal) throw ValidationException::withMessages(['discount' => 'Diskon tidak boleh melebihi subtotal.']);
                $total = round($subtotal - $discount, 2);
                $tenderTotal = round(collect($data['payments'])->sum(fn ($payment) => (float) $payment['amount']), 2);
                if (abs($tenderTotal - $total) > 0.004) throw ValidationException::withMessages(['payments' => 'Jumlah metode bayar harus sama dengan total transaksi.']);
                $creditAmount = round(collect($data['payments'])->where('method', 'credit')->sum(fn ($payment) => (float) $payment['amount']), 2);
                if ($creditAmount > 0) {
                    $customer = DB::table('customers')->where('tenant_id', $tenant->id)->where('id', $data['customer_id'])->lockForUpdate()->first();
                    if ($customer->credit_limit !== null) {
                        $outstanding = \App\Http\Controllers\CustomerController::outstandingFor($tenant->id, $customer->id);
                        if ($outstanding + $creditAmount > (float) $customer->credit_limit + 0.004) {
                            $room = max(0, (float) $customer->credit_limit - $outstanding);
                            throw ValidationException::withMessages(['customer_id' => 'Melebihi batas kredit '.$customer->name.'. Sisa batas Rp '.number_format($room, 0, ',', '.').'.']);
                        }
                    }
                }
                $cashReceived = round((float) ($data['cash_received'] ?? 0), 2);
                if ($cashApplied > 0 && $cashReceived < $cashApplied) throw ValidationException::withMessages(['cash_received' => 'Uang tunai belum cukup.']);

                $saleNumber = $this->nextNumber($tenant->id, $outlet->id, 'sale');
                $saleId = (string) Str::uuid(); $now = now($tenant->timezone);
                $paidTotal = collect($data['payments'])->reject(fn ($payment) => $payment['method'] === 'credit')->sum(fn ($payment) => (float) $payment['amount']);
                DB::table('sales')->insert([
                    'id' => $saleId, 'tenant_id' => $tenant->id, 'outlet_id' => $outlet->id, 'cash_shift_id' => $shift->id,
                    'customer_id' => $data['customer_id'] ?? null, 'cashier_user_id' => auth()->id(), 'sale_number' => $saleNumber,
                    'client_transaction_id' => (string) Str::uuid(), 'status' => 'completed', 'channel' => 'pos', 'sold_at' => $now,
                    'currency' => $tenant->currency, 'subtotal' => $subtotal, 'discount_total' => $discount, 'fee_total' => 0,
                    'tax_total' => 0, 'grand_total' => $total, 'paid_total' => $paidTotal,
                    'change_total' => max(0, $cashReceived - $cashApplied), 'notes' => $data['notes'] ?? null,
                    'completed_at' => $now, 'created_at' => $now, 'updated_at' => $now,
                ]);
                if ($discount > 0) DB::table('sale_adjustments')->insert([
                    'id' => (string) Str::uuid(), 'tenant_id' => $tenant->id, 'sale_id' => $saleId, 'type' => 'discount',
                    'label' => 'Diskon transaksi', 'calculation_method' => 'fixed', 'amount' => $discount, 'created_at' => $now, 'updated_at' => $now,
                ]);

                if ($charge) {
                    DB::table('payment_gateway_charges')->where('id', $charge->id)->update(['status' => 'used', 'sale_id' => $saleId, 'updated_at' => $now]);
                }

                $cogs = 0.0;
                $allocatedDiscount = 0.0;
                $lastLineIndex = count($lineRows) - 1;
                foreach ($lineRows as $lineIndex => $line) {
                    $row = $line['row']; $qty = $line['quantity']; $cost = $line['unit_cost'];
                    $lineDiscount = $lineIndex === $lastLineIndex
                        ? round($discount - $allocatedDiscount, 2)
                        : round($discount * $line['line_total'] / max($subtotal, 0.01), 2);
                    $allocatedDiscount += $lineDiscount;
                    $netLineTotal = round($line['line_total'] - $lineDiscount, 2);
                    $saleItemId = (string) Str::uuid();
                    DB::table('sale_items')->insert([
                        'id' => $saleItemId, 'tenant_id' => $tenant->id, 'sale_id' => $saleId,
                        'product_id' => $row->product_id, 'product_variant_id' => $row->id, 'unit_id' => $row->unit_id,
                        'sku_snapshot' => $row->product_sku, 'name_snapshot' => $row->product_name, 'variant_snapshot' => $row->name,
                        'quantity' => $qty, 'base_quantity' => $qty, 'unit_price' => $line['price'], 'discount_amount' => $lineDiscount,
                        'tax_amount' => 0, 'unit_cost_snapshot' => $cost, 'line_subtotal' => $line['line_total'], 'note' => $line['note'] ?? null,
                        'line_total' => $netLineTotal, 'line_type' => 'product', 'created_at' => $now, 'updated_at' => $now,
                    ]);
                    if ($row->track_stock) {
                        $balance = $line['balance'];
                        $newQuantity = (float) ($balance->quantity_on_hand ?? 0) - $qty;
                        $valueDelta = round($cost * $qty, 2);
                        DB::table('stock_movements')->insert([
                            'id' => (string) Str::uuid(), 'tenant_id' => $tenant->id, 'outlet_id' => $outlet->id,
                            'product_variant_id' => $row->id, 'occurred_at' => $now, 'movement_type' => 'sale',
                            'quantity_delta' => -$qty, 'unit_cost' => $cost, 'value_delta' => -$valueDelta,
                            'source_type' => 'sale', 'source_id' => $saleId, 'source_line_id' => $saleItemId,
                            'idempotency_key' => 'sale:'.$saleId.':'.$row->id, 'created_by_user_id' => auth()->id(),
                            'device_id' => null, 'created_at' => $now, 'updated_at' => $now,
                        ]);
                        DB::table('stock_balances')->where('tenant_id', $tenant->id)->where('outlet_id', $outlet->id)
                            ->where('product_variant_id', $row->id)->update([
                                'quantity_on_hand' => $newQuantity, 'inventory_value' => round((float) ($balance->inventory_value ?? 0) - $valueDelta, 2),
                                'version' => (int) ($balance->version ?? 0) + 1, 'updated_at' => $now,
                            ]);
                        $cogs += $valueDelta;
                    }
                }

                $debits = [];
                $payments = [];
                foreach ($data['payments'] as $i => $tender) {
                    $method = $tender['method']; $amount = round((float) $tender['amount'], 2);
                    $cashAccount = $method === 'credit' ? null : DB::table('cash_accounts')->where('tenant_id', $tenant->id)
                        ->where('outlet_id', $outlet->id)->where('type', match ($method) {
                            'cash' => 'cash', 'bank_transfer' => 'bank', 'qris_external' => 'qris_clearing',
                        })->where('is_active', true)->first();
                    if ($method !== 'credit' && ! $cashAccount) throw ValidationException::withMessages(['payments' => 'Akun metode bayar belum disiapkan.']);
                    $paymentId = (string) Str::uuid();
                    DB::table('payments')->insert([
                        'id' => $paymentId, 'tenant_id' => $tenant->id, 'outlet_id' => $outlet->id, 'cash_shift_id' => $shift->id,
                        'customer_id' => $data['customer_id'] ?? null, 'cash_account_id' => $cashAccount->id ?? null,
                        'confirmed_by_user_id' => $method === 'credit' ? null : auth()->id(),
                        'payment_number' => 'PAY-'.$saleNumber.'-'.str_pad((string) ($i + 1), 2, '0', STR_PAD_LEFT),
                        'method' => $method, 'status' => $method === 'credit' ? 'pending' : 'confirmed', 'amount' => $amount,
                        'received_at' => $method === 'credit' ? null : $now, 'confirmed_at' => $method === 'credit' ? null : $now,
                        'idempotency_key' => 'checkout:'.$saleId.':'.$i, 'created_at' => $now, 'updated_at' => $now,
                        'metadata' => ! empty($data['payment_proof_path']) && in_array($method, ['bank_transfer', 'qris_external'], true)
                            ? json_encode(['proof_path' => $data['payment_proof_path']]) : null,
                    ]);
                    DB::table('payment_allocations')->insert([
                        'id' => (string) Str::uuid(), 'tenant_id' => $tenant->id, 'payment_id' => $paymentId,
                        'sale_id' => $saleId, 'amount' => $amount, 'created_at' => $now, 'updated_at' => $now,
                    ]);
                    if ($method === 'credit') {
                        $receivable = DB::table('chart_of_accounts')->where('tenant_id', $tenant->id)->where('code', '1200')->first();
                        $debits[$receivable->id] = ($debits[$receivable->id] ?? 0) + $amount;
                    } else {
                        $debits[$cashAccount->chart_account_id] = ($debits[$cashAccount->chart_account_id] ?? 0) + $amount;
                    }
                }

                $entryId = (string) Str::uuid();
                $entryNumber = 'JRN-'.$saleNumber;
                DB::table('journal_entries')->insert([
                    'id' => $entryId, 'tenant_id' => $tenant->id, 'outlet_id' => $outlet->id,
                    'entry_number' => $entryNumber, 'entry_date' => $now->toDateString(), 'status' => 'posted',
                    'description' => 'Penjualan '.$saleNumber, 'source_type' => 'sale', 'source_id' => $saleId,
                    'idempotency_key' => 'sale:'.$saleId.':journal', 'posted_at' => $now, 'posted_by_user_id' => auth()->id(),
                    'created_by_user_id' => auth()->id(), 'created_at' => $now, 'updated_at' => $now,
                ]);
                foreach ($debits as $accountId => $amount) $this->journalLine($tenant->id, $entryId, $accountId, $amount, 0, 'Penerimaan '.$saleNumber, $now);
                $salesAccount = DB::table('chart_of_accounts')->where('tenant_id', $tenant->id)->where('code', '4010')->first();
                $this->journalLine($tenant->id, $entryId, $salesAccount->id, 0, $total, 'Penjualan '.$saleNumber, $now);
                if ($cogs > 0) {
                    $cogsAccount = DB::table('chart_of_accounts')->where('tenant_id', $tenant->id)->where('code', '5010')->first();
                    $inventoryAccount = DB::table('chart_of_accounts')->where('tenant_id', $tenant->id)->where('code', '1100')->first();
                    $this->journalLine($tenant->id, $entryId, $cogsAccount->id, $cogs, 0, 'HPP '.$saleNumber, $now);
                    $this->journalLine($tenant->id, $entryId, $inventoryAccount->id, 0, $cogs, 'Persediaan '.$saleNumber, $now);
                }

                DB::table('audit_logs')->insert([
                    'id' => (string) Str::uuid(), 'tenant_id' => $tenant->id, 'outlet_id' => $outlet->id,
                    'actor_user_id' => auth()->id(), 'action' => 'sale.completed', 'subject_type' => 'sale',
                    'subject_id' => $saleId, 'after' => json_encode(['sale_number' => $saleNumber, 'total' => $total]),
                    'occurred_at' => $now,
                ]);

                DB::table('outbox_messages')->insert([
                    'id' => (string) Str::uuid(), 'tenant_id' => $tenant->id, 'aggregate_type' => 'sale', 'aggregate_id' => $saleId,
                    'event_type' => 'sale.completed', 'payload' => json_encode(['sale_id' => $saleId, 'sale_number' => $saleNumber]),
                    'occurred_at' => $now, 'attempts' => 0, 'created_at' => $now,
                ]);

                return $saleId;
            }, attempts: 3);
        } catch (QueryException $exception) {
            report($exception);
            throw $exception;
        }

        return $saleId;
    }

    private function nextNumber(string $tenantId, string $outletId, string $type): string
    {
        $period = now()->format('Y');
        $scope = ['tenant_id' => $tenantId, 'outlet_id' => $outletId, 'document_type' => $type, 'period_key' => $period];
        $counter = DB::table('document_counters')->where($scope)->lockForUpdate()->first();
        if (! $counter) {
            $previous = DB::table('document_counters')->where('tenant_id', $tenantId)->where('outlet_id', $outletId)
                ->where('document_type', $type)->orderByDesc('period_key')->first();
            DB::table('document_counters')->insertOrIgnore($scope + [
                'id' => (string) Str::uuid(), 'prefix' => $previous->prefix ?? strtoupper(substr($type, 0, 3)),
                'next_number' => 1, 'padding' => $previous->padding ?? 5, 'created_at' => now(), 'updated_at' => now(),
            ]);
            $counter = DB::table('document_counters')->where($scope)->lockForUpdate()->first();
        }
        DB::table('document_counters')->where('id', $counter->id)->update(['next_number' => $counter->next_number + 1, 'updated_at' => now()]);
        return ($counter->prefix ?: strtoupper(substr($type, 0, 3))).'/'.$period.'/'.str_pad((string) $counter->next_number, $counter->padding, '0', STR_PAD_LEFT);
    }

    private function journalLine(string $tenantId, string $entryId, string $accountId, float $debit, float $credit, string $description, $now): void
    {
        DB::table('journal_lines')->insert([
            'id' => (string) Str::uuid(), 'tenant_id' => $tenantId, 'journal_entry_id' => $entryId,
            'account_id' => $accountId, 'description' => $description, 'debit' => round($debit, 2),
            'credit' => round($credit, 2), 'created_at' => $now, 'updated_at' => $now,
        ]);
    }
}
