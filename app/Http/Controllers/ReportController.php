<?php

namespace App\Http\Controllers;

use App\Models\AccountingPeriod;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class ReportController extends Controller
{
    /**
     * Jurnal yang sudah dibalik (reversed) tetap dihitung bersama jurnal pembaliknya sehingga efek netonya nol.
     */
    private const COUNTED_STATUSES = ['posted', 'reversed'];

    public function profitLoss(Request $request): View
    {
        $tenant = $request->attributes->get('tenant');
        $tz = $tenant->timezone;
        $valid = fn (?string $value) => $value && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) && strtotime($value) !== false;
        $from = $valid($request->query('from')) ? $request->query('from') : Carbon::now($tz)->startOfMonth()->toDateString();
        $to = $valid($request->query('to')) ? $request->query('to') : Carbon::now($tz)->toDateString();
        if ($from > $to) {
            [$from, $to] = [$to, $from];
        }

        $rows = DB::table('journal_lines')
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.journal_entry_id')
            ->join('chart_of_accounts', 'chart_of_accounts.id', '=', 'journal_lines.account_id')
            ->where('journal_lines.tenant_id', $tenant->id)->whereIn('journal_entries.status', self::COUNTED_STATUSES)
            ->whereBetween('journal_entries.entry_date', [$from, $to])->whereIn('chart_of_accounts.account_type', ['revenue', 'expense'])
            ->groupBy('chart_of_accounts.code', 'chart_of_accounts.name', 'chart_of_accounts.account_type')
            ->orderBy('chart_of_accounts.code')
            ->selectRaw('chart_of_accounts.code as code, chart_of_accounts.name as name, chart_of_accounts.account_type as type, SUM(journal_lines.debit) as debit, SUM(journal_lines.credit) as credit')
            ->get()
            ->map(function ($row) {
                $row->amount = $row->type === 'revenue' ? (float) $row->credit - (float) $row->debit : (float) $row->debit - (float) $row->credit;

                return $row;
            })->filter(fn ($row) => abs($row->amount) > 0.004)->values();

        $revenue = $rows->where('type', 'revenue');
        $costOfSales = $rows->filter(fn ($row) => str_starts_with($row->code, '5'));
        $operating = $rows->filter(fn ($row) => $row->type === 'expense' && ! str_starts_with($row->code, '5'));
        $totalRevenue = (float) $revenue->sum('amount');
        $totalCost = (float) $costOfSales->sum('amount');
        $totalOperating = (float) $operating->sum('amount');

        return view('reports.profit-loss', [
            'from' => $from, 'to' => $to, 'revenue' => $revenue, 'costOfSales' => $costOfSales, 'operating' => $operating,
            'totalRevenue' => $totalRevenue, 'totalCost' => $totalCost, 'grossProfit' => $totalRevenue - $totalCost,
            'totalOperating' => $totalOperating, 'netProfit' => $totalRevenue - $totalCost - $totalOperating,
        ]);
    }

    public function balanceSheet(Request $request): View
    {
        $tenant = $request->attributes->get('tenant');
        $today = Carbon::now($tenant->timezone)->toDateString();
        $asOf = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $request->query('as_of')) && strtotime($request->query('as_of')) !== false ? $request->query('as_of') : $today;

        $rows = DB::table('journal_lines')
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.journal_entry_id')
            ->join('chart_of_accounts', 'chart_of_accounts.id', '=', 'journal_lines.account_id')
            ->where('journal_lines.tenant_id', $tenant->id)->whereIn('journal_entries.status', self::COUNTED_STATUSES)
            ->where('journal_entries.entry_date', '<=', $asOf)
            ->groupBy('chart_of_accounts.code', 'chart_of_accounts.name', 'chart_of_accounts.account_type')
            ->orderBy('chart_of_accounts.code')
            ->selectRaw('chart_of_accounts.code as code, chart_of_accounts.name as name, chart_of_accounts.account_type as type, SUM(journal_lines.debit) as debit, SUM(journal_lines.credit) as credit')
            ->get();

        $assets = $rows->where('type', 'asset')->map(fn ($row) => $this->withAmount($row, (float) $row->debit - (float) $row->credit))->filter(fn ($row) => abs($row->amount) > 0.004)->values();
        $liabilities = $rows->where('type', 'liability')->map(fn ($row) => $this->withAmount($row, (float) $row->credit - (float) $row->debit))->filter(fn ($row) => abs($row->amount) > 0.004)->values();
        $equity = $rows->where('type', 'equity')->map(fn ($row) => $this->withAmount($row, (float) $row->credit - (float) $row->debit))->filter(fn ($row) => abs($row->amount) > 0.004)->values();
        $earnings = (float) $rows->where('type', 'revenue')->sum(fn ($row) => (float) $row->credit - (float) $row->debit)
            - (float) $rows->where('type', 'expense')->sum(fn ($row) => (float) $row->debit - (float) $row->credit);

        $totalAssets = round((float) $assets->sum('amount'), 2);
        $totalLiabilities = round((float) $liabilities->sum('amount'), 2);
        $totalEquity = round((float) $equity->sum('amount') + $earnings, 2);

        return view('reports.balance-sheet', [
            'asOf' => $asOf, 'assets' => $assets, 'liabilities' => $liabilities, 'equity' => $equity, 'earnings' => round($earnings, 2),
            'totalAssets' => $totalAssets, 'totalLiabilities' => $totalLiabilities, 'totalEquity' => $totalEquity,
            'difference' => round($totalAssets - $totalLiabilities - $totalEquity, 2),
        ]);
    }

    /**
     * Modal awal atau tambahan modal (Dr kas/bank, Cr Modal Pemilik) dan prive (Dr Prive, Cr kas/bank).
     */
    public function storeEquity(Request $request): RedirectResponse
    {
        $tenant = $request->attributes->get('tenant');
        $outlet = $request->attributes->get('outlet');
        $data = $request->validate([
            'kind' => ['required', 'in:capital,draw'], 'method' => ['required', 'in:cash,bank_transfer'],
            'amount' => ['required', 'numeric', 'gt:0', 'max:99999999999999'], 'entry_date' => ['required', 'date', 'before_or_equal:today'],
            'note' => ['nullable', 'string', 'max:300'],
        ]);

        AccountingPeriod::assertOpen($tenant->id, $data['entry_date']);

        DB::transaction(function () use ($tenant, $outlet, $data) {
            $now = now($tenant->timezone);
            $cashAccount = DB::table('cash_accounts')->where('tenant_id', $tenant->id)->where('outlet_id', $outlet->id)
                ->where('type', $data['method'] === 'cash' ? 'cash' : 'bank')->where('is_active', true)->first();
            if (! $cashAccount || ! $cashAccount->chart_account_id) {
                throw ValidationException::withMessages(['method' => 'Akun kas atau bank belum disiapkan.']);
            }
            $isCapital = $data['kind'] === 'capital';
            $equityAccount = $this->equityAccount($tenant->id, $isCapital ? '3010' : '3020', $isCapital ? 'Modal Pemilik' : 'Prive Pemilik', $isCapital ? 'credit' : 'debit', $now);
            $amount = round((float) $data['amount'], 2);
            $entryId = (string) Str::uuid();
            $label = $isCapital ? 'Setoran modal pemilik' : 'Prive pemilik';
            DB::table('journal_entries')->insert([
                'id' => $entryId, 'tenant_id' => $tenant->id, 'outlet_id' => $outlet->id, 'entry_number' => ($isCapital ? 'CAP-' : 'DRW-').Str::upper(Str::random(8)),
                'entry_date' => $data['entry_date'], 'status' => 'posted', 'description' => $label.($data['note'] ? ': '.$data['note'] : ''),
                'source_type' => $isCapital ? 'owner_capital' : 'owner_draw', 'source_id' => $entryId, 'idempotency_key' => 'owner-equity:'.$entryId,
                'posted_at' => $now, 'posted_by_user_id' => auth()->id(), 'created_by_user_id' => auth()->id(), 'created_at' => $now, 'updated_at' => $now,
            ]);
            $lines = $isCapital
                ? [[$cashAccount->chart_account_id, $amount, 0], [$equityAccount->id, 0, $amount]]
                : [[$equityAccount->id, $amount, 0], [$cashAccount->chart_account_id, 0, $amount]];
            foreach ($lines as [$accountId, $debit, $credit]) {
                DB::table('journal_lines')->insert([
                    'id' => (string) Str::uuid(), 'tenant_id' => $tenant->id, 'journal_entry_id' => $entryId, 'account_id' => $accountId,
                    'description' => $label, 'debit' => $debit, 'credit' => $credit, 'created_at' => $now, 'updated_at' => $now,
                ]);
            }
            DB::table('audit_logs')->insert([
                'id' => (string) Str::uuid(), 'tenant_id' => $tenant->id, 'outlet_id' => $outlet->id, 'actor_user_id' => auth()->id(),
                'action' => $isCapital ? 'equity.capital_added' : 'equity.owner_draw', 'subject_type' => 'journal_entry', 'subject_id' => $entryId,
                'after' => json_encode(['amount' => $amount, 'method' => $data['method']]), 'occurred_at' => $now, 'created_at' => $now,
            ]);
        }, attempts: 3);

        return redirect()->route('reports.balance-sheet')->with('success', $data['kind'] === 'capital' ? 'Setoran modal dicatat.' : 'Prive dicatat.');
    }

    private function withAmount(object $row, float $amount): object
    {
        $row->amount = $amount;

        return $row;
    }

    private function equityAccount(string $tenantId, string $code, string $name, string $normal, $now): object
    {
        $account = DB::table('chart_of_accounts')->where('tenant_id', $tenantId)->where('code', $code)->first();
        if ($account) {
            return $account;
        }
        DB::table('chart_of_accounts')->insert([
            'id' => (string) Str::uuid(), 'tenant_id' => $tenantId, 'code' => $code, 'name' => $name, 'account_type' => 'equity',
            'normal_balance' => $normal, 'is_system' => true, 'allow_manual_posting' => false, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now,
        ]);

        return DB::table('chart_of_accounts')->where('tenant_id', $tenantId)->where('code', $code)->first();
    }
}
