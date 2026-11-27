<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\View\View;

class PlatformController extends Controller
{
    public const PLANS = ['free' => 'Gratis', 'starter' => 'Starter', 'pro' => 'Pro', 'business' => 'Business'];

    public function dashboard(): View
    {
        $since = now()->subDays(30);
        $byStatus = DB::table('tenants')->whereNull('deleted_at')->select('status', DB::raw('count(*) as total'))->groupBy('status')->pluck('total', 'status');
        $byPlan = DB::table('tenants')->whereNull('deleted_at')->select('plan', DB::raw('count(*) as total'))->groupBy('plan')->pluck('total', 'plan');
        $stats = [
            'tenants' => (int) $byStatus->sum(),
            'active' => (int) ($byStatus['active'] ?? 0),
            'suspended' => (int) ($byStatus['suspended'] ?? 0),
            'newTenants' => DB::table('tenants')->whereNull('deleted_at')->where('created_at', '>=', $since)->count(),
            'activeUsers' => DB::table('users')->where('status', 'active')->where('last_login_at', '>=', $since)->count(),
            'sales30d' => DB::table('sales')->where('status', 'completed')->where('sold_at', '>=', $since)->count(),
            'openTickets' => DB::table('support_tickets')->whereIn('status', ['open', 'in_progress'])->count(),
        ];
        $growth = DB::table('tenants')->whereNull('deleted_at')->where('created_at', '>=', now()->subMonths(5)->startOfMonth())
            ->get(['created_at'])->groupBy(fn ($row) => substr((string) $row->created_at, 0, 7))->map->count()->sortKeys();
        $latest = DB::table('tenants')->whereNull('deleted_at')->orderByDesc('created_at')->limit(8)->get();

        return view('platform.dashboard', ['stats' => $stats, 'byPlan' => $byPlan, 'growth' => $growth, 'latest' => $latest, 'plans' => self::PLANS]);
    }

    public function tenants(Request $request): View
    {
        $search = trim((string) $request->query('q', ''));
        $status = (string) $request->query('status', '');
        $plan = (string) $request->query('plan', '');
        $tenants = DB::table('tenants')->leftJoin('users', 'users.id', '=', 'tenants.created_by_user_id')->whereNull('tenants.deleted_at')
            ->when($search !== '', fn ($q) => $q->where(fn ($w) => $w->where('tenants.name', 'like', "%{$search}%")->orWhere('tenants.phone', 'like', "%{$search}%")->orWhere('tenants.email', 'like', "%{$search}%")))
            ->when(in_array($status, ['active', 'suspended'], true), fn ($q) => $q->where('tenants.status', $status))
            ->when(array_key_exists($plan, self::PLANS), fn ($q) => $q->where('tenants.plan', $plan))
            ->orderByDesc('tenants.created_at')
            ->select('tenants.*', 'users.name as owner_name')->paginate(25)->withQueryString();

        return view('platform.tenants', ['tenants' => $tenants, 'search' => $search, 'status' => $status, 'plan' => $plan, 'plans' => self::PLANS]);
    }

    public function show(string $tenantId): View
    {
        $tenant = DB::table('tenants')->leftJoin('users', 'users.id', '=', 'tenants.created_by_user_id')->where('tenants.id', $tenantId)->whereNull('tenants.deleted_at')
            ->select('tenants.*', 'users.name as owner_name', 'users.email as owner_email', 'users.phone as owner_phone')->first();
        abort_unless($tenant, 404);
        $since = now()->subDays(30);
        $usage = [
            'outlets' => DB::table('outlets')->where('tenant_id', $tenantId)->whereNull('deleted_at')->count(),
            'members' => DB::table('tenant_user')->where('tenant_id', $tenantId)->where('status', 'active')->count(),
            'products' => DB::table('products')->where('tenant_id', $tenantId)->count(),
            'sales30d' => DB::table('sales')->where('tenant_id', $tenantId)->where('status', 'completed')->where('sold_at', '>=', $since)->count(),
            'lastSaleAt' => DB::table('sales')->where('tenant_id', $tenantId)->where('status', 'completed')->max('sold_at'),
        ];
        $logs = DB::table('audit_logs')->leftJoin('users', 'users.id', '=', 'audit_logs.actor_user_id')->where('audit_logs.tenant_id', $tenantId)
            ->where('audit_logs.action', 'like', 'platform.%')->orderByDesc('audit_logs.occurred_at')->limit(20)->select('audit_logs.*', 'users.name as actor_name')->get();
        $tickets = DB::table('support_tickets')->where('tenant_id', $tenantId)->orderByDesc('last_message_at')->limit(10)->get();

        return view('platform.show', ['tenant' => $tenant, 'usage' => $usage, 'logs' => $logs, 'tickets' => $tickets, 'plans' => self::PLANS]);
    }

