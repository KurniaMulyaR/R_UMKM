<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class StockTransferController extends Controller
{
    public function index(Request $request): View
    {
        $tenant = $request->attributes->get('tenant');
        $outlet = $request->attributes->get('outlet');
        $transfers = DB::table('stock_transfers')->join('outlets as origin', 'origin.id', '=', 'stock_transfers.from_outlet_id')->join('outlets as destination', 'destination.id', '=', 'stock_transfers.to_outlet_id')
            ->where('stock_transfers.tenant_id', $tenant->id)->where(fn ($query) => $query->where('from_outlet_id', $outlet->id)->orWhere('to_outlet_id', $outlet->id))
            ->orderByDesc('stock_transfers.created_at')->limit(50)->select('stock_transfers.*', 'origin.name as from_name', 'destination.name as to_name')->get();

        return view('stock-transfers.index', ['transfers' => $transfers, 'outlet' => $outlet]);
    }

    public function create(Request $request): View
    {
        $tenant = $request->attributes->get('tenant');
        $outlet = $request->attributes->get('outlet');
        $destinations = DB::table('outlets')->where('tenant_id', $tenant->id)->where('status', 'active')->where('id', '!=', $outlet->id)->orderBy('name')->get(['id', 'name']);
        $rows = DB::table('stock_balances')->join('product_variants', 'product_variants.id', '=', 'stock_balances.product_variant_id')->join('products', 'products.id', '=', 'product_variants.product_id')
            ->join('units', 'units.id', '=', 'products.base_unit_id')->where('stock_balances.tenant_id', $tenant->id)->where('stock_balances.outlet_id', $outlet->id)
            ->where('stock_balances.quantity_on_hand', '>', 0)->where('products.track_stock', true)->orderBy('products.name')
            ->select('product_variants.id as variant_id', 'products.name', 'products.sku', 'units.symbol as unit_symbol', 'stock_balances.quantity_on_hand')->get();

        return view('stock-transfers.create', ['destinations' => $destinations, 'rows' => $rows]);
    }

    public function store(Request $request): RedirectResponse
    {
        $tenant = $request->attributes->get('tenant');
        $outlet = $request->attributes->get('outlet');
        $data = $request->validate([
            'to_outlet_id' => ['required', 'uuid'], 'notes' => ['nullable', 'string', 'max:500'],
            'items' => ['required', 'array', 'max:200'], 'items.*' => ['nullable', 'numeric', 'min:0', 'max:999999999'],
        ]);
        abort_unless($data['to_outlet_id'] !== $outlet->id && DB::table('outlets')->where('tenant_id', $tenant->id)->where('status', 'active')->where('id', $data['to_outlet_id'])->exists(), 422, 'Outlet tujuan tidak valid.');
        $entered = collect($data['items'])->filter(fn ($quantity) => (float) $quantity > 0);
        if ($entered->isEmpty()) {
            throw ValidationException::withMessages(['items' => 'Isi jumlah transfer minimal satu barang.']);
        }

        $transferId = DB::transaction(function () use ($tenant, $outlet, $data, $entered) {
            $now = now($tenant->timezone);
            $transferId = (string) Str::uuid();
            $number = $this->nextNumber($tenant->id, $outlet->id, $now);
            DB::table('stock_transfers')->insert([
                'id' => $transferId, 'tenant_id' => $tenant->id, 'from_outlet_id' => $outlet->id, 'to_outlet_id' => $data['to_outlet_id'], 'transfer_number' => $number,
                'status' => 'in_transit', 'notes' => $data['notes'] ?? null, 'sent_at' => $now, 'sent_by_user_id' => auth()->id(), 'created_at' => $now, 'updated_at' => $now,
            ]);
            $total = 0.0;
            foreach ($entered as $variantId => $quantity) {
                $quantity = round((float) $quantity, 4);
                $balance = DB::table('stock_balances')->where('tenant_id', $tenant->id)->where('outlet_id', $outlet->id)->where('product_variant_id', $variantId)->lockForUpdate()->first();
                if (! $balance || (float) $balance->quantity_on_hand + 0.00001 < $quantity) {
                    throw ValidationException::withMessages(['items' => 'Stok sumber tidak cukup untuk salah satu barang. Muat ulang halaman.']);
                }
                $cost = (float) $balance->average_cost;
                $value = round($quantity * $cost, 2);
                $total += $value;
                $itemId = (string) Str::uuid();
                DB::table('stock_transfer_items')->insert(['id' => $itemId, 'tenant_id' => $tenant->id, 'stock_transfer_id' => $transferId, 'product_variant_id' => $variantId, 'quantity' => $quantity, 'unit_cost' => $cost, 'created_at' => $now, 'updated_at' => $now]);
                $this->moveStock($tenant->id, $outlet->id, $variantId, -$quantity, $cost, -$value, 'transfer_out', $transferId, $itemId, 'transfer-out:'.$itemId, $number, $now);
            }
            $total = round($total, 2);
            DB::table('stock_transfers')->where('id', $transferId)->update(['total_value' => $total]);
            $this->journal($tenant->id, $outlet->id, 'JTO-'.$number, 'Transfer stok keluar '.$number, $transferId, 'transfer-out', [['1110', $total, 0], ['1100', 0, $total]], $now);
            $this->audit($tenant->id, $outlet->id, 'stock_transfer.sent', $transferId, $now);

            return $transferId;
        }, attempts: 3);

        return redirect()->route('stock-transfers.show', $transferId)->with('success', 'Transfer dikirim. Stok sumber sudah berkurang dan barang berstatus dalam perjalanan.');
    }

    public function show(Request $request, string $id): View
    {
        $tenant = $request->attributes->get('tenant');
        $outlet = $request->attributes->get('outlet');
        $transfer = $this->find($tenant->id, $outlet->id, $id);
        $items = DB::table('stock_transfer_items')->join('product_variants', 'product_variants.id', '=', 'stock_transfer_items.product_variant_id')->join('products', 'products.id', '=', 'product_variants.product_id')
            ->where('stock_transfer_items.stock_transfer_id', $id)->orderBy('products.name')->select('stock_transfer_items.*', 'products.name', 'products.sku')->get();
        $names = DB::table('outlets')->whereIn('id', [$transfer->from_outlet_id, $transfer->to_outlet_id])->pluck('name', 'id');

        return view('stock-transfers.show', ['transfer' => $transfer, 'items' => $items, 'outlet' => $outlet, 'names' => $names]);
    }

    public function receive(Request $request, string $id): RedirectResponse
    {
        $tenant = $request->attributes->get('tenant');
        $outlet = $request->attributes->get('outlet');
        $data = $request->validate(['received' => ['nullable', 'array'], 'received.*' => ['nullable', 'numeric', 'min:0', 'max:999999999']]);

        DB::transaction(function () use ($tenant, $outlet, $id, $data) {
            $transfer = DB::table('stock_transfers')->where('tenant_id', $tenant->id)->where('id', $id)->lockForUpdate()->first();
            abort_unless($transfer && $transfer->status === 'in_transit' && $transfer->to_outlet_id === $outlet->id, 422, 'Transfer ini tidak bisa diterima di outlet aktif.');
            $now = now($tenant->timezone);
            $receivedValue = 0.0;
            $shortageValue = 0.0;
            foreach (DB::table('stock_transfer_items')->where('stock_transfer_id', $id)->get() as $item) {
                $quantity = round(min((float) ($data['received'][$item->id] ?? $item->quantity), (float) $item->quantity), 4);
                $cost = (float) $item->unit_cost;
                DB::table('stock_transfer_items')->where('id', $item->id)->update(['received_quantity' => $quantity, 'updated_at' => $now]);
                if ($quantity > 0) {
                    $value = round($quantity * $cost, 2);
                    $receivedValue += $value;
                    $this->moveStock($tenant->id, $outlet->id, $item->product_variant_id, $quantity, $cost, $value, 'transfer_in', $id, $item->id, 'transfer-in:'.$item->id, $transfer->transfer_number, $now);
                }
                $shortageValue += round(((float) $item->quantity - $quantity) * $cost, 2);
            }
            $receivedValue = round($receivedValue, 2);
            $shortageValue = round($shortageValue, 2);
            $lines = [['1100', $receivedValue, 0], ['5020', $shortageValue, 0], ['1110', 0, round($receivedValue + $shortageValue, 2)]];
            $this->journal($tenant->id, $outlet->id, 'JTI-'.$transfer->transfer_number, 'Transfer stok masuk '.$transfer->transfer_number, $id, 'transfer-in', $lines, $now);
            DB::table('stock_transfers')->where('id', $id)->update(['status' => 'received', 'received_at' => $now, 'received_by_user_id' => auth()->id(), 'shortage_value' => $shortageValue, 'updated_at' => $now]);
            $this->audit($tenant->id, $outlet->id, 'stock_transfer.received', $id, $now);
        }, attempts: 3);

        return back()->with('success', 'Transfer diterima dan stok outlet ini bertambah.');
    }

    public function cancel(Request $request, string $id): RedirectResponse
    {
        $tenant = $request->attributes->get('tenant');
        $outlet = $request->attributes->get('outlet');

        DB::transaction(function () use ($tenant, $outlet, $id) {
            $transfer = DB::table('stock_transfers')->where('tenant_id', $tenant->id)->where('id', $id)->lockForUpdate()->first();
            abort_unless($transfer && $transfer->status === 'in_transit' && $transfer->from_outlet_id === $outlet->id, 422, 'Hanya outlet pengirim yang bisa membatalkan transfer yang masih dalam perjalanan.');
            $now = now($tenant->timezone);
            foreach (DB::table('stock_transfer_items')->where('stock_transfer_id', $id)->get() as $item) {
                $value = round((float) $item->quantity * (float) $item->unit_cost, 2);
                $this->moveStock($tenant->id, $outlet->id, $item->product_variant_id, (float) $item->quantity, (float) $item->unit_cost, $value, 'transfer_cancel', $id, $item->id, 'transfer-cancel:'.$item->id, $transfer->transfer_number, $now);
            }
            $total = round((float) $transfer->total_value, 2);
            $this->journal($tenant->id, $outlet->id, 'JTC-'.$transfer->transfer_number, 'Pembatalan transfer '.$transfer->transfer_number, $id, 'transfer-cancel', [['1100', $total, 0], ['1110', 0, $total]], $now);
            DB::table('stock_transfers')->where('id', $id)->update(['status' => 'cancelled', 'cancelled_at' => $now, 'updated_at' => $now]);
            $this->audit($tenant->id, $outlet->id, 'stock_transfer.cancelled', $id, $now);
        }, attempts: 3);

        return back()->with('success', 'Transfer dibatalkan dan stok dikembalikan ke outlet pengirim.');
    }

    private function audit(string $tenantId, string $outletId, string $action, string $transferId, $now): void
    {
        DB::table('audit_logs')->insert([
            'id' => (string) Str::uuid(), 'tenant_id' => $tenantId, 'outlet_id' => $outletId, 'actor_user_id' => auth()->id(), 'action' => $action,
            'subject_type' => 'stock_transfer', 'subject_id' => $transferId, 'occurred_at' => $now,
        ]);

    }

    private function find(string $tenantId, string $outletId, string $id): object
    {
        $transfer = DB::table('stock_transfers')->where('tenant_id', $tenantId)->where('id', $id)->where(fn ($query) => $query->where('from_outlet_id', $outletId)->orWhere('to_outlet_id', $outletId))->first();
        abort_unless($transfer, 404);

        return $transfer;
    }

    private function moveStock(string $tenantId, string $outletId, string $variantId, float $quantity, float $unitCost, float $value, string $type, string $transferId, string $transferItemId, string $key, string $number, $now): void
    {
        $balance = DB::table('stock_balances')->where('tenant_id', $tenantId)->where('outlet_id', $outletId)->where('product_variant_id', $variantId)->lockForUpdate()->first();
        DB::table('stock_movements')->insert([
            'id' => (string) Str::uuid(), 'tenant_id' => $tenantId, 'outlet_id' => $outletId, 'product_variant_id' => $variantId, 'occurred_at' => $now, 'movement_type' => $type,
            'quantity_delta' => $quantity, 'unit_cost' => $unitCost, 'value_delta' => $value, 'source_type' => 'stock_transfer', 'source_id' => $transferId, 'source_line_id' => $transferItemId,
            'idempotency_key' => $key, 'created_by_user_id' => auth()->id(), 'reason' => 'Transfer '.$number, 'created_at' => $now, 'updated_at' => $now,
        ]);
        $newQty = round((float) ($balance->quantity_on_hand ?? 0) + $quantity, 4);
        $newValue = max(0.0, round((float) ($balance->inventory_value ?? 0) + $value, 2));
        $data = ['quantity_on_hand' => $newQty, 'inventory_value' => $newValue, 'average_cost' => $newQty > 0 ? round($newValue / $newQty, 2) : ($balance->average_cost ?? $unitCost), 'updated_at' => $now];
        if ($balance) {
            DB::table('stock_balances')->where('id', $balance->id)->update($data + ['version' => $balance->version + 1]);
        } else {
            DB::table('stock_balances')->insert($data + ['id' => (string) Str::uuid(), 'tenant_id' => $tenantId, 'outlet_id' => $outletId, 'product_variant_id' => $variantId, 'version' => 1, 'created_at' => $now]);
        }
    }

    /**
     * @param  array<int, array{0: string, 1: float, 2: float}>  $lines
     */
    private function journal(string $tenantId, string $outletId, string $entryNumber, string $description, string $transferId, string $keyPrefix, array $lines, $now): void
    {
        $lines = array_values(array_filter($lines, fn ($line) => $line[1] > 0 || $line[2] > 0));
        if ($lines === []) {
            return;
        }
        $entryId = (string) Str::uuid();
        DB::table('journal_entries')->insert([
            'id' => $entryId, 'tenant_id' => $tenantId, 'outlet_id' => $outletId, 'entry_number' => $entryNumber, 'entry_date' => $now->toDateString(), 'status' => 'posted',
            'description' => $description, 'source_type' => 'stock_transfer', 'source_id' => $transferId, 'idempotency_key' => $keyPrefix.':'.$transferId, 'posted_at' => $now,
            'posted_by_user_id' => auth()->id(), 'created_by_user_id' => auth()->id(), 'created_at' => $now, 'updated_at' => $now,
        ]);
        foreach ($lines as [$code, $debit, $credit]) {
            DB::table('journal_lines')->insert([
                'id' => (string) Str::uuid(), 'tenant_id' => $tenantId, 'journal_entry_id' => $entryId, 'account_id' => $this->accountId($tenantId, $code, $now), 'outlet_id' => $outletId,
                'description' => $description, 'debit' => round($debit, 2), 'credit' => round($credit, 2), 'created_at' => $now, 'updated_at' => $now,
            ]);
        }
    }

    private function accountId(string $tenantId, string $code, $now): string
    {
        $account = DB::table('chart_of_accounts')->where('tenant_id', $tenantId)->where('code', $code)->first();
        if ($account) {
            return $account->id;
        }
        $id = (string) Str::uuid();
        DB::table('chart_of_accounts')->insert([
            'id' => $id, 'tenant_id' => $tenantId, 'code' => $code, 'name' => 'Persediaan Dalam Perjalanan', 'account_type' => 'asset', 'normal_balance' => 'debit',
            'is_system' => true, 'allow_manual_posting' => false, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now,
        ]);

        return $id;
    }

    private function nextNumber(string $tenantId, string $outletId, $now): string
    {
        $scope = ['tenant_id' => $tenantId, 'outlet_id' => $outletId, 'document_type' => 'stock_transfer', 'period_key' => $now->format('Y')];
        $counter = DB::table('document_counters')->where($scope)->lockForUpdate()->first();
        if (! $counter) {
            DB::table('document_counters')->insert($scope + ['id' => (string) Str::uuid(), 'prefix' => 'TRF', 'next_number' => 1, 'padding' => 5, 'created_at' => $now, 'updated_at' => $now]);
            $counter = DB::table('document_counters')->where($scope)->lockForUpdate()->first();
        }
        DB::table('document_counters')->where('id', $counter->id)->update(['next_number' => $counter->next_number + 1, 'updated_at' => $now]);
        $outletCode = DB::table('outlets')->where('id', $outletId)->value('code');

        return $counter->prefix.'/'.$outletCode.'/'.$scope['period_key'].'/'.str_pad((string) $counter->next_number, $counter->padding, '0', STR_PAD_LEFT);
    }
}
