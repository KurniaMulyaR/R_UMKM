<?php

namespace App\Http\Controllers;

use App\Models\AccountingPeriod;
use App\Support\SaleTax;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class VatReportController extends Controller
{
    public function index(Request $request): View
    {
        $tenant = $request->attributes->get('tenant');
        $month = preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', (string) $request->query('month')) ? (string) $request->query('month') : now($tenant->timezone)->format('Y-m');
        $start = Carbon::createFromFormat('Y-m-d', $month.'-01', $tenant->timezone)->startOfMonth();
        $end = $start->copy()->endOfMonth();
        $account = SaleTax::account($tenant->id);

        $bySource = DB::table('journal_lines')->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.journal_entry_id')
            ->where('journal_lines.tenant_id', $tenant->id)->where('journal_lines.account_id', $account->id)->whereIn('journal_entries.status', ['posted', 'reversed'])
            ->whereBetween('journal_entries.entry_date', [$start->toDateString(), $end->toDateString()])
            ->groupBy('journal_entries.source_type')->selectRaw('journal_entries.source_type, SUM(journal_lines.credit) as credit, SUM(journal_lines.debit) as debit')->get()->keyBy('source_type');
        $collected = (float) ($bySource['sale']->credit ?? 0);
        $reversed = (float) ($bySource['sale_void']->debit ?? 0) + (float) ($bySource['sale_return']->debit ?? 0);
        $paid = (float) ($bySource['ppn_payment']->debit ?? 0);
        $taxable = (float) DB::table('sales')->where('tenant_id', $tenant->id)->where('status', 'completed')->where('tax_total', '>', 0)
            ->whereBetween('sold_at', [$start->toDateTimeString(), $end->copy()->endOfDay()->toDateTimeString()])->selectRaw('COALESCE(SUM(grand_total - tax_total), 0) as dpp')->value('dpp');
        $balance = (float) DB::table('journal_lines')->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.journal_entry_id')
            ->where('journal_lines.tenant_id', $tenant->id)->where('journal_lines.account_id', $account->id)->whereIn('journal_entries.status', ['posted', 'reversed'])
            ->selectRaw('COALESCE(SUM(journal_lines.credit - journal_lines.debit), 0) as balance')->value('balance');
        $payments = DB::table('journal_entries')->where('tenant_id', $tenant->id)->where('source_type', 'ppn_payment')->orderByDesc('entry_date')->limit(12)
            ->get(['entry_number', 'entry_date', 'description']);

        return view('reports.ppn', [
            'month' => $month, 'monthLabel' => $start->locale('id')->translatedFormat('F Y'), 'collected' => $collected, 'reversed' => $reversed, 'paid' => $paid,
            'net' => round($collected - $reversed, 2), 'taxable' => $taxable, 'balance' => round($balance, 2), 'payments' => $payments, 'settings' => SaleTax::settings($tenant),
        ]);
    }

    /**
     * Setoran PPN ke negara: Dr Hutang PPN / Cr Kas atau Bank.
     */
    public function pay(Request $request): RedirectResponse
    {
        $tenant = $request->attributes->get('tenant');
        $outlet = $request->attributes->get('outlet');
        $data = $request->validate(['amount' => ['required', 'numeric', 'gt:0', 'max:99999999999999'], 'method' => ['required', 'in:cash,bank_transfer'], 'paid_on' => ['required', 'date', 'before_or_equal:today']]);
        DB::transaction(function () use ($tenant, $outlet, $data) {
            $now = now($tenant->timezone);
            $amount = round((float) $data['amount'], 2);
            AccountingPeriod::assertOpen($tenant->id, $data['paid_on']);
            $account = SaleTax::account($tenant->id);
            $balance = (float) DB::table('journal_lines')->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.journal_entry_id')
                ->where('journal_lines.tenant_id', $tenant->id)->where('journal_lines.account_id', $account->id)->whereIn('journal_entries.status', ['posted', 'reversed'])
                ->selectRaw('COALESCE(SUM(journal_lines.credit - journal_lines.debit), 0) as balance')->value('balance');
            if ($amount > $balance + 0.004) {
                throw ValidationException::withMessages(['amount' => 'Setoran melebihi saldo Hutang PPN (Rp '.number_format(max(0, $balance), 0, ',', '.').').']);
            }
            $cashAccount = DB::table('cash_accounts')->where('tenant_id', $tenant->id)->where('outlet_id', $outlet->id)->where('type', $data['method'] === 'cash' ? 'cash' : 'bank')->where('is_active', true)->first();
            if (! $cashAccount || ! $cashAccount->chart_account_id) {
                throw ValidationException::withMessages(['method' => 'Akun kas atau bank belum disiapkan.']);
            }
            $shift = $data['method'] === 'cash'
                ? DB::table('cash_shifts')->where('tenant_id', $tenant->id)->where('outlet_id', $outlet->id)->where('cashier_user_id', auth()->id())->where('status', 'open')->first()
                : null;
            $entryId = (string) Str::uuid();
            DB::table('journal_entries')->insert([
                'id' => $entryId, 'tenant_id' => $tenant->id, 'outlet_id' => $outlet->id, 'entry_number' => 'PPN-'.Str::upper(Str::random(8)), 'entry_date' => $data['paid_on'], 'status' => 'posted',
                'description' => 'Setoran PPN', 'source_type' => 'ppn_payment', 'source_id' => $entryId, 'idempotency_key' => 'ppn-payment:'.$entryId, 'cash_shift_id' => $shift?->id,
                'posted_at' => $now, 'posted_by_user_id' => auth()->id(), 'created_by_user_id' => auth()->id(), 'created_at' => $now, 'updated_at' => $now,
            ]);
            foreach ([[$account->id, $amount, 0], [$cashAccount->chart_account_id, 0, $amount]] as [$accountId, $debit, $credit]) {
                DB::table('journal_lines')->insert([
                    'id' => (string) Str::uuid(), 'tenant_id' => $tenant->id, 'journal_entry_id' => $entryId, 'account_id' => $accountId,
                    'description' => 'Setoran PPN', 'debit' => $debit, 'credit' => $credit, 'created_at' => $now, 'updated_at' => $now,
                ]);
            }
        });

        return back()->with('success', 'Setoran PPN dicatat.');
    }
}
