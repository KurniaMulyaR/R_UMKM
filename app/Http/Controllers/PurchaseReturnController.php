<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class PurchaseReturnController extends Controller
{
    public function create(Request $request, string $billId): View
    {
        $tenant = $request->attributes->get('tenant');
        $outlet = $request->attributes->get('outlet');
        $bill = DB::table('supplier_bills')->join('suppliers', 'suppliers.id', '=', 'supplier_bills.supplier_id')
            ->where('supplier_bills.tenant_id', $tenant->id)->where('supplier_bills.outlet_id', $outlet->id)->where('supplier_bills.id', $billId)
            ->select('supplier_bills.*', 'suppliers.name as supplier_name')->first();
        abort_unless($bill, 404);
        $items = $this->returnableItems($tenant->id, $billId);
        $returns = DB::table('purchase_returns')->where('tenant_id', $tenant->id)->where('supplier_bill_id', $billId)->orderByDesc('returned_at')->get();

        return view('purchasing.return', compact('bill', 'items', 'returns'));
    }

    public function store(Request $request, string $billId): RedirectResponse
    {
        $tenant = $request->attributes->get('tenant');
        $outlet = $request->attributes->get('outlet');
        $data = $request->validate([
            'quantities' => ['required', 'array'], 'quantities.*' => ['nullable', 'numeric', 'min:0', 'max:999999'],
            'reason' => ['required', 'string', 'min:3', 'max:500'],
        ]);

        $orderId = DB::transaction(function () use ($tenant, $outlet, $billId, $data) {
            $now = now($tenant->timezone);
            $bill = DB::table('supplier_bills')->where('tenant_id', $tenant->id)->where('outlet_id', $outlet->id)->where('id', $billId)->lockForUpdate()->first();
            abort_unless($bill, 404);
            $returnable = $this->returnableItems($tenant->id, $billId)->keyBy('id');

            $lines = [];
            $total = 0.0;
            foreach ($data['quantities'] as $billItemId => $quantity) {
                $quantity = round((float) $quantity, 4);
                if ($quantity <= 0) {
                    continue;
                }
                $item = $returnable->get($billItemId);
                if (! $item) {
                    throw ValidationException::withMessages(['quantities' => 'Ada barang yang bukan bagian dari tagihan ini.']);
                }
                if ($quantity > round((float) $item->remaining, 4)) {
                    throw ValidationException::withMessages(['quantities' => $item->description_snapshot.': jumlah retur melebihi sisa yang bisa diretur.']);
                }
                $value = round($quantity * (float) $item->unit_cost, 2);
                $total += $value;
                $lines[] = [$item, $quantity, $value];
            }
            if ($lines === []) {
                throw ValidationException::withMessages(['quantities' => 'Isi jumlah barang yang diretur.']);
            }
            $total = round($total, 2);
            $payableReduction = min($total, max(0.0, round((float) $bill->balance_due, 2)));
            $refundDue = round($total - $payableReduction, 2);

            $returnId = (string) Str::uuid();
            $number = $this->nextNumber($tenant->id, $outlet->id, $now);
            DB::table('purchase_returns')->insert([
                'id' => $returnId, 'tenant_id' => $tenant->id, 'outlet_id' => $outlet->id, 'supplier_id' => $bill->supplier_id,
                'supplier_bill_id' => $bill->id, 'return_number' => $number, 'status' => 'posted', 'returned_at' => $now,
                'total' => $total, 'refund_due' => $refundDue, 'refunded_total' => 0, 'reason' => $data['reason'], 'created_by_user_id' => auth()->id(), 'created_at' => $now, 'updated_at' => $now,
            ]);

            foreach ($lines as [$item, $quantity, $value]) {
                $returnItemId = (string) Str::uuid();
                DB::table('purchase_return_items')->insert([
                    'id' => $returnItemId, 'tenant_id' => $tenant->id, 'purchase_return_id' => $returnId, 'supplier_bill_item_id' => $item->id,
                    'product_variant_id' => $item->product_variant_id, 'name_snapshot' => $item->description_snapshot, 'quantity' => $quantity,
                    'unit_cost' => $item->unit_cost, 'line_total' => $value, 'created_at' => $now, 'updated_at' => $now,
                ]);
                if ($item->product_variant_id) {
                    $this->decreaseStock($tenant->id, $outlet->id, $item, $quantity, $value, $returnId, $returnItemId, $data['reason'], $now);
                }
            }

            $inventory = DB::table('chart_of_accounts')->where('tenant_id', $tenant->id)->where('code', '1100')->first();
            $payable = DB::table('chart_of_accounts')->where('tenant_id', $tenant->id)->where('code', '2010')->first();
            $entryId = (string) Str::uuid();
            DB::table('journal_entries')->insert([
                'id' => $entryId, 'tenant_id' => $tenant->id, 'outlet_id' => $outlet->id, 'entry_number' => 'PRJ-'.$number,
                'entry_date' => $now->toDateString(), 'status' => 'posted', 'description' => 'Retur pembelian '.$number,
                'source_type' => 'purchase_return', 'source_id' => $returnId, 'idempotency_key' => 'purchase-return:'.$returnId.':journal',
                'posted_at' => $now, 'posted_by_user_id' => auth()->id(), 'created_by_user_id' => auth()->id(), 'created_at' => $now, 'updated_at' => $now,
            ]);
            $journalLines = [[$inventory->id, 0, $total]];
            if ($payableReduction > 0) {
                $journalLines[] = [$payable->id, $payableReduction, 0];
            }
            if ($refundDue > 0) {
                $journalLines[] = [$this->refundReceivableAccount($tenant->id, $now)->id, $refundDue, 0];
            }
            foreach ($journalLines as [$accountId, $debit, $credit]) {
                DB::table('journal_lines')->insert([
                    'id' => (string) Str::uuid(), 'tenant_id' => $tenant->id, 'journal_entry_id' => $entryId, 'account_id' => $accountId,
                    'supplier_id' => $bill->supplier_id, 'description' => 'Retur pembelian '.$number, 'debit' => round($debit, 2),
                    'credit' => round($credit, 2), 'created_at' => $now, 'updated_at' => $now,
                ]);
            }

            $newTotal = round((float) $bill->total - $total, 2);
            $newBalance = max(0.0, round($newTotal - (float) $bill->paid_total, 2));
            DB::table('supplier_bills')->where('id', $bill->id)->update([
                'total' => $newTotal, 'subtotal' => round((float) $bill->subtotal - $total, 2), 'balance_due' => $newBalance,
                'status' => $newBalance <= 0 ? 'paid' : ((float) $bill->paid_total > 0 ? 'partial' : 'open'), 'updated_at' => $now,
            ]);
            DB::table('audit_logs')->insert([
                'id' => (string) Str::uuid(), 'tenant_id' => $tenant->id, 'outlet_id' => $outlet->id, 'actor_user_id' => auth()->id(),
                'action' => 'purchase_return.created', 'subject_type' => 'supplier_bill', 'subject_id' => $bill->id,
                'after' => json_encode(['return' => $number, 'total' => $total, 'refund_due' => $refundDue]), 'reason' => $data['reason'], 'occurred_at' => $now,
            ]);


            return $bill->purchase_order_id;
        }, attempts: 3);

        return redirect()->route($orderId ? 'purchasing.show' : 'purchasing.index', $orderId ? [$orderId] : [])->with('success', 'Retur pembelian dicatat. Stok berkurang dan tagihan supplier disesuaikan.');
    }

    public function refund(Request $request, string $returnId): RedirectResponse
    {
        $tenant = $request->attributes->get('tenant');
        $outlet = $request->attributes->get('outlet');
        $data = $request->validate([
            'method' => ['required', 'in:cash,bank_transfer'], 'amount' => ['required', 'numeric', 'gt:0', 'max:99999999999999'],
        ]);

        $billId = DB::transaction(function () use ($tenant, $outlet, $returnId, $data) {
            $now = now($tenant->timezone);
            $return = DB::table('purchase_returns')->where('tenant_id', $tenant->id)->where('outlet_id', $outlet->id)->where('id', $returnId)->lockForUpdate()->first();
            abort_unless($return && $return->status === 'posted', 404);
            $outstanding = round((float) $return->refund_due - (float) $return->refunded_total, 2);
            $amount = round((float) $data['amount'], 2);
            if ($amount > $outstanding) {
                throw ValidationException::withMessages(['amount' => $outstanding > 0 ? 'Nominal melebihi refund yang belum diterima.' : 'Retur ini tidak punya refund yang tertunda.']);
            }
            $cashAccount = DB::table('cash_accounts')->where('tenant_id', $tenant->id)->where('outlet_id', $outlet->id)
                ->where('type', $data['method'] === 'cash' ? 'cash' : 'bank')->where('is_active', true)->first();
            if (! $cashAccount || ! $cashAccount->chart_account_id) {
                throw ValidationException::withMessages(['method' => 'Akun kas atau bank belum disiapkan.']);
            }
            $openShift = $data['method'] === 'cash'
                ? DB::table('cash_shifts')->where('tenant_id', $tenant->id)->where('outlet_id', $outlet->id)
                    ->where('cashier_user_id', auth()->id())->where('status', 'open')->first()
                : null;
            $refundId = (string) Str::uuid();
            $entryId = (string) Str::uuid();
            DB::table('journal_entries')->insert([
                'id' => $entryId, 'tenant_id' => $tenant->id, 'outlet_id' => $outlet->id, 'entry_number' => 'RFD-'.$return->return_number.'-'.Str::upper(Str::random(4)),
                'entry_date' => $now->toDateString(), 'status' => 'posted', 'description' => 'Refund retur pembelian '.$return->return_number,
                'source_type' => 'purchase_return_refund', 'source_id' => $refundId, 'idempotency_key' => 'purchase-refund:'.$refundId.':journal',
                'posted_at' => $now, 'posted_by_user_id' => auth()->id(), 'created_by_user_id' => auth()->id(), 'created_at' => $now, 'updated_at' => $now,
            ]);
            foreach ([[$cashAccount->chart_account_id, $amount, 0], [$this->refundReceivableAccount($tenant->id, $now)->id, 0, $amount]] as [$accountId, $debit, $credit]) {
                DB::table('journal_lines')->insert([
                    'id' => (string) Str::uuid(), 'tenant_id' => $tenant->id, 'journal_entry_id' => $entryId, 'account_id' => $accountId,
                    'supplier_id' => $return->supplier_id, 'description' => 'Refund '.$return->return_number, 'debit' => $debit, 'credit' => $credit,
                    'created_at' => $now, 'updated_at' => $now,
                ]);
            }
            DB::table('purchase_return_refunds')->insert([
                'id' => $refundId, 'tenant_id' => $tenant->id, 'outlet_id' => $outlet->id, 'purchase_return_id' => $return->id,
                'cash_account_id' => $cashAccount->id, 'cash_shift_id' => $openShift?->id, 'method' => $data['method'], 'amount' => $amount,
                'received_at' => $now, 'journal_entry_id' => $entryId, 'created_by_user_id' => auth()->id(), 'created_at' => $now, 'updated_at' => $now,
            ]);
            DB::table('purchase_returns')->where('id', $return->id)->update(['refunded_total' => round((float) $return->refunded_total + $amount, 2), 'updated_at' => $now]);
            DB::table('audit_logs')->insert([
                'id' => (string) Str::uuid(), 'tenant_id' => $tenant->id, 'outlet_id' => $outlet->id, 'actor_user_id' => auth()->id(),
                'action' => 'purchase_return.refunded', 'subject_type' => 'supplier_bill', 'subject_id' => $return->supplier_bill_id,
                'after' => json_encode(['return' => $return->return_number, 'amount' => $amount]), 'occurred_at' => $now,
            ]);


            return $return->supplier_bill_id;
        }, attempts: 3);

        return redirect()->route('purchasing.return.create', $billId)->with('success', 'Refund dari supplier dicatat.');
    }

    private function refundReceivableAccount(string $tenantId, $now): object
    {
        $account = DB::table('chart_of_accounts')->where('tenant_id', $tenantId)->where('code', '1210')->first();
        if ($account) {
            return $account;
        }
        DB::table('chart_of_accounts')->insert([
            'id' => (string) Str::uuid(), 'tenant_id' => $tenantId, 'code' => '1210', 'name' => 'Piutang Refund Supplier',
            'account_type' => 'asset', 'normal_balance' => 'debit', 'is_system' => true, 'allow_manual_posting' => false,
            'is_active' => true, 'created_at' => $now, 'updated_at' => $now,
        ]);

        return DB::table('chart_of_accounts')->where('tenant_id', $tenantId)->where('code', '1210')->first();
    }

    /**
     * @return \Illuminate\Support\Collection<int, object>
     */
    private function returnableItems(string $tenantId, string $billId)
    {
        $returned = DB::table('purchase_return_items')->join('purchase_returns', 'purchase_returns.id', '=', 'purchase_return_items.purchase_return_id')
            ->where('purchase_return_items.tenant_id', $tenantId)->where('purchase_returns.status', 'posted')
            ->groupBy('purchase_return_items.supplier_bill_item_id')
            ->selectRaw('purchase_return_items.supplier_bill_item_id as item_id, SUM(purchase_return_items.quantity) as qty')->pluck('qty', 'item_id');

        return DB::table('supplier_bill_items')->where('tenant_id', $tenantId)->where('supplier_bill_id', $billId)->orderBy('created_at')->get()
            ->map(function ($item) use ($returned) {
                $item->returned = (float) ($returned[$item->id] ?? 0);
                $item->remaining = round((float) $item->quantity - $item->returned, 4);

                return $item;
            });
    }

    private function decreaseStock(string $tenantId, string $outletId, object $item, float $quantity, float $value, string $returnId, string $returnItemId, string $reason, $now): void
    {
        $balance = DB::table('stock_balances')->where('tenant_id', $tenantId)->where('outlet_id', $outletId)
            ->where('product_variant_id', $item->product_variant_id)->lockForUpdate()->first();
        if (! $balance || (float) $balance->quantity_on_hand < $quantity) {
            throw ValidationException::withMessages(['quantities' => $item->description_snapshot.': stok saat ini tidak cukup untuk diretur.']);
        }
        DB::table('stock_movements')->insert([
            'id' => (string) Str::uuid(), 'tenant_id' => $tenantId, 'outlet_id' => $outletId, 'product_variant_id' => $item->product_variant_id,
            'occurred_at' => $now, 'movement_type' => 'purchase_return', 'quantity_delta' => -$quantity, 'unit_cost' => $item->unit_cost,
            'value_delta' => -$value, 'source_type' => 'purchase_return', 'source_id' => $returnId, 'source_line_id' => $returnItemId,
            'idempotency_key' => 'purchase-return:'.$returnItemId, 'created_by_user_id' => auth()->id(), 'reason' => $reason,
            'created_at' => $now, 'updated_at' => $now,
        ]);
        $newQuantity = round((float) $balance->quantity_on_hand - $quantity, 4);
        $newValue = max(0.0, round((float) $balance->inventory_value - $value, 2));
        DB::table('stock_balances')->where('id', $balance->id)->update([
            'quantity_on_hand' => $newQuantity, 'inventory_value' => $newValue,
            'average_cost' => $newQuantity > 0 ? round($newValue / $newQuantity, 2) : $balance->average_cost,
            'version' => $balance->version + 1, 'updated_at' => $now,
        ]);
    }

    private function nextNumber(string $tenantId, string $outletId, $now): string
    {
        $scope = ['tenant_id' => $tenantId, 'outlet_id' => $outletId, 'document_type' => 'purchase_return', 'period_key' => $now->format('Y')];
        $counter = DB::table('document_counters')->where($scope)->lockForUpdate()->first();
        if (! $counter) {
            DB::table('document_counters')->insert($scope + [
                'id' => (string) Str::uuid(), 'prefix' => 'PRT', 'next_number' => 1, 'padding' => 5, 'created_at' => $now, 'updated_at' => $now,
            ]);
            $counter = DB::table('document_counters')->where($scope)->lockForUpdate()->first();
        }
        DB::table('document_counters')->where('id', $counter->id)->update(['next_number' => $counter->next_number + 1, 'updated_at' => $now]);

        return $counter->prefix.'/'.$scope['period_key'].'/'.str_pad((string) $counter->next_number, $counter->padding, '0', STR_PAD_LEFT);
    }
}
