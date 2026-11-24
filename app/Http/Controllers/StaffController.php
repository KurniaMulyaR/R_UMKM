<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\View\View;

class StaffController extends Controller
{
    public function index(Request $request): View
    {
        $tenant = $request->attributes->get('tenant');
        $staff = DB::table('tenant_user')->join('users', 'users.id', '=', 'tenant_user.user_id')
            ->join('tenant_user_roles', 'tenant_user_roles.tenant_user_id', '=', 'tenant_user.id')
            ->join('roles', 'roles.id', '=', 'tenant_user_roles.role_id')
            ->where('tenant_user.tenant_id', $tenant->id)->where('tenant_user.status', 'active')
            ->select('users.id', 'users.name', 'users.phone', 'users.email', 'users.last_login_at', 'roles.name as role_name', 'roles.key as role_key')
            ->orderByRaw("CASE roles.key WHEN 'owner' THEN 0 WHEN 'manager' THEN 1 WHEN 'cashier' THEN 2 ELSE 3 END")
            ->orderBy('users.name')->get();
        return view('staff.index', compact('staff'));
    }

    public function store(Request $request): RedirectResponse
    {
        $tenant = $request->attributes->get('tenant');
        $outlet = $request->attributes->get('outlet');
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'phone' => ['required', 'string', 'max:32', 'unique:users,phone'],
            'email' => ['nullable', 'email', 'max:190', 'unique:users,email'],
            'role' => ['required', 'in:manager,cashier,warehouse'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);
        DB::transaction(function () use ($data, $tenant, $outlet) {
            $user = User::create([
                'name' => $data['name'], 'phone' => $data['phone'], 'email' => $data['email'] ?? null,
                'password' => $data['password'],
            ]);
            $membershipId = (string) Str::uuid();
            $now = now();
            DB::table('tenant_user')->insert([
                'id' => $membershipId, 'tenant_id' => $tenant->id, 'user_id' => $user->id,
                'status' => 'active', 'joined_at' => $now, 'invited_by_user_id' => auth()->id(),
                'created_at' => $now, 'updated_at' => $now,
            ]);
            $role = DB::table('roles')->where('tenant_id', $tenant->id)->where('key', $data['role'])->first();
            abort_unless($role, 422, 'Peran belum tersedia.');
            DB::table('tenant_user_roles')->insert([
                'id' => (string) Str::uuid(), 'tenant_user_id' => $membershipId, 'role_id' => $role->id,
                'created_at' => $now, 'updated_at' => $now,
            ]);
            DB::table('user_outlet')->insert([
                'id' => (string) Str::uuid(), 'tenant_user_id' => $membershipId, 'outlet_id' => $outlet->id,
                'is_default' => true, 'status' => 'active', 'created_at' => $now, 'updated_at' => $now,
            ]);
            DB::table('audit_logs')->insert([
                'id' => (string) Str::uuid(), 'tenant_id' => $tenant->id, 'outlet_id' => $outlet->id,
                'actor_user_id' => auth()->id(), 'action' => 'staff.created', 'subject_type' => 'user',
                'subject_id' => $user->id, 'after' => json_encode(['role' => $data['role']]),
                'occurred_at' => $now, 'created_at' => $now,
            ]);
        });
        return redirect()->route('staff.index')->with('success', 'Akun staf berhasil dibuat. Berikan nomor HP dan kata sandi awal kepada staf secara langsung.');
    }
}
