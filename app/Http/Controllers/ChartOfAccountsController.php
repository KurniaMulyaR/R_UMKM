<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class ChartOfAccountsController extends Controller
{
    private const TYPES = ['asset' => 'Aset', 'liability' => 'Kewajiban', 'equity' => 'Ekuitas', 'revenue' => 'Pendapatan', 'expense' => 'Beban'];

    public function index(Request $request): View
    {
        $tenant = $request->attributes->get('tenant');
        $accounts = DB::table('chart_of_accounts')->where('tenant_id', $tenant->id)->orderBy('code')->get();

        return view('accounts.index', ['accounts' => $accounts, 'types' => self::TYPES]);
    }

    public function store(Request $request): RedirectResponse
    {
        $tenant = $request->attributes->get('tenant');
        $data = $request->validate([
            'code' => ['required', 'regex:/^\d{4,8}$/'], 'name' => ['required', 'string', 'max:160'], 'account_type' => ['required', 'in:'.implode(',', array_keys(self::TYPES))],
        ]);
        if (DB::table('chart_of_accounts')->where('tenant_id', $tenant->id)->where('code', $data['code'])->exists()) {
            throw ValidationException::withMessages(['code' => 'Kode akun sudah dipakai.']);
        }
        $now = now($tenant->timezone);
        DB::table('chart_of_accounts')->insert([
            'id' => (string) Str::uuid(), 'tenant_id' => $tenant->id, 'code' => $data['code'], 'name' => $data['name'], 'account_type' => $data['account_type'],
            'normal_balance' => in_array($data['account_type'], ['asset', 'expense'], true) ? 'debit' : 'credit', 'is_system' => false,
            'allow_manual_posting' => true, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now,
        ]);

        return back()->with('success', 'Akun '.$data['code'].' ditambahkan.');
    }
}
