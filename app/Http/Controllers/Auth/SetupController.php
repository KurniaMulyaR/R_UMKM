<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\View\View;

class SetupController extends Controller
{
    public function create(): View|RedirectResponse
    {
        if (Auth::check()) {
            return redirect()->route('dashboard');
        }

        if (DB::table('users')->count() > 0) {
            return redirect()->route('login')->with('setup_locked', 'Setup awal sudah selesai. Silakan masuk dengan akun yang sudah dibuat.');
        }

        return view('auth.setup');
    }

    public function store(Request $request): RedirectResponse
    {
        if (DB::table('users')->count() > 0) {
            return redirect()->route('login')->with('setup_locked', 'Setup awal sudah selesai. Silakan masuk dengan akun yang sudah dibuat.');
        }
        $data = $request->validate([
            'owner_name' => ['required', 'string', 'max:120'],
            'business_name' => ['required', 'string', 'max:160'],
            'business_type' => ['required', 'in:grocery,food,electronics,other'],
            'phone' => ['required', 'string', 'max:32', 'unique:users,phone'],
            'email' => ['nullable', 'email', 'max:190', 'unique:users,email'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        $user = DB::transaction(function () use ($data) {
            $user = User::create([
                'name' => $data['owner_name'], 'phone' => $data['phone'],
                'email' => $data['email'] ?: null, 'password' => $data['password'],
            ]);
            $tenantId = (string) Str::uuid();
            $outletId = (string) Str::uuid();
            $membershipId = (string) Str::uuid();
            $now = now();

            DB::table('tenants')->insert([
                'id' => $tenantId, 'name' => $data['business_name'],
                'slug' => Str::slug($data['business_name']).'-'.Str::lower(Str::random(5)),
                'business_type' => $data['business_type'], 'timezone' => 'Asia/Jakarta',
                'currency' => 'IDR', 'status' => 'active', 'created_by_user_id' => $user->id,
                'created_at' => $now, 'updated_at' => $now,
            ]);
            DB::table('outlets')->insert([
                'id' => $outletId, 'tenant_id' => $tenantId, 'name' => $data['business_name'],
                'code' => 'UTAMA', 'timezone' => 'Asia/Jakarta', 'is_default' => true,
                'status' => 'active', 'created_at' => $now, 'updated_at' => $now,
            ]);
            DB::table('tenant_user')->insert([
                'id' => $membershipId, 'tenant_id' => $tenantId, 'user_id' => $user->id,
                'status' => 'active', 'joined_at' => $now, 'created_at' => $now, 'updated_at' => $now,
            ]);
            $roleId = (string) Str::uuid();
            DB::table('roles')->insert([
                'id' => $roleId, 'tenant_id' => $tenantId, 'name' => 'Pemilik', 'key' => 'owner',
                'description' => 'Akses penuh ke usaha', 'is_system' => true, 'created_at' => $now, 'updated_at' => $now,
            ]);
            DB::table('tenant_user_roles')->insert([
                'id' => (string) Str::uuid(), 'tenant_user_id' => $membershipId, 'role_id' => $roleId,
                'created_at' => $now, 'updated_at' => $now,
            ]);            foreach ([['Manager', 'manager'], ['Kasir', 'cashier'], ['Admin Gudang', 'warehouse']] as [$roleName, $roleKey]) {
                DB::table('roles')->insert([
                    'id' => (string) Str::uuid(), 'tenant_id' => $tenantId, 'name' => $roleName, 'key' => $roleKey,
                    'description' => 'Peran bawaan R Kasir', 'is_system' => true, 'created_at' => $now, 'updated_at' => $now,
                ]);
            }
            DB::table('user_outlet')->insert([
                'id' => (string) Str::uuid(), 'tenant_user_id' => $membershipId, 'outlet_id' => $outletId,
                'is_default' => true, 'status' => 'active', 'created_at' => $now, 'updated_at' => $now,
            ]);
            DB::table('units')->insert([
                'id' => (string) Str::uuid(), 'tenant_id' => $tenantId, 'name' => 'Pcs', 'symbol' => 'pcs',
                'precision' => 0, 'created_at' => $now, 'updated_at' => $now,
            ]);
            DB::table('product_categories')->insert([
                'id' => (string) Str::uuid(), 'tenant_id' => $tenantId, 'name' => 'Umum', 'sort_order' => 0,
                'is_active' => true, 'created_at' => $now, 'updated_at' => $now,
            ]);

            $accounts = [
                ['1010', 'Kas', 'asset', 'debit'], ['1020', 'Bank', 'asset', 'debit'],
                ['1030', 'Saldo QRIS / Gateway', 'asset', 'debit'], ['1100', 'Persediaan Barang', 'asset', 'debit'],
                ['1200', 'Piutang Pelanggan', 'asset', 'debit'], ['2010', 'Hutang Supplier', 'liability', 'credit'],
                ['4010', 'Penjualan', 'revenue', 'credit'], ['5010', 'Harga Pokok Penjualan', 'expense', 'debit'],
                ['5020', 'Selisih Persediaan', 'expense', 'debit'],
            ];
            $accountIds = [];
            foreach ($accounts as [$code, $name, $type, $normal]) {
                $accountIds[$code] = (string) Str::uuid();
                DB::table('chart_of_accounts')->insert([
                    'id' => $accountIds[$code], 'tenant_id' => $tenantId, 'code' => $code, 'name' => $name,
                    'account_type' => $type, 'normal_balance' => $normal, 'is_system' => true,
                    'allow_manual_posting' => false, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now,
                ]);
            }
            foreach ([['Kas Utama', 'cash', '1010'], ['Bank', 'bank', '1020'], ['QRIS', 'qris_clearing', '1030']] as [$name, $type, $code]) {
                DB::table('cash_accounts')->insert([
                    'id' => (string) Str::uuid(), 'tenant_id' => $tenantId, 'chart_account_id' => $accountIds[$code],
                    'outlet_id' => $outletId, 'name' => $name, 'type' => $type, 'is_active' => true,
                    'created_at' => $now, 'updated_at' => $now,
                ]);
            }
            foreach (['sale', 'return', 'stock_count', 'journal'] as $type) {
                DB::table('document_counters')->insert([
                    'id' => (string) Str::uuid(), 'tenant_id' => $tenantId, 'outlet_id' => $outletId,
                    'document_type' => $type, 'period_key' => now('Asia/Jakarta')->format('Y'),
                    'prefix' => strtoupper(substr($type, 0, 3)), 'next_number' => 1, 'padding' => 5,
                    'created_at' => $now, 'updated_at' => $now,
                ]);
            }

            return $user;
        });

        Auth::login($user);
        $request->session()->regenerate();

        return redirect()->route('dashboard')->with('success', 'Usaha berhasil disiapkan. Tambahkan produk pertama untuk mulai jualan.');
    }
}
