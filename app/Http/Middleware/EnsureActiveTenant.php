<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

class EnsureActiveTenant
{
    public function handle(Request $request, Closure $next): Response
    {
        $membership = DB::table('tenant_user')->where('user_id', Auth::id())->where('status', 'active')->first();
        abort_unless($membership, 403, 'Akun ini belum tergabung ke usaha mana pun.');

        $tenantId = session('active_tenant_id', $membership->tenant_id);
        $membership = DB::table('tenant_user')->where('tenant_id', $tenantId)
            ->where('user_id', Auth::id())->where('status', 'active')->first();
        abort_unless($membership, 403, 'Akses ke usaha ini tidak tersedia.');

        $tenant = DB::table('tenants')->where('id', $tenantId)->where('status', 'active')->first();
        abort_unless($tenant, 403, 'Usaha sedang tidak aktif.');

        $outletId = session('active_outlet_id');
        $allowedOutlets = DB::table('user_outlet')->where('tenant_user_id', $membership->id)
            ->where('status', 'active')->pluck('outlet_id');
        $outlet = DB::table('outlets')->where('tenant_id', $tenantId)->where('status', 'active')
            ->when($outletId, fn ($query) => $query->where('id', $outletId))
            ->when($allowedOutlets->isNotEmpty(), fn ($query) => $query->whereIn('id', $allowedOutlets))
            ->orderByDesc('is_default')->first();
        abort_unless($outlet, 403, 'Outlet aktif tidak ditemukan.');

        session(['active_tenant_id' => $tenantId, 'active_outlet_id' => $outlet->id]);
        $role = DB::table('tenant_user_roles')->join('roles', 'roles.id', '=', 'tenant_user_roles.role_id')
            ->where('tenant_user_roles.tenant_user_id', $membership->id)->orderByRaw("CASE roles.key WHEN 'owner' THEN 0 ELSE 1 END")->first();
        abort_unless($role, 403, 'Peran akun belum diatur.');
        view()->share('currentRole', $role);
        $request->attributes->set('role_key', $role->key);
        view()->share('currentTenant', $tenant);
        view()->share('currentOutlet', $outlet);
        $request->attributes->set('tenant', $tenant);
        $request->attributes->set('outlet', $outlet);
        $request->attributes->set('tenant_membership', $membership);

        return $next($request);
    }
}
