<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\View\View;

class CustomerController extends Controller
{
    public const BUCKETS = ['d30' => '0–30 hari', 'd60' => '31–60 hari', 'd90' => '61–90 hari', 'over' => '> 90 hari'];

    public function index(Request $request): View
    {
        $tenant = $request->attributes->get('tenant');
        $customers = DB::table('customers')->where('tenant_id', $tenant->id)->whereNull('deleted_at')
            ->orderBy('name')->paginate(20);
        $outstanding = self::outstandingTotals($tenant->id);

        return view('customers.index', compact('customers', 'outstanding'));
    }

    public function store(Request $request): RedirectResponse
    {
        $tenant = $request->attributes->get('tenant');
        $data = $request->validate([
            'name' => ['required', 'string', 'max:160'], 'phone' => ['nullable', 'string', 'max:32'],
            'email' => ['nullable', 'email', 'max:190'], 'address' => ['nullable', 'string', 'max:1000'],
            'credit_limit' => ['nullable', 'numeric', 'min:0', 'max:99999999999999'],
        ]);
        DB::table('customers')->insert([
            'id' => (string) Str::uuid(), 'tenant_id' => $tenant->id, 'name' => $data['name'],
            'phone' => $data['phone'] ?? null, 'email' => $data['email'] ?? null, 'address' => $data['address'] ?? null,
            'credit_limit' => ($data['credit_limit'] ?? '') === '' ? null : $data['credit_limit'],
            'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);

        return back()->with('success', 'Pelanggan berhasil ditambahkan.');
    }

    public function updateCreditLimit(Request $request, string $id): RedirectResponse
    {
        $tenant = $request->attributes->get('tenant');
        $data = $request->validate(['credit_limit' => ['nullable', 'numeric', 'min:0', 'max:99999999999999']]);
        abort_unless(DB::table('customers')->where('tenant_id', $tenant->id)->where('id', $id)->whereNull('deleted_at')->exists(), 404);
        $limit = ($data['credit_limit'] ?? '') === '' ? null : round((float) $data['credit_limit'], 2);
        DB::table('customers')->where('id', $id)->update(['credit_limit' => $limit, 'updated_at' => now()]);
        DB::table('audit_logs')->insert([
            'id' => (string) Str::uuid(), 'tenant_id' => $tenant->id, 'outlet_id' => $request->attributes->get('outlet')->id ?? null, 'actor_user_id' => auth()->id(),
            'action' => 'customer.credit_limit_changed', 'subject_type' => 'customer', 'subject_id' => $id, 'after' => json_encode(['credit_limit' => $limit]),
            'occurred_at' => now(), 'created_at' => now(),
        ]);

        return back()->with('success', 'Batas kredit diperbarui.');
    }

    public function aging(Request $request): View
    {
        $tenant = $request->attributes->get('tenant');
        $today = Carbon::now($tenant->timezone)->startOfDay();
        $sales = self::openReceivables($tenant->id);
        $customers = [];
        $totals = array_fill_keys(array_keys(self::BUCKETS), 0.0);
        foreach ($sales as $sale) {
            $age = max(0, (int) Carbon::parse($sale->sold_at, $tenant->timezone)->startOfDay()->diffInDays($today));
            $sale->age_days = $age;
            $key = match (true) {
                $age <= 30 => 'd30', $age <= 60 => 'd60', $age <= 90 => 'd90', default => 'over',
            };
            $row = $customers[$sale->customer_id] ?? ['name' => $sale->customer_name, 'limit' => $sale->credit_limit, 'buckets' => array_fill_keys(array_keys(self::BUCKETS), 0.0), 'total' => 0.0, 'sales' => []];
            $row['buckets'][$key] += (float) $sale->outstanding;
            $row['total'] += (float) $sale->outstanding;
            $row['sales'][] = $sale;
            $customers[$sale->customer_id] = $row;
            $totals[$key] += (float) $sale->outstanding;
        }
        uasort($customers, fn ($a, $b) => $b['total'] <=> $a['total']);

        return view('customers.aging', ['buckets' => self::BUCKETS, 'customers' => $customers, 'totals' => $totals, 'grandTotal' => array_sum($totals)]);
    }

    /**
     * @return Collection<int, object>
     */
    public static function openReceivables(string $tenantId, ?string $customerId = null): Collection
    {
        $query = DB::table('sales')->join('customers', 'customers.id', '=', 'sales.customer_id')
            ->where('sales.tenant_id', $tenantId)->whereIn('sales.status', ['completed', 'partially_returned'])
            ->whereRaw('(sales.grand_total - sales.returned_total - sales.paid_total) > 0.004')
            ->orderBy('sales.sold_at')
            ->selectRaw('sales.id, sales.sale_number, sales.sold_at, sales.customer_id, customers.name as customer_name, customers.credit_limit, (sales.grand_total - sales.returned_total - sales.paid_total) as outstanding');
        if ($customerId) {
            $query->where('sales.customer_id', $customerId);
        }

        return $query->get();
    }

    /**
     * @return array<string, float>
     */
    public static function outstandingTotals(string $tenantId): array
    {
        return self::openReceivables($tenantId)->groupBy('customer_id')->map(fn ($rows) => round((float) $rows->sum('outstanding'), 2))->all();
    }

    public static function outstandingFor(string $tenantId, string $customerId): float
    {
        return round((float) self::openReceivables($tenantId, $customerId)->sum('outstanding'), 2);
    }
}