    public function updatePlan(Request $request, string $tenantId): RedirectResponse
    {
        $tenant = DB::table('tenants')->where('id', $tenantId)->whereNull('deleted_at')->first();
        abort_unless($tenant, 404);
        $data = $request->validate([
            'plan' => ['required', 'in:'.implode(',', array_keys(self::PLANS))],
            'plan_expires_at' => ['nullable', 'date'],
            'reason' => ['required', 'string', 'max:500'],
        ]);
        $expires = $data['plan_expires_at'] ?? null;
        DB::table('tenants')->where('id', $tenantId)->update(['plan' => $data['plan'], 'plan_expires_at' => $expires, 'updated_at' => now()]);
        $this->audit($request, $tenantId, 'platform.plan_changed', ['plan' => $tenant->plan, 'plan_expires_at' => $tenant->plan_expires_at], ['plan' => $data['plan'], 'plan_expires_at' => $expires], $data['reason']);

        return back()->with('success', 'Paket usaha diperbarui.');
    }

    public function suspend(Request $request, string $tenantId): RedirectResponse
    {
        $tenant = DB::table('tenants')->where('id', $tenantId)->whereNull('deleted_at')->first();
        abort_unless($tenant, 404);
        $data = $request->validate(['reason' => ['required', 'string', 'max:500']]);
        DB::table('tenants')->where('id', $tenantId)->update(['status' => 'suspended', 'suspended_reason' => $data['reason'], 'updated_at' => now()]);
        $this->audit($request, $tenantId, 'platform.suspended', ['status' => $tenant->status], ['status' => 'suspended'], $data['reason']);

        return back()->with('success', 'Usaha ditangguhkan. Pengguna usaha ini tidak bisa masuk ke kasir sampai diaktifkan lagi.');
    }

    public function activate(Request $request, string $tenantId): RedirectResponse
    {
        $tenant = DB::table('tenants')->where('id', $tenantId)->whereNull('deleted_at')->first();
        abort_unless($tenant, 404);
        $data = $request->validate(['reason' => ['required', 'string', 'max:500']]);
        DB::table('tenants')->where('id', $tenantId)->update(['status' => 'active', 'suspended_reason' => null, 'updated_at' => now()]);
        $this->audit($request, $tenantId, 'platform.activated', ['status' => $tenant->status], ['status' => 'active'], $data['reason']);

        return back()->with('success', 'Usaha diaktifkan kembali.');
    }

    public function logs(Request $request): View
    {
        $action = (string) $request->query('action', '');
        $logs = DB::table('audit_logs')->leftJoin('users', 'users.id', '=', 'audit_logs.actor_user_id')->leftJoin('tenants', 'tenants.id', '=', 'audit_logs.tenant_id')
            ->where('audit_logs.action', 'like', 'platform.%')
            ->when($action !== '', fn ($q) => $q->where('audit_logs.action', $action))
            ->orderByDesc('audit_logs.occurred_at')
            ->select('audit_logs.*', 'users.name as actor_name', 'tenants.name as tenant_name')->paginate(30)->withQueryString();

        return view('platform.logs', ['logs' => $logs, 'action' => $action]);
    }

    /**
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     */
    private function audit(Request $request, string $tenantId, string $action, array $before, array $after, string $reason): void
    {
        DB::table('audit_logs')->insert([
            'id' => (string) Str::uuid(), 'tenant_id' => $tenantId, 'actor_user_id' => auth()->id(), 'action' => $action,
            'subject_type' => 'tenant', 'subject_id' => $tenantId, 'before' => json_encode($before), 'after' => json_encode($after),
            'reason' => $reason, 'ip_address' => $request->ip(), 'occurred_at' => now(),
        ]);
    }
}
