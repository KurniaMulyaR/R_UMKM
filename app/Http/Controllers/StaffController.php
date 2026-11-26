<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
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
            ->where('tenant_user.tenant_id', $tenant->id)->whereIn('tenant_user.status', ['active', 'inactive'])
            ->select('users.id', 'users.name', 'users.phone', 'users.email', 'users.last_login_at', 'tenant_user.status', 'roles.name as role_name', 'roles.key as role_key')
            ->orderByRaw("CASE tenant_user.status WHEN 'active' THEN 0 ELSE 1 END")
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
                'occurred_at' => $now,
            ]);

        });
        return redirect()->route('staff.index')->with('success', 'Akun staf berhasil dibuat. Berikan nomor HP dan kata sandi awal kepada staf secara langsung.');
    }

    public function update(Request $request, string $userId): RedirectResponse
    {
        [$tenant, $membership, $roleKey] = $this->managedMember($request, $userId);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'], 'phone' => ['required', 'string', 'max:32', Rule::unique('users', 'phone')->ignore($userId)],
            'email' => ['nullable', 'email', 'max:190', Rule::unique('users', 'email')->ignore($userId)], 'role' => ['required', 'in:manager,cashier,warehouse'],
        ]);
        $role = DB::table('roles')->where('tenant_id', $tenant->id)->where('key', $data['role'])->first();
        abort_unless($role, 422, 'Peran belum tersedia.');
        $now = now();
        DB::transaction(function () use ($data, $userId, $membership, $role, $roleKey, $tenant, $request, $now) {
            DB::table('users')->where('id', $userId)->update(['name' => $data['name'], 'phone' => $data['phone'], 'email' => $data['email'] ?? null, 'updated_at' => $now]);
            DB::table('tenant_user_roles')->where('tenant_user_id', $membership->id)->delete();
            DB::table('tenant_user_roles')->insert(['id' => (string) Str::uuid(), 'tenant_user_id' => $membership->id, 'role_id' => $role->id, 'created_at' => $now, 'updated_at' => $now]);
            $this->audit($tenant->id, $request, 'staff.updated', $userId, ['role_before' => $roleKey, 'role_after' => $data['role']], $now);
        });

        return back()->with('success', 'Data staf diperbarui.');
    }

    public function setStatus(Request $request, string $userId, string $status): RedirectResponse
    {
        abort_unless(in_array($status, ['active', 'inactive'], true), 404);
        [$tenant, $membership] = $this->managedMember($request, $userId, $status === 'active' ? 'inactive' : 'active');
        $now = now();
        DB::table('tenant_user')->where('id', $membership->id)->update(['status' => $status, 'updated_at' => $now]);
        if ($status === 'inactive') {
            DB::table('sessions')->where('user_id', $userId)->delete();
        }
        $this->audit($tenant->id, $request, $status === 'active' ? 'staff.activated' : 'staff.deactivated', $userId, [], $now);

        return back()->with('success', $status === 'active' ? 'Akun staf diaktifkan kembali.' : 'Akun staf dinonaktifkan dan langsung dikeluarkan dari sesi masuknya.');
    }

    public function resetPassword(Request $request, string $userId): RedirectResponse
    {
        [$tenant] = $this->managedMember($request, $userId);
        $data = $request->validate(['password' => ['required', 'string', 'min:8', 'confirmed']]);
        $now = now();
        DB::table('users')->where('id', $userId)->update(['password' => Hash::make($data['password']), 'remember_token' => null, 'updated_at' => $now]);
        DB::table('sessions')->where('user_id', $userId)->delete();
        $this->audit($tenant->id, $request, 'staff.password_reset', $userId, [], $now);

        return back()->with('success', 'Kata sandi diganti. Berikan kata sandi baru kepada staf secara langsung.');
    }

    /**
     * Pemilik tidak boleh mengubah dirinya sendiri atau pemilik lain lewat halaman ini.
     *
     * @return array{0: object, 1: object, 2: string}
     */
    private function managedMember(Request $request, string $userId, ?string $requiredStatus = null): array
    {
        $tenant = $request->attributes->get('tenant');
        abort_if($userId === auth()->id(), 422, 'Akun sendiri tidak bisa diubah dari halaman ini.');
        $membership = DB::table('tenant_user')->where('tenant_id', $tenant->id)->where('user_id', $userId)->whereIn('status', ['active', 'inactive'])->first();
        abort_unless($membership, 404);
        abort_if($requiredStatus && $membership->status !== $requiredStatus, 422, 'Status akun tidak sesuai.');
        $roleKey = (string) DB::table('tenant_user_roles')->join('roles', 'roles.id', '=', 'tenant_user_roles.role_id')->where('tenant_user_roles.tenant_user_id', $membership->id)->value('roles.key');
        abort_if($roleKey === 'owner', 422, 'Akun pemilik tidak bisa diubah dari halaman ini.');

        return [$tenant, $membership, $roleKey];
    }

    /**
     * @param  array<string, mixed>  $after
     */
    private function audit(string $tenantId, Request $request, string $action, string $userId, array $after, $now): void
    {
        DB::table('audit_logs')->insert([
            'id' => (string) Str::uuid(), 'tenant_id' => $tenantId, 'outlet_id' => $request->attributes->get('outlet')->id ?? null, 'actor_user_id' => auth()->id(), 'action' => $action,
            'subject_type' => 'user', 'subject_id' => $userId, 'after' => json_encode($after), 'ip_address' => $request->ip(), 'occurred_at' => $now, 'created_at' => $now,
        ]);
    }
}
