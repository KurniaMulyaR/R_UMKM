<?php

namespace App\Http\Controllers;

use App\Models\AccountingPeriod;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class SaleReturnController extends Controller
{
    public function create(Request $request, string $saleId): View
    {
        $tenant = $request->attributes->get('tenant');
        $outlet = $request->attributes->get('outlet');
        $sale = $this->loadSale($tenant->id, $outlet->id, $saleId);
        $returns = DB::table('sale_returns')->where('tenant_id', $tenant->id)->where('sale_id', $saleId)->orderByDesc('returned_at')->get();

        return view('sales.return', [
            'sale' => $sale, 'items' => $this->returnableItems($tenant->id, $saleId), 'returns' => $returns,
            'outstanding' => $this->outstanding($sale),
        ]);
    }

    public function store(Request $request, string $saleId): RedirectResponse
    {
        $tenant = $request->attributes->get('tenant');
        $outlet = $request->attributes->get('outlet');
        $data = $request->validate([
            'reason' => ['required', 'string', 'min:3', 'max:255'], 'refund_method' => ['nullable', 'in:cash,bank_transfer,qris_external'],
            'items' => ['required', 'array', 'max:100'], 'items.*.quantity' => ['nullable', 'numeric', 'min:0', 'max:999999'], 'items.*.condition' => ['nullable', 'in:good,damaged'],
        ]);

        $returnId = DB::transaction(function () use ($tenant, $outlet, $saleId, $data) {
            $sale = DB::table('sales')->where('tenant_id', $tenant->id)->where('outlet_id', $outlet->id)->where('id', $saleId)->lockForUpdate()->first();
            abort_unless($sale && $sale->status === 'completed', 422, 'Transaksi ini tidak bisa diretur.');
            $now = now($tenant->timezone);
            $items = $this->returnableItems($tenant->id, $saleId)->keyBy('id');

            $lines = [];
            foreach ($data['items'] as $itemId => $input) {
                $quantity = round((float) ($input['quantity'] ?? 0), 4);
                if ($quantity <= 0) {
                    continue;
                }
                $item = $items->get($itemId);
                if (! $item || $quantity > (float) $item->remaining + 0.00001) {
                    throw ValidationException::withMessages(['items' => 'Jumlah retur melebihi sisa yang bisa diretur.']);
                }
                $isLast = abs($quantity - (float) $item->remaining) < 0.00001;
                $refund = $isLast ? round((float) $item->line_total - (float) $item->refunded, 2) : round((float) $item->line_total / (float) $item->quantity * $quantity, 2);
                $restock = ($input['condition'] ?? 'good') === 'good' && $item->track_stock ? $quantity : 0.0;
                $lines[] = ['item' => $item, 'quantity' => $quantity, 'refund' => max(0.0, $refund), 'restock' => $restock, 'condition' => $input['condition'] ?? 'good'];
            }
            if ($lines === []) {
                throw ValidationException::withMessages(['items' => 'Isi jumlah retur minimal satu barang.']);
            }

            $refundTotal = round(collect($lines)->sum('refund'), 2);
            $outstanding = $this->outstanding($sale);
            $receivableReduction = $sale->customer_id ? min($refundTotal, $outstanding) : 0.0;
            $cashRefund = round($refundTotal - $receivableReduction, 2);
            $method = $data['refund_method'] ?? null;
            if ($cashRefund > 0 && ! $method) {
                throw ValidationException::withMessages(['refund_method' => 'Pilih metode pengembalian uang.']);
            }
            $shift = null;
            if ($cashRefund > 0 && $method === 'cash') {
                $shift = DB::table('cash_shifts')->where('tenant_id', $tenant->id)->where('outlet_id', $outlet->id)->where('cashier_user_id', auth()->id())->where('status', 'open')->lockForUpdate()->first();
                if (! $shift) {
                    throw ValidationException::withMessages(['shift' => 'Buka shift sebelum mengembalikan uang tunai.']);
                }
            }
            $cashAccountId = null;
            if ($cashRefund > 0) {
                $type = ['cash' => 'cash', 'bank_transfer' => 'bank', 'qris_external' => 'qris_clearing'][$method];
                $cashAccount = DB::table('cash_accounts')->where('tenant_id', $tenant->id)->where('outlet_id', $outlet->id)->where('type', $type)->where('is_active', true)->first();
                if (! $cashAccount) {
                    throw ValidationException::withMessages(['refund_method' => 'Akun metode pengembalian belum disiapkan.']);
                }
                $cashAccountId = $cashAccount->chart_account_id;
            }

            $returnId = (string) Str::uuid();
            $number = $this->nextNumber($tenant->id, $outlet->id, $now);
            $restockValue = 0.0;
            DB::table('sale_returns')->insert([
                'id' => $returnId, 'tenant_id' => $tenant->id, 'outlet_id' => $outlet->id, 'sale_id' => $sale->id, 'return_number' => $number, 'status' => 'completed',
                'reason' => $data['reason'], 'returned_at' => $now, 'created_by_user_id' => auth()->id(), 'approved_by_user_id' => auth()->id(), 'refund_total' => $refundTotal,
                'restock_policy' => 'good_condition', 'receivable_reduction' => $receivableReduction, 'cash_refund_amount' => $cashRefund, 'refund_method' => $cashRefund > 0 ? $method : null,
                'cash_shift_id' => $shift?->id, 'created_at' => $now, 'updated_at' => $now,
            ]);

            foreach ($lines as $line) {
                $item = $line['item'];
                DB::table('sale_return_items')->insert([
                    'id' => (string) Str::uuid(), 'tenant_id' => $tenant->id, 'sale_return_id' => $returnId, 'sale_item_id' => $item->id, 'product_variant_id' => $item->product_variant_id,
                    'quantity' => $line['quantity'], 'condition' => $line['condition'], 'restock_quantity' => $line['restock'], 'refund_amount' => $line['refund'],
                    'reason' => $data['reason'], 'created_at' => $now, 'updated_at' => $now,
                ]);
                if ($line['restock'] > 0 && $item->product_variant_id) {
                    $value = round($line['restock'] * (float) $item->unit_cost_snapshot, 2);
                    $restockValue += $value;
                    $this->restock($tenant->id, $outlet->id, $item->product_variant_id, $line['restock'], (float) $item->unit_cost_snapshot, $value, $returnId, $number, $now);
                }
            }
            $restockValue = round($restockValue, 2);
            DB::table('sale_returns')->where('id', $returnId)->update(['restock_value' => $restockValue]);

            $entryId = (string) Str::uuid();
            DB::table('journal_entries')->insert([
                'id' => $entryId, 'tenant_id' => $tenant->id, 'outlet_id' => $outlet->id, 'entry_number' => 'JRT-'.$number, 'entry_date' => $now->toDateString(), 'status' => 'posted',
                'description' => 'Retur penjualan '.$sale->sale_number, 'source_type' => 'sale_return', 'source_id' => $returnId, 'idempotency_key' => 'sale-return:'.$returnId.':journal',
                'posted_at' => $now, 'posted_by_user_id' => auth()->id(), 'created_by_user_id' => auth()->id(), 'created_at' => $now, 'updated_at' => $now,
            ]);
            $revenue = $this->accountId($tenant->id, '4010');
            $journal = [[$revenue, $refundTotal, 0]];
            if ($receivableReduction > 0) {
                $journal[] = [$this->accountId($tenant->id, '1200'), 0, $receivableReduction];
            }
            if ($cashRefund > 0) {
                $journal[] = [$cashAccountId, 0, $cashRefund];
            }
            if ($restockValue > 0) {
                $journal[] = [$this->accountId($tenant->id, '1100'), $restockValue, 0];
                $journal[] = [$this->accountId($tenant->id, '5010'), 0, $restockValue];
            }
            foreach ($journal as [$accountId, $debit, $credit]) {
                DB::table('journal_lines')->insert([
                    'id' => (string) Str::uuid(), 'tenant_id' => $tenant->id, 'journal_entry_id' => $entryId, 'account_id' => $accountId, 'customer_id' => $sale->customer_id,
                    'description' => 'Retur '.$number, 'debit' => round($debit, 2), 'credit' => round($credit, 2), 'created_at' => $now, 'updated_at' => $now,
                ]);
            }
            DB::table('sale_returns')->where('id', $returnId)->update(['journal_entry_id' => $entryId]);
            DB::table('sales')->where('id', $sale->id)->update(['returned_total' => round((float) $sale->returned_total + $refundTotal, 2), 'updated_at' => $now]);
            DB::table('audit_logs')->insert([
                'id' => (string) Str::uuid(), 'tenant_id' => $tenant->id, 'outlet_id' => $outlet->id, 'actor_user_id' => auth()->id(), 'action' => 'sale_return.created',
                'subject_type' => 'sale', 'subject_id' => $sale->id, 'after' => json_encode(['return' => $number, 'refund_total' => $refundTotal]), 'occurred_at' => $now, 'created_at' => $now,
            ]);

            return $returnId;
        }, attempts: 3);

        return redirect()->route('sales.return.create', $saleId)->with('success', 'Retur dicatat. Stok dan jurnal sudah diperbarui.');
    }

    private function loadSale(string $tenantId, string $outletId, string $saleId): object
    {
        $sale = DB::table('sales')->leftJoin('customers', 'customers.id', '=', 'sales.customer_id')->where('sales.tenant_id', $tenantId)->where('sales.outlet_id', $outletId)->where('sales.id', $saleId)
            ->select('sales.*', 'customers.name as customer_name')->first();
        abort_unless($sale, 404);
        abort_unless($sale->status === 'completed', 422, 'Transaksi ini tidak bisa diretur.');

        return $sale;
    }

    /** Receivable still owed on the sale after payments and earlier returns. */
    private function outstanding(object $sale): float
    {
        return max(0.0, round((float) $sale->grand_total - (float) $sale->returned_total - (float) $sale->paid_total, 2));
    }

    private function returnableItems(string $tenantId, string $saleId)
    {
        $returned = DB::table('sale_return_items')->join('sale_returns', 'sale_returns.id', '=', 'sale_return_items.sale_return_id')
            ->where('sale_returns.tenant_id', $tenantId)->where('sale_returns.sale_id', $saleId)->where('sale_returns.status', 'completed')
            ->groupBy('sale_return_items.sale_item_id')->selectRaw('sale_return_items.sale_item_id, sum(sale_return_items.quantity) as qty, sum(sale_return_items.refund_amount) as amount')->get()->keyBy('sale_item_id');

        return DB::table('sale_items')->leftJoin('products', 'products.id', '=', 'sale_items.product_id')->where('sale_items.tenant_id', $tenantId)->where('sale_items.sale_id', $saleId)
            ->orderBy('sale_items.created_at')->select('sale_items.*', DB::raw('coalesce(products.track_stock, false) as track_stock'))->get()->map(function ($item) use ($returned) {
                $item->returned = (float) ($returned[$item->id]->qty ?? 0);
                $item->refunded = (float) ($returned[$item->id]->amount ?? 0);
                $item->remaining = round((float) $item->quantity - $item->returned, 4);

                return $item;
            });
    }

    private function restock(string $tenantId, string $outletId, string $variantId, float $quantity, float $unitCost, float $value, string $returnId, string $number, $now): void
    {
        $balance = DB::table('stock_balances')->where('tenant_id', $tenantId)->where('outlet_id', $outletId)->where('product_variant_id', $variantId)->lockForUpdate()->first();
        DB::table('stock_movements')->insert([
            'id' => (string) Str::uuid(), 'tenant_id' => $tenantId, 'outlet_id' => $outletId, 'product_variant_id' => $variantId, 'occurred_at' => $now, 'movement_type' => 'sale_return',
            'quantity_delta' => $quantity, 'unit_cost' => $unitCost, 'value_delta' => $value, 'source_type' => 'sale_return', 'source_id' => $returnId,
            'source_line_id' => $returnId.':'.$variantId, 'idempotency_key' => 'sale-return:'.$returnId.':'.$variantId, 'created_by_user_id' => auth()->id(),
            'reason' => 'Retur '.$number, 'created_at' => $now, 'updated_at' => $now,
        ]);
        $newQty = round((float) ($balance->quantity_on_hand ?? 0) + $quantity, 4);
        $newValue = round((float) ($balance->inventory_value ?? 0) + $value, 2);
        $data = ['quantity_on_hand' => $newQty, 'inventory_value' => $newValue, 'average_cost' => $newQty > 0 ? round($newValue / $newQty, 2) : $unitCost, 'updated_at' => $now];
        if ($balance) {
            DB::table('stock_balances')->where('id', $balance->id)->update($data + ['version' => $balance->version + 1]);
        } else {
            DB::table('stock_balances')->insert($data + ['id' => (string) Str::uuid(), 'tenant_id' => $tenantId, 'outlet_id' => $outletId, 'product_variant_id' => $variantId, 'version' => 1, 'created_at' => $now]);
        }
    }

    private function accountId(string $tenantId, string $code): string
    {
        return DB::table('chart_of_accounts')->where('tenant_id', $tenantId)->where('code', $code)->value('id');
    }

    private function nextNumber(string $tenantId, string $outletId, $now): string
    {
        $scope = ['tenant_id' => $tenantId, 'outlet_id' => $outletId, 'document_type' => 'sale_return', 'period_key' => $now->format('Y')];
        $counter = DB::table('document_counters')->where($scope)->lockForUpdate()->first();
        if (! $counter) {
            DB::table('document_counters')->insert($scope + ['id' => (string) Str::uuid(), 'prefix' => 'RTJ', 'next_number' => 1, 'padding' => 5, 'created_at' => $now, 'updated_at' => $now]);
            $counter = DB::table('document_counters')->where($scope)->lockForUpdate()->first();
        }
        DB::table('document_counters')->where('id', $counter->id)->update(['next_number' => $counter->next_number + 1, 'updated_at' => $now]);

        return $counter->prefix.'/'.$scope['period_key'].'/'.str_pad((string) $counter->next_number, $counter->padding, '0', STR_PAD_LEFT);
    }
}
