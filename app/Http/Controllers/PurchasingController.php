<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class PurchasingController extends Controller
{
    public function suppliers(Request $request): View
    {
        $tenant = $request->attributes->get('tenant');
        $suppliers = DB::table('suppliers')->leftJoin('supplier_bills', function ($join) {
            $join->on('supplier_bills.supplier_id', '=', 'suppliers.id')->whereIn('supplier_bills.status', ['open', 'partial']);
        })->where('suppliers.tenant_id', $tenant->id)->whereNull('suppliers.deleted_at')
            ->groupBy('suppliers.id', 'suppliers.name', 'suppliers.contact_name', 'suppliers.phone', 'suppliers.payment_terms_days')
            ->orderBy('suppliers.name')
            ->select('suppliers.id', 'suppliers.name', 'suppliers.contact_name', 'suppliers.phone', 'suppliers.payment_terms_days',
                DB::raw('COALESCE(SUM(supplier_bills.balance_due), 0) as payable'))
            ->paginate(20);

        return view('purchasing.suppliers', compact('suppliers'));
    }

    public function storeSupplier(Request $request): RedirectResponse
    {
        $tenant = $request->attributes->get('tenant');
        $data = $request->validate([
            'name' => ['required', 'string', 'max:160'], 'contact_name' => ['nullable', 'string', 'max:160'],
            'phone' => ['nullable', 'string', 'max:32'], 'email' => ['nullable', 'email', 'max:190'],
            'address' => ['nullable', 'string', 'max:1000'], 'payment_terms_days' => ['nullable', 'integer', 'min:0', 'max:365'],
        ]);
        DB::table('suppliers')->insert([
            'id' => (string) Str::uuid(), 'tenant_id' => $tenant->id, 'name' => $data['name'],
            'contact_name' => $data['contact_name'] ?? null, 'phone' => $data['phone'] ?? null, 'email' => $data['email'] ?? null,
            'address' => $data['address'] ?? null, 'payment_terms_days' => $data['payment_terms_days'] ?? null,
            'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);

        return back()->with('success', 'Supplier berhasil ditambahkan.');
    }

    public function index(Request $request): View
    {
        $tenant = $request->attributes->get('tenant');
        $outlet = $request->attributes->get('outlet');
        $orders = DB::table('purchase_orders')->join('suppliers', 'suppliers.id', '=', 'purchase_orders.supplier_id')
            ->where('purchase_orders.tenant_id', $tenant->id)->where('purchase_orders.outlet_id', $outlet->id)
            ->orderByDesc('purchase_orders.created_at')->select('purchase_orders.*', 'suppliers.name as supplier_name')->paginate(15);
        $payable = (float) DB::table('supplier_bills')->where('tenant_id', $tenant->id)->where('outlet_id', $outlet->id)
            ->whereIn('status', ['open', 'partial'])->sum('balance_due');

        return view('purchasing.orders', compact('orders', 'payable'));
    }

    public function aging(Request $request): View
    {
        $tenant = $request->attributes->get('tenant');
        $outlet = $request->attributes->get('outlet');
        $today = Carbon::now($tenant->timezone)->startOfDay();
        $bills = DB::table('supplier_bills')->join('suppliers', 'suppliers.id', '=', 'supplier_bills.supplier_id')
            ->where('supplier_bills.tenant_id', $tenant->id)->where('supplier_bills.outlet_id', $outlet->id)
            ->whereIn('supplier_bills.status', ['open', 'partial'])->where('supplier_bills.balance_due', '>', 0)
            ->orderBy('supplier_bills.due_at')->get(['supplier_bills.*', 'suppliers.name as supplier_name']);

        $buckets = ['current' => 'Belum jatuh tempo', 'd30' => '1–30 hari', 'd60' => '31–60 hari', 'd90' => '61–90 hari', 'over' => '> 90 hari'];
        $bucketOf = function (int $daysLate): string {
            return match (true) {
                $daysLate <= 0 => 'current', $daysLate <= 30 => 'd30', $daysLate <= 60 => 'd60', $daysLate <= 90 => 'd90', default => 'over',
            };
        };
        $suppliers = [];
        $totals = array_fill_keys(array_keys($buckets), 0.0);
        foreach ($bills as $bill) {
            $due = $bill->due_at ? Carbon::parse($bill->due_at)->startOfDay() : $today;
            $daysLate = $due->lt($today) ? (int) $due->diffInDays($today) : 0;
            $bill->days_late = $daysLate;
            $key = $bucketOf($daysLate);
            $row = $suppliers[$bill->supplier_id] ?? ['name' => $bill->supplier_name, 'buckets' => array_fill_keys(array_keys($buckets), 0.0), 'total' => 0.0];
            $row['buckets'][$key] += (float) $bill->balance_due;
            $row['total'] += (float) $bill->balance_due;
            $suppliers[$bill->supplier_id] = $row;
            $totals[$key] += (float) $bill->balance_due;
        }
        uasort($suppliers, fn ($a, $b) => $b['total'] <=> $a['total']);

        return view('purchasing.aging', ['buckets' => $buckets, 'suppliers' => $suppliers, 'totals' => $totals, 'bills' => $bills, 'grandTotal' => array_sum($totals)]);
    }

    public function create(Request $request): View
    {
        $tenant = $request->attributes->get('tenant');
        $suppliers = DB::table('suppliers')->where('tenant_id', $tenant->id)->where('is_active', true)->whereNull('deleted_at')->orderBy('name')->get();
        $variants = DB::table('product_variants')->join('products', 'products.id', '=', 'product_variants.product_id')
            ->where('products.tenant_id', $tenant->id)->where('products.is_active', true)->where('products.track_stock', true)->whereNull('products.deleted_at')
            ->orderBy('products.name')->get(['product_variants.id', 'products.name', 'products.sku', 'product_variants.cost_price']);

        return view('purchasing.create', compact('suppliers', 'variants'));
    }

    public function store(Request $request): RedirectResponse
    {
        $tenant = $request->attributes->get('tenant');
        $outlet = $request->attributes->get('outlet');
        $data = $request->validate([
            'supplier_id' => ['required', 'uuid'], 'needed_at' => ['nullable', 'date'],
            'items' => ['required', 'array', 'min:1', 'max:100'], 'items.*.variant_id' => ['required', 'uuid'],
            'items.*.quantity' => ['required', 'numeric', 'gt:0', 'max:999999'],
            'items.*.unit_cost' => ['required', 'numeric', 'min:0', 'max:99999999999'],
        ]);
        abort_unless(DB::table('suppliers')->where('tenant_id', $tenant->id)->where('id', $data['supplier_id'])->exists(), 404);

        $orderId = DB::transaction(function () use ($tenant, $outlet, $data) {
            $now = now($tenant->timezone);
            $orderId = (string) Str::uuid();
            $lines = [];
            $subtotal = 0.0;
            foreach ($data['items'] as $row) {
                $variant = DB::table('product_variants')->join('products', 'products.id', '=', 'product_variants.product_id')
                    ->where('products.tenant_id', $tenant->id)->where('product_variants.id', $row['variant_id'])
                    ->first(['product_variants.id', 'product_variants.sku', 'products.name', 'products.base_unit_id']);
                if (! $variant) {
                    throw ValidationException::withMessages(['items' => 'Ada produk yang tidak ditemukan di usaha ini.']);
                }
                $lineTotal = round((float) $row['quantity'] * (float) $row['unit_cost'], 2);
                $subtotal += $lineTotal;
                $lines[] = [
                    'id' => (string) Str::uuid(), 'tenant_id' => $tenant->id, 'purchase_order_id' => $orderId,
                    'product_variant_id' => $variant->id, 'sku_snapshot' => $variant->sku, 'name_snapshot' => $variant->name,
                    'ordered_quantity' => $row['quantity'], 'unit_id' => $variant->base_unit_id, 'unit_cost' => $row['unit_cost'],
                    'received_quantity' => 0, 'line_total' => $lineTotal, 'created_at' => $now, 'updated_at' => $now,
                ];
            }
            DB::table('purchase_orders')->insert([
                'id' => $orderId, 'tenant_id' => $tenant->id, 'outlet_id' => $outlet->id, 'supplier_id' => $data['supplier_id'],
                'po_number' => $this->nextNumber($tenant->id, $outlet->id, 'purchase_order', 'PO', $now),
                'status' => 'draft', 'needed_at' => $data['needed_at'] ?? null, 'subtotal' => $subtotal, 'discount_total' => 0,
                'total' => $subtotal, 'created_by_user_id' => auth()->id(), 'created_at' => $now, 'updated_at' => $now,
            ]);
            DB::table('purchase_order_items')->insert($lines);
            $this->audit($tenant->id, $outlet->id, 'purchase_order.created', $orderId, ['total' => $subtotal], $now);

            return $orderId;
        }, attempts: 3);

        return redirect()->route('purchasing.show', $orderId)->with('success', 'Pesanan beli disimpan sebagai draf.');
    }

    public function show(Request $request, string $id): View
    {
        $tenant = $request->attributes->get('tenant');
        $outlet = $request->attributes->get('outlet');
        $order = DB::table('purchase_orders')->join('suppliers', 'suppliers.id', '=', 'purchase_orders.supplier_id')
            ->where('purchase_orders.tenant_id', $tenant->id)->where('purchase_orders.outlet_id', $outlet->id)->where('purchase_orders.id', $id)
            ->select('purchase_orders.*', 'suppliers.name as supplier_name', 'suppliers.payment_terms_days')->first();
        abort_unless($order, 404);
        $items = DB::table('purchase_order_items')->where('purchase_order_id', $id)->orderBy('created_at')->get();
        $bills = DB::table('supplier_bills')->where('tenant_id', $tenant->id)->where('purchase_order_id', $id)->orderBy('billed_at')->get();

        return view('purchasing.show', compact('order', 'items', 'bills'));
    }

    public function approve(Request $request, string $id): RedirectResponse
    {
        $tenant = $request->attributes->get('tenant');
        $outlet = $request->attributes->get('outlet');
        $now = now($tenant->timezone);
        $updated = DB::table('purchase_orders')->where('tenant_id', $tenant->id)->where('outlet_id', $outlet->id)
            ->where('id', $id)->where('status', 'draft')->update([
                'status' => 'ordered', 'ordered_at' => $now, 'sent_at' => $now, 'approved_by_user_id' => auth()->id(), 'updated_at' => $now,
            ]);
        abort_unless($updated, 422, 'Hanya pesanan berstatus draf yang bisa disetujui.');
        $this->audit($tenant->id, $outlet->id, 'purchase_order.approved', $id, ['status' => 'ordered'], $now);

        return back()->with('success', 'Pesanan beli disetujui dan siap diterima barangnya.');
    }

    public function cancel(Request $request, string $id): RedirectResponse
    {
        $tenant = $request->attributes->get('tenant');
        $outlet = $request->attributes->get('outlet');
        $data = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:500']]);
        $now = now($tenant->timezone);
        $order = DB::table('purchase_orders')->where('tenant_id', $tenant->id)->where('outlet_id', $outlet->id)->where('id', $id)->first();
        abort_unless($order && in_array($order->status, ['draft', 'ordered'], true), 422, 'Pesanan ini tidak bisa dibatalkan.');
        DB::table('purchase_orders')->where('id', $id)->update(['status' => 'cancelled', 'cancel_reason' => $data['reason'], 'updated_at' => $now]);
        $this->audit($tenant->id, $outlet->id, 'purchase_order.cancelled', $id, ['reason' => $data['reason']], $now);

        return back()->with('success', 'Pesanan beli dibatalkan.');
    }

    public function receive(Request $request, string $id): RedirectResponse
    {
        $tenant = $request->attributes->get('tenant');
        $outlet = $request->attributes->get('outlet');
        $data = $request->validate([
            'quantities' => ['required', 'array'], 'quantities.*' => ['nullable', 'numeric', 'min:0', 'max:999999'],
            'supplier_invoice_number' => ['nullable', 'string', 'max:128'],
        ]);

        DB::transaction(function () use ($tenant, $outlet, $id, $data) {
            $now = now($tenant->timezone);
            $order = DB::table('purchase_orders')->where('tenant_id', $tenant->id)->where('outlet_id', $outlet->id)->where('id', $id)->lockForUpdate()->first();
            abort_unless($order && in_array($order->status, ['ordered', 'partially_received'], true), 422, 'Pesanan ini belum bisa menerima barang.');
            $supplier = DB::table('suppliers')->where('id', $order->supplier_id)->first();
            $items = DB::table('purchase_order_items')->where('purchase_order_id', $id)->lockForUpdate()->get()->keyBy('id');

            $receiveLines = [];
            foreach ($data['quantities'] as $itemId => $quantity) {
                $quantity = round((float) $quantity, 4);
                if ($quantity <= 0) {
                    continue;
                }
                $item = $items->get($itemId);
                if (! $item) {
                    throw ValidationException::withMessages(['quantities' => 'Ada barang yang bukan bagian dari pesanan ini.']);
                }
                $remaining = round((float) $item->ordered_quantity - (float) $item->received_quantity, 4);
                if ($quantity > $remaining) {
                    throw ValidationException::withMessages(['quantities' => $item->name_snapshot.': jumlah melebihi sisa pesanan ('.rtrim(rtrim(number_format($remaining, 4, '.', ''), '0'), '.').').']);
                }
                $receiveLines[] = [$item, $quantity];
            }
            if ($receiveLines === []) {
                throw ValidationException::withMessages(['quantities' => 'Isi jumlah barang yang diterima.']);
            }

            $receiptId = (string) Str::uuid();
            $receiptNumber = $this->nextNumber($tenant->id, $outlet->id, 'goods_receipt', 'GRN', $now);
            DB::table('goods_receipts')->insert([
                'id' => $receiptId, 'tenant_id' => $tenant->id, 'outlet_id' => $outlet->id, 'purchase_order_id' => $id,
                'supplier_id' => $order->supplier_id, 'receipt_number' => $receiptNumber, 'status' => 'posted', 'received_at' => $now,
                'created_by_user_id' => auth()->id(), 'created_at' => $now, 'updated_at' => $now,
            ]);

            $billTotal = 0.0;
            $billItems = [];
            foreach ($receiveLines as [$item, $quantity]) {
                $lineValue = round($quantity * (float) $item->unit_cost, 2);
                $billTotal += $lineValue;
                $receiptItemId = (string) Str::uuid();
                DB::table('goods_receipt_items')->insert([
                    'id' => $receiptItemId, 'tenant_id' => $tenant->id, 'goods_receipt_id' => $receiptId, 'purchase_order_item_id' => $item->id,
                    'product_variant_id' => $item->product_variant_id, 'name_snapshot' => $item->name_snapshot, 'quantity' => $quantity,
                    'unit_id' => $item->unit_id, 'base_quantity' => $quantity, 'unit_cost' => $item->unit_cost, 'condition' => 'good',
                    'created_at' => $now, 'updated_at' => $now,
                ]);
                DB::table('purchase_order_items')->where('id', $item->id)->update([
                    'received_quantity' => round((float) $item->received_quantity + $quantity, 4), 'updated_at' => $now,
                ]);
                $billItems[] = [
                    'id' => (string) Str::uuid(), 'tenant_id' => $tenant->id, 'goods_receipt_item_id' => $receiptItemId,
                    'product_variant_id' => $item->product_variant_id, 'description_snapshot' => $item->name_snapshot,
                    'quantity' => $quantity, 'unit_cost' => $item->unit_cost, 'line_total' => $lineValue, 'created_at' => $now, 'updated_at' => $now,
                ];
                $this->increaseStock($tenant->id, $outlet->id, $item, $quantity, $lineValue, $receiptId, $receiptItemId, $now);
            }

            $billId = (string) Str::uuid();
            $terms = (int) ($supplier->payment_terms_days ?? 0);
            DB::table('supplier_bills')->insert([
                'id' => $billId, 'tenant_id' => $tenant->id, 'outlet_id' => $outlet->id, 'supplier_id' => $order->supplier_id,
                'purchase_order_id' => $id, 'bill_number' => $this->nextNumber($tenant->id, $outlet->id, 'supplier_bill', 'BILL', $now),
                'supplier_invoice_number' => $data['supplier_invoice_number'] ?? null, 'status' => 'open', 'billed_at' => $now,
                'due_at' => $now->copy()->addDays($terms)->toDateString(), 'subtotal' => $billTotal, 'discount_total' => 0,
                'total' => $billTotal, 'paid_total' => 0, 'balance_due' => $billTotal, 'created_at' => $now, 'updated_at' => $now,
            ]);
            foreach ($billItems as $billItem) {
                DB::table('supplier_bill_items')->insert($billItem + ['supplier_bill_id' => $billId]);
            }

            $inventory = DB::table('chart_of_accounts')->where('tenant_id', $tenant->id)->where('code', '1100')->first();
            $payable = DB::table('chart_of_accounts')->where('tenant_id', $tenant->id)->where('code', '2010')->first();
            if (! $inventory || ! $payable) {
                throw ValidationException::withMessages(['quantities' => 'Akun persediaan atau hutang supplier belum disiapkan.']);
            }
            $this->postJournal($tenant->id, $outlet->id, 'PUR-'.$receiptNumber, 'Penerimaan barang '.$receiptNumber, 'goods_receipt', $receiptId,
                'goods-receipt:'.$receiptId.':journal', [[$inventory->id, $billTotal, 0], [$payable->id, 0, $billTotal]], $now);

            $fullyReceived = DB::table('purchase_order_items')->where('purchase_order_id', $id)->whereColumn('received_quantity', '<', 'ordered_quantity')->doesntExist();
            DB::table('purchase_orders')->where('id', $id)->update(['status' => $fullyReceived ? 'received' : 'partially_received', 'updated_at' => $now]);
            $this->audit($tenant->id, $outlet->id, 'purchase_order.received', $id, ['receipt' => $receiptNumber, 'value' => $billTotal], $now);
        }, attempts: 3);

        return redirect()->route('purchasing.show', $id)->with('success', 'Barang diterima. Stok bertambah dan tagihan supplier sudah dibuat.');
    }

    public function pay(Request $request, string $billId): RedirectResponse
    {
        $tenant = $request->attributes->get('tenant');
        $outlet = $request->attributes->get('outlet');
        $data = $request->validate([
            'method' => ['required', 'in:cash,bank_transfer'], 'amount' => ['required', 'numeric', 'gt:0', 'max:99999999999999'],
        ]);

        $orderId = DB::transaction(function () use ($tenant, $outlet, $billId, $data) {
            $now = now($tenant->timezone);
            $bill = DB::table('supplier_bills')->where('tenant_id', $tenant->id)->where('outlet_id', $outlet->id)->where('id', $billId)->lockForUpdate()->first();
            abort_unless($bill && in_array($bill->status, ['open', 'partial'], true), 422, 'Tagihan ini sudah lunas atau tidak bisa dibayar.');
            $amount = round((float) $data['amount'], 2);
            if ($amount > round((float) $bill->balance_due, 2)) {
                throw ValidationException::withMessages(['amount' => 'Nominal melebihi sisa tagihan.']);
            }
            $accountType = $data['method'] === 'cash' ? 'cash' : 'bank';
            $cashAccount = DB::table('cash_accounts')->where('tenant_id', $tenant->id)->where('outlet_id', $outlet->id)
                ->where('type', $accountType)->where('is_active', true)->first();
            if (! $cashAccount || ! $cashAccount->chart_account_id) {
                throw ValidationException::withMessages(['method' => 'Akun metode pembayaran belum disiapkan.']);
            }
            $payable = DB::table('chart_of_accounts')->where('tenant_id', $tenant->id)->where('code', '2010')->first();

            $sequence = DB::table('payment_allocations')->where('tenant_id', $tenant->id)->where('supplier_bill_id', $bill->id)->count() + 1;
            $paymentNumber = 'PAYS-'.$bill->bill_number.'-'.str_pad((string) $sequence, 2, '0', STR_PAD_LEFT);
            $paymentId = (string) Str::uuid();
            $openShift = $data['method'] === 'cash'
                ? DB::table('cash_shifts')->where('tenant_id', $tenant->id)->where('outlet_id', $outlet->id)
                    ->where('cashier_user_id', auth()->id())->where('status', 'open')->first()
                : null;
            DB::table('payments')->insert([
                'id' => $paymentId, 'tenant_id' => $tenant->id, 'outlet_id' => $outlet->id, 'cash_shift_id' => $openShift?->id, 'cash_account_id' => $cashAccount->id,
                'confirmed_by_user_id' => auth()->id(), 'payment_number' => $paymentNumber, 'method' => $data['method'], 'status' => 'confirmed',
                'amount' => $amount, 'received_at' => $now, 'confirmed_at' => $now, 'idempotency_key' => 'supplier-bill:'.$bill->id.':'.$sequence,
                'created_at' => $now, 'updated_at' => $now,
            ]);
            DB::table('payment_allocations')->insert([
                'id' => (string) Str::uuid(), 'tenant_id' => $tenant->id, 'payment_id' => $paymentId, 'supplier_bill_id' => $bill->id,
                'amount' => $amount, 'created_at' => $now, 'updated_at' => $now,
            ]);
            $paid = round((float) $bill->paid_total + $amount, 2);
            $balance = round((float) $bill->total - $paid, 2);
            DB::table('supplier_bills')->where('id', $bill->id)->update([
                'paid_total' => $paid, 'balance_due' => $balance, 'status' => $balance <= 0 ? 'paid' : 'partial', 'updated_at' => $now,
            ]);
            $this->postJournal($tenant->id, $outlet->id, 'PAY-'.$paymentNumber, 'Pembayaran tagihan '.$bill->bill_number, 'supplier_payment', $paymentId,
                'supplier-payment:'.$paymentId.':journal', [[$payable->id, $amount, 0], [$cashAccount->chart_account_id, 0, $amount]], $now);
            $this->audit($tenant->id, $outlet->id, 'supplier_bill.paid', $bill->id, ['amount' => $amount, 'payment' => $paymentNumber], $now);

            return $bill->purchase_order_id;
        }, attempts: 3);

        return $orderId
            ? redirect()->route('purchasing.show', $orderId)->with('success', 'Pembayaran supplier berhasil dicatat.')
            : back()->with('success', 'Pembayaran supplier berhasil dicatat.');
    }

    /**
     * Stok bertambah dengan biaya rata-rata bergerak; buku besar stok bersifat append-only.
     */
    private function increaseStock(string $tenantId, string $outletId, object $item, float $quantity, float $value, string $receiptId, string $receiptItemId, $now): void
    {
        $balance = DB::table('stock_balances')->where('tenant_id', $tenantId)->where('outlet_id', $outletId)
            ->where('product_variant_id', $item->product_variant_id)->lockForUpdate()->first();
        DB::table('stock_movements')->insert([
            'id' => (string) Str::uuid(), 'tenant_id' => $tenantId, 'outlet_id' => $outletId, 'product_variant_id' => $item->product_variant_id,
            'occurred_at' => $now, 'movement_type' => 'purchase_receipt', 'quantity_delta' => $quantity, 'unit_cost' => $item->unit_cost,
            'value_delta' => $value, 'source_type' => 'goods_receipt', 'source_id' => $receiptId, 'source_line_id' => $receiptItemId,
            'idempotency_key' => 'goods-receipt:'.$receiptItemId, 'created_by_user_id' => auth()->id(), 'reason' => 'Penerimaan barang',
            'created_at' => $now, 'updated_at' => $now,
        ]);
        if (! $balance) {
            DB::table('stock_balances')->insert([
                'id' => (string) Str::uuid(), 'tenant_id' => $tenantId, 'outlet_id' => $outletId, 'product_variant_id' => $item->product_variant_id,
                'quantity_on_hand' => $quantity, 'inventory_value' => $value, 'average_cost' => $item->unit_cost, 'version' => 0,
                'created_at' => $now, 'updated_at' => $now,
            ]);
        } else {
            $newQuantity = round((float) $balance->quantity_on_hand + $quantity, 4);
            $newValue = round((float) $balance->inventory_value + $value, 2);
            DB::table('stock_balances')->where('id', $balance->id)->update([
                'quantity_on_hand' => $newQuantity, 'inventory_value' => $newValue,
                'average_cost' => $newQuantity > 0 ? round($newValue / $newQuantity, 2) : $item->unit_cost,
                'version' => $balance->version + 1, 'updated_at' => $now,
            ]);
        }
        DB::table('product_variants')->where('id', $item->product_variant_id)->update(['cost_price' => $item->unit_cost, 'updated_at' => $now]);
    }

    /**
     * @param  array<int, array{0: string, 1: float, 2: float}>  $lines  [account_id, debit, credit]
     */
    private function postJournal(string $tenantId, string $outletId, string $number, string $description, string $sourceType, string $sourceId, string $idempotencyKey, array $lines, $now): void
    {
        $entryId = (string) Str::uuid();
        DB::table('journal_entries')->insert([
            'id' => $entryId, 'tenant_id' => $tenantId, 'outlet_id' => $outletId, 'entry_number' => $number, 'entry_date' => $now->toDateString(),
            'status' => 'posted', 'description' => $description, 'source_type' => $sourceType, 'source_id' => $sourceId,
            'idempotency_key' => $idempotencyKey, 'posted_at' => $now, 'posted_by_user_id' => auth()->id(),
            'created_by_user_id' => auth()->id(), 'created_at' => $now, 'updated_at' => $now,
        ]);
        foreach ($lines as [$accountId, $debit, $credit]) {
            DB::table('journal_lines')->insert([
                'id' => (string) Str::uuid(), 'tenant_id' => $tenantId, 'journal_entry_id' => $entryId, 'account_id' => $accountId,
                'description' => $description, 'debit' => round($debit, 2), 'credit' => round($credit, 2), 'created_at' => $now, 'updated_at' => $now,
            ]);
        }
    }

    private function nextNumber(string $tenantId, string $outletId, string $type, string $prefix, $now): string
    {
        $period = $now->format('Y');
        $scope = ['tenant_id' => $tenantId, 'outlet_id' => $outletId, 'document_type' => $type, 'period_key' => $period];
        $counter = DB::table('document_counters')->where($scope)->lockForUpdate()->first();
        if (! $counter) {
            DB::table('document_counters')->insert($scope + [
                'id' => (string) Str::uuid(), 'prefix' => $prefix, 'next_number' => 1, 'padding' => 5, 'created_at' => $now, 'updated_at' => $now,
            ]);
            $counter = DB::table('document_counters')->where($scope)->lockForUpdate()->first();
        }
        DB::table('document_counters')->where('id', $counter->id)->update(['next_number' => $counter->next_number + 1, 'updated_at' => $now]);

        return ($counter->prefix ?: $prefix).'/'.$period.'/'.str_pad((string) $counter->next_number, $counter->padding, '0', STR_PAD_LEFT);
    }

    /**
     * @param  array<string, mixed>  $after
     */
    private function audit(string $tenantId, string $outletId, string $action, string $subjectId, array $after, $now): void
    {
        DB::table('audit_logs')->insert([
            'id' => (string) Str::uuid(), 'tenant_id' => $tenantId, 'outlet_id' => $outletId, 'actor_user_id' => auth()->id(),
            'action' => $action, 'subject_type' => str_starts_with($action, 'supplier_bill') ? 'supplier_bill' : 'purchase_order',
            'subject_id' => $subjectId, 'after' => json_encode($after), 'occurred_at' => $now,
        ]);

    }
}
