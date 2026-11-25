<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class StockCountController extends Controller
{
    public function index(Request $request): View
    {
        $tenant = $request->attributes->get('tenant');
        $outlet = $request->attributes->get('outlet');
        $counts = DB::table('stock_counts')->where('tenant_id', $tenant->id)->where('outlet_id', $outlet->id)
            ->orderByDesc('created_at')->limit(30)->get()->map(function ($count) {
                $items = DB::table('stock_count_items')->where('stock_count_id', $count->id)->get();
                $count->item_count = $items->count();
                $count->variance_items = $items->filter(fn ($item) => abs((float) $item->variance) > 0.00001)->count();
                $count->variance_value = round($items->sum(fn ($item) => (float) $item->variance * (float) $item->unit_cost_snapshot), 2);

                return $count;
            });

        return view('stock-counts.index', ['counts' => $counts]);
    }

    public function create(Request $request): View
    {
        $tenant = $request->attributes->get('tenant');
        $outlet = $request->attributes->get('outlet');
        $rows = DB::table('product_variants')->join('products', 'products.id', '=', 'product_variants.product_id')
            ->join('units', 'units.id', '=', 'products.base_unit_id')
            ->leftJoin('stock_balances', fn ($join) => $join->on('stock_balances.product_variant_id', '=', 'product_variants.id')->where('stock_balances.outlet_id', $outlet->id))
            ->where('products.tenant_id', $tenant->id)->where('products.is_active', true)->where('products.track_stock', true)
            ->where('product_variants.is_active', true)->whereNull('products.deleted_at')->orderBy('products.name')
            ->select('product_variants.id as variant_id', 'products.name', 'products.sku', 'units.symbol as unit_symbol',
                DB::raw('coalesce(stock_balances.quantity_on_hand, 0) as system_quantity'))->get();

        return view('stock-counts.create', ['rows' => $rows]);
    }

    public function store(Request $request): RedirectResponse
    {
        $tenant = $request->attributes->get('tenant');
        $outlet = $request->attributes->get('outlet');
        $data = $request->validate([
            'notes' => ['nullable', 'string', 'max:1000'],
            'counted' => ['required', 'array', 'max:2000'], 'counted.*' => ['nullable', 'numeric', 'min:0', 'max:999999999'],
        ]);
        $entered = collect($data['counted'])->filter(fn ($value) => $value !== null && $value !== '');
        if ($entered->isEmpty()) {
            throw ValidationException::withMessages(['counted' => 'Isi jumlah fisik minimal satu produk.']);
        }

        $countId = DB::transaction(function () use ($tenant, $outlet, $entered, $data) {
            $now = now($tenant->timezone);
            $countId = (string) Str::uuid();
            DB::table('stock_counts')->insert([
                'id' => $countId, 'tenant_id' => $tenant->id, 'outlet_id' => $outlet->id, 'count_number' => $this->nextNumber($tenant->id, $outlet->id, $now),
                'status' => 'submitted', 'started_at' => $now, 'submitted_at' => $now, 'notes' => $data['notes'] ?? null, 'created_at' => $now, 'updated_at' => $now,
            ]);
            foreach ($entered as $variantId => $counted) {
                $variant = DB::table('product_variants')->join('products', 'products.id', '=', 'product_variants.product_id')
                    ->where('product_variants.id', $variantId)->where('products.tenant_id', $tenant->id)->where('products.track_stock', true)
                    ->select('product_variants.id', 'product_variants.cost_price')->first();
                if (! $variant) {
                    throw ValidationException::withMessages(['counted' => 'Ada produk yang tidak valid. Muat ulang halaman.']);
                }
                $balance = DB::table('stock_balances')->where('tenant_id', $tenant->id)->where('outlet_id', $outlet->id)->where('product_variant_id', $variantId)->first();
                $system = (float) ($balance->quantity_on_hand ?? 0);
                $cost = (float) ($balance->average_cost ?? 0) > 0 ? (float) $balance->average_cost : (float) ($variant->cost_price ?? 0);
                DB::table('stock_count_items')->insert([
                    'id' => (string) Str::uuid(), 'tenant_id' => $tenant->id, 'stock_count_id' => $countId, 'product_variant_id' => $variantId,
                    'system_quantity_snapshot' => $system, 'counted_quantity' => (float) $counted, 'variance' => round((float) $counted - $system, 4),
                    'unit_cost_snapshot' => $cost, 'created_at' => $now, 'updated_at' => $now,
                ]);
            }
            $this->audit($tenant->id, $outlet->id, 'stock_count.submitted', $countId, $now);

            return $countId;
        });

        return redirect()->route('stock-counts.show', $countId)->with('success', 'Opname dikirim. Pemilik atau manajer perlu menyetujui sebelum stok berubah.');
    }

    public function show(Request $request, string $id): View
    {
        $tenant = $request->attributes->get('tenant');
        $count = DB::table('stock_counts')->where('tenant_id', $tenant->id)->where('id', $id)->first();
        abort_unless($count, 404);
        $items = DB::table('stock_count_items')->join('product_variants', 'product_variants.id', '=', 'stock_count_items.product_variant_id')
            ->join('products', 'products.id', '=', 'product_variants.product_id')->where('stock_count_items.stock_count_id', $id)
            ->orderBy('products.name')->select('stock_count_items.*', 'products.name', 'products.sku')->get();

        return view('stock-counts.show', ['count' => $count, 'items' => $items]);
    }

    public function approve(Request $request, string $id): RedirectResponse
    {
        $tenant = $request->attributes->get('tenant');
        $outlet = $request->attributes->get('outlet');

        DB::transaction(function () use ($tenant, $outlet, $id) {
            $count = DB::table('stock_counts')->where('tenant_id', $tenant->id)->where('outlet_id', $outlet->id)->where('id', $id)->lockForUpdate()->first();
            abort_unless($count && $count->status === 'submitted', 422, 'Opname ini tidak bisa disetujui.');
            $now = now($tenant->timezone);
            $lines = [];
            $net = 0.0;
            foreach (DB::table('stock_count_items')->where('stock_count_id', $id)->get() as $item) {
                $delta = round((float) $item->variance, 4);
                if (abs($delta) < 0.00001) {
                    continue;
                }
                $balance = DB::table('stock_balances')->where('tenant_id', $tenant->id)->where('outlet_id', $outlet->id)
                    ->where('product_variant_id', $item->product_variant_id)->lockForUpdate()->first();
                $currentQty = (float) ($balance->quantity_on_hand ?? 0);
                $currentValue = (float) ($balance->inventory_value ?? 0);
                $cost = (float) $item->unit_cost_snapshot;
                $newQty = round($currentQty + $delta, 4);
                if ($newQty < 0) {
                    throw ValidationException::withMessages(['count' => 'Stok sistem sudah berubah sejak opname dibuat, hasil penyesuaian akan minus. Buat opname baru.']);
                }
                $value = round($delta * $cost, 2);
                $newValue = max(0.0, round($currentValue + $value, 2));
                DB::table('stock_movements')->insert([
                    'id' => (string) Str::uuid(), 'tenant_id' => $tenant->id, 'outlet_id' => $outlet->id, 'product_variant_id' => $item->product_variant_id,
                    'occurred_at' => $now, 'movement_type' => 'count_adjustment', 'quantity_delta' => $delta, 'unit_cost' => $cost, 'value_delta' => $value,
                    'source_type' => 'stock_count', 'source_id' => $id, 'source_line_id' => $item->id, 'idempotency_key' => 'stock-count:'.$item->id,
                    'created_by_user_id' => auth()->id(), 'reason' => 'Opname '.$count->count_number, 'created_at' => $now, 'updated_at' => $now,
                ]);
                $balanceData = ['quantity_on_hand' => $newQty, 'inventory_value' => $newValue,
                    'average_cost' => $newQty > 0 ? round($newValue / $newQty, 2) : ($balance->average_cost ?? $cost), 'updated_at' => $now];
                if ($balance) {
                    DB::table('stock_balances')->where('id', $balance->id)->update($balanceData + ['version' => $balance->version + 1]);
                } else {
                    DB::table('stock_balances')->insert($balanceData + ['id' => (string) Str::uuid(), 'tenant_id' => $tenant->id, 'outlet_id' => $outlet->id,
                        'product_variant_id' => $item->product_variant_id, 'version' => 1, 'created_at' => $now]);
                }
                $lines[] = [$item->product_variant_id, $value];
                $net += $value;
            }

            if ($lines !== []) {
                $inventory = $this->account($tenant->id, '1100', 'Persediaan', 'asset', 'debit', $now);
                $variance = $this->account($tenant->id, '5020', 'Selisih Persediaan', 'expense', 'debit', $now);
                $entryId = (string) Str::uuid();
                DB::table('journal_entries')->insert([
                    'id' => $entryId, 'tenant_id' => $tenant->id, 'outlet_id' => $outlet->id, 'entry_number' => 'ADJ-'.$count->count_number,
                    'entry_date' => $now->toDateString(), 'status' => 'posted', 'description' => 'Selisih opname '.$count->count_number,
                    'source_type' => 'stock_count', 'source_id' => $id, 'idempotency_key' => 'stock-count:'.$id.':journal', 'posted_at' => $now,
                    'posted_by_user_id' => auth()->id(), 'created_by_user_id' => auth()->id(), 'created_at' => $now, 'updated_at' => $now,
                ]);
                foreach ($lines as [$variantId, $value]) {
                    $this->line($tenant->id, $entryId, $inventory, $value > 0 ? $value : 0, $value < 0 ? -$value : 0, $variantId, $count->count_number, $now);
                    $this->line($tenant->id, $entryId, $variance, $value < 0 ? -$value : 0, $value > 0 ? $value : 0, $variantId, $count->count_number, $now);
                }
            }

            DB::table('stock_counts')->where('id', $id)->update(['status' => 'approved', 'approved_at' => $now, 'approved_by_user_id' => auth()->id(), 'updated_at' => $now]);
            $this->audit($tenant->id, $outlet->id, 'stock_count.approved', $id, $now);
        }, attempts: 3);

        return back()->with('success', 'Opname disetujui. Stok dan jurnal selisih sudah diperbarui.');
    }

    public function reject(Request $request, string $id): RedirectResponse
    {
        $tenant = $request->attributes->get('tenant');
        $outlet = $request->attributes->get('outlet');
        $updated = DB::table('stock_counts')->where('tenant_id', $tenant->id)->where('outlet_id', $outlet->id)->where('id', $id)->where('status', 'submitted')
            ->update(['status' => 'rejected', 'updated_at' => now()]);
        abort_unless($updated, 422, 'Opname ini tidak bisa ditolak.');
        $this->audit($tenant->id, $outlet->id, 'stock_count.rejected', $id, now());

        return back()->with('success', 'Opname ditolak. Stok tidak berubah.');
    }

    private function line(string $tenantId, string $entryId, string $accountId, float $debit, float $credit, string $variantId, string $number, $now): void
    {
        DB::table('journal_lines')->insert([
            'id' => (string) Str::uuid(), 'tenant_id' => $tenantId, 'journal_entry_id' => $entryId, 'account_id' => $accountId, 'product_variant_id' => $variantId,
            'description' => 'Selisih opname '.$number, 'debit' => round($debit, 2), 'credit' => round($credit, 2), 'created_at' => $now, 'updated_at' => $now,
        ]);
    }

    private function account(string $tenantId, string $code, string $name, string $type, string $normal, $now): string
    {
        $account = DB::table('chart_of_accounts')->where('tenant_id', $tenantId)->where('code', $code)->first();
        if ($account) {
            return $account->id;
        }
        $id = (string) Str::uuid();
        DB::table('chart_of_accounts')->insert([
            'id' => $id, 'tenant_id' => $tenantId, 'code' => $code, 'name' => $name, 'account_type' => $type, 'normal_balance' => $normal,
            'is_system' => true, 'allow_manual_posting' => false, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now,
        ]);

        return $id;
    }

    private function nextNumber(string $tenantId, string $outletId, $now): string
    {
        $scope = ['tenant_id' => $tenantId, 'outlet_id' => $outletId, 'document_type' => 'stock_count', 'period_key' => $now->format('Y')];
        $counter = DB::table('document_counters')->where($scope)->lockForUpdate()->first();
        if (! $counter) {
            DB::table('document_counters')->insert($scope + ['id' => (string) Str::uuid(), 'prefix' => 'STO', 'next_number' => 1, 'padding' => 5, 'created_at' => $now, 'updated_at' => $now]);
            $counter = DB::table('document_counters')->where($scope)->lockForUpdate()->first();
        }
        DB::table('document_counters')->where('id', $counter->id)->update(['next_number' => $counter->next_number + 1, 'updated_at' => $now]);

        return $counter->prefix.'/'.$scope['period_key'].'/'.str_pad((string) $counter->next_number, $counter->padding, '0', STR_PAD_LEFT);
    }

    private function audit(string $tenantId, string $outletId, string $action, string $subjectId, $now): void
    {
        DB::table('audit_logs')->insert([
            'id' => (string) Str::uuid(), 'tenant_id' => $tenantId, 'outlet_id' => $outletId, 'actor_user_id' => auth()->id(), 'action' => $action,
            'subject_type' => 'stock_count', 'subject_id' => $subjectId, 'after' => json_encode(['id' => $subjectId]), 'occurred_at' => $now, 'created_at' => $now,
        ]);
    }
}
