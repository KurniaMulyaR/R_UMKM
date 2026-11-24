<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\View\View;

class ShiftController extends Controller
{
    public function index(Request $request): View
    {
        $tenant = $request->attributes->get('tenant');
        $outlet = $request->attributes->get('outlet');
        $openShift = DB::table('cash_shifts')->where('tenant_id', $tenant->id)->where('outlet_id', $outlet->id)
            ->where('cashier_user_id', auth()->id())->where('status', 'open')->first();
        $shifts = DB::table('cash_shifts')->join('users', 'users.id', '=', 'cash_shifts.cashier_user_id')
            ->where('cash_shifts.tenant_id', $tenant->id)->where('cash_shifts.outlet_id', $outlet->id)
            ->orderByDesc('cash_shifts.opened_at')->limit(20)
            ->select('cash_shifts.*', 'users.name as cashier_name')->get();
        return view('shifts.index', compact('openShift', 'shifts'));
    }

    public function open(Request $request): RedirectResponse
    {
        $tenant = $request->attributes->get('tenant'); $outlet = $request->attributes->get('outlet');
        $data = $request->validate(['opening_cash' => ['required', 'numeric', 'min:0', 'max:99999999999999']]);
        $exists = DB::table('cash_shifts')->where('tenant_id', $tenant->id)->where('outlet_id', $outlet->id)
            ->where('cashier_user_id', auth()->id())->where('status', 'open')->exists();
        if ($exists) return back()->withErrors(['shift' => 'Shift masih terbuka. Tutup dulu sebelum membuka shift baru.']);

        DB::table('cash_shifts')->insert([
            'id' => (string) Str::uuid(), 'tenant_id' => $tenant->id, 'outlet_id' => $outlet->id,
            'cashier_user_id' => auth()->id(), 'opened_at' => now($tenant->timezone),
            'opening_cash' => $data['opening_cash'], 'status' => 'open', 'created_at' => now(), 'updated_at' => now(),
        ]);
        return redirect()->route('pos.index')->with('success', 'Shift dibuka. Kas awal sudah dicatat.');
    }

    public function close(Request $request): RedirectResponse
    {
        $tenant = $request->attributes->get('tenant'); $outlet = $request->attributes->get('outlet');
        $data = $request->validate(['counted_cash' => ['required', 'numeric', 'min:0', 'max:99999999999999'], 'close_note' => ['nullable', 'string', 'max:1000']]);
        $shift = DB::table('cash_shifts')->where('tenant_id', $tenant->id)->where('outlet_id', $outlet->id)
            ->where('cashier_user_id', auth()->id())->where('status', 'open')->lockForUpdate()->first();
        if (! $shift) return back()->withErrors(['shift' => 'Tidak ada shift terbuka.']);

        $cashSales = DB::table('payment_allocations')->join('payments', 'payments.id', '=', 'payment_allocations.payment_id')
            ->join('sales', 'sales.id', '=', 'payment_allocations.sale_id')
            ->where('payments.cash_shift_id', $shift->id)->where('sales.status', 'completed')
            ->where('payments.method', 'cash')->where('payments.status', 'confirmed')->sum('payment_allocations.amount');
        $expected = (float) $shift->opening_cash + (float) $cashSales;
        DB::table('cash_shifts')->where('id', $shift->id)->update([
            'closed_at' => now($tenant->timezone), 'expected_cash' => $expected,
            'counted_cash' => $data['counted_cash'], 'cash_difference' => (float) $data['counted_cash'] - $expected,
            'close_note' => $data['close_note'] ?? null, 'status' => 'closed', 'updated_at' => now(),
        ]);
        return back()->with('success', 'Shift ditutup. Selisih kas: Rp '.number_format((float) $data['counted_cash'] - $expected, 0, ',', '.').'.');
    }
}
