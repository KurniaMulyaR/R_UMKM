<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class OutletController extends Controller
{
    public function index(Request $request): View
    {
        $tenant = $request->attributes->get('tenant');
        $membership = $request->attributes->get('tenant_membership');
        $outlets = DB::table('outlets')->where('tenant_id', $tenant->id)->where('status', 'active')->orderByDesc('is_default')->orderBy('name')->get();
        $allowed = DB::table('user_outlet')->where('tenant_user_id', $membership->id)->where('status', 'active')->pluck('outlet_id')->all();
        $access = DB::table('user_outlet')->join('tenant_user', 'tenant_user.id', '=', 'user_outlet.tenant_user_id')->join('users', 'users.id', '=', 'tenant_user.user_id')
            ->where('tenant_user.tenant_id', $tenant->id)->where('user_outlet.status', 'active')->select('user_outlet.outlet_id', 'user_outlet.tenant_user_id', 'users.name')->get()->groupBy('outlet_id');
        $staff = DB::table('tenant_user')->join('users', 'users.id', '=', 'tenant_user.user_id')->where('tenant_user.tenant_id', $tenant->id)->where('tenant_user.status', 'active')
            ->orderBy('users.name')->get(['tenant_user.id', 'users.name']);

        return view('outlets.index', ['outlets' => $outlets, 'allowed' => $allowed, 'access' => $access, 'staff' => $staff]);
    }

    public function store(Request $request): RedirectResponse
    {
        $tenant = $request->attributes->get('tenant');
        $membership = $request->attributes->get('tenant_membership');
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'], 'code' => ['required', 'regex:/^[A-Za-z0-9-]{2,16}$/'],
            'address' => ['nullable', 'string', 'max:500'], 'phone' => ['nullable', 'string', 'max:32'],
        ]);
        $code = strtoupper($data['code']);
        if (DB::table('outlets')->where('tenant_id', $tenant->id)->where('code', $code)->exists()) {
            throw ValidationException::withMessages(['code' => 'Kode outlet sudah dipakai.']);
        }

        DB::transaction(function () use ($tenant, $membership, $data, $code) {
            $now = now($tenant->timezone);
            $outletId = (string) Str::uuid();
            DB::table('outlets')->insert([
                'id' => $outletId, 'tenant_id' => $tenant->id, 'name' => $data['name'], 'code' => $code, 'address' => $data['address'] ?? null, 'phone' => $data['phone'] ?? null,
                'timezone' => $tenant->timezone, 'is_default' => false, 'status' => 'active', 'created_at' => $now, 'updated_at' => $now,
            ]);
            foreach ([['Kas', 'cash', '1010'], ['Bank', 'bank', '1020'], ['QRIS', 'qris_clearing', '1030']] as [$label, $type, $accountCode]) {
                $chartId = DB::table('chart_of_accounts')->where('tenant_id', $tenant->id)->where('code', $accountCode)->value('id');
                DB::table('cash_accounts')->insert([
                    'id' => (string) Str::uuid(), 'tenant_id' => $tenant->id, 'chart_account_id' => $chartId, 'outlet_id' => $outletId,
                    'name' => $label.' '.$data['name'], 'type' => $type, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now,
                ]);
            }
            DB::table('user_outlet')->insert([
                'id' => (string) Str::uuid(), 'tenant_user_id' => $membership->id, 'outlet_id' => $outletId, 'is_default' => false, 'status' => 'active', 'created_at' => $now, 'updated_at' => $now,
            ]);
            DB::table('audit_logs')->insert([
                'id' => (string) Str::uuid(), 'tenant_id' => $tenant->id, 'outlet_id' => $outletId, 'actor_user_id' => auth()->id(), 'action' => 'outlet.created',
                'subject_type' => 'outlet', 'subject_id' => $outletId, 'after' => json_encode(['name' => $data['name'], 'code' => $code]), 'occurred_at' => $now,
            ]);

        });

        return back()->with('success', 'Outlet ditambahkan. Mulai isi stoknya lewat transfer stok atau pembelian.');
    }

    public function switch(Request $request, string $id): RedirectResponse
    {
        $tenant = $request->attributes->get('tenant');
        $membership = $request->attributes->get('tenant_membership');
        $outlet = DB::table('outlets')->where('tenant_id', $tenant->id)->where('status', 'active')->where('id', $id)->first();
        abort_unless($outlet, 404);
        $restricted = DB::table('user_outlet')->where('tenant_user_id', $membership->id)->where('status', 'active')->pluck('outlet_id');
        abort_if($restricted->isNotEmpty() && ! $restricted->contains($id), 403, 'Anda tidak punya akses ke outlet ini.');
        session(['active_outlet_id' => $id]);

        return redirect()->route('dashboard')->with('success', 'Outlet aktif: '.$outlet->name.'.');
    }

    public function grant(Request $request): RedirectResponse
    {
        $tenant = $request->attributes->get('tenant');
        $data = $request->validate(['outlet_id' => ['required', 'uuid'], 'tenant_user_id' => ['required', 'uuid']]);
        abort_unless(DB::table('outlets')->where('tenant_id', $tenant->id)->where('id', $data['outlet_id'])->exists()
            && DB::table('tenant_user')->where('tenant_id', $tenant->id)->where('id', $data['tenant_user_id'])->exists(), 404);
        $now = now($tenant->timezone);
        DB::table('user_outlet')->updateOrInsert(
            ['tenant_user_id' => $data['tenant_user_id'], 'outlet_id' => $data['outlet_id']],
            ['id' => DB::table('user_outlet')->where('tenant_user_id', $data['tenant_user_id'])->where('outlet_id', $data['outlet_id'])->value('id') ?? (string) Str::uuid(), 'status' => 'active', 'updated_at' => $now, 'created_at' => $now],
        );

        return back()->with('success', 'Akses outlet diberikan.');
    }

    public function revoke(Request $request): RedirectResponse
    {
        $tenant = $request->attributes->get('tenant');
        $data = $request->validate(['outlet_id' => ['required', 'uuid'], 'tenant_user_id' => ['required', 'uuid']]);
        $membership = $request->attributes->get('tenant_membership');
        abort_if($data['tenant_user_id'] === $membership->id, 422, 'Akses Anda sendiri tidak bisa dicabut.');
        abort_unless(DB::table('outlets')->where('tenant_id', $tenant->id)->where('id', $data['outlet_id'])->exists(), 404);
        $remaining = DB::table('user_outlet')->where('tenant_user_id', $data['tenant_user_id'])->where('status', 'active')->where('outlet_id', '!=', $data['outlet_id'])->count();
        abort_if($remaining === 0, 422, 'Pengguna harus punya akses ke minimal satu outlet.');
        DB::table('user_outlet')->where('tenant_user_id', $data['tenant_user_id'])->where('outlet_id', $data['outlet_id'])->update(['status' => 'inactive', 'updated_at' => now()]);

        return back()->with('success', 'Akses outlet dicabut.');
    }
}
