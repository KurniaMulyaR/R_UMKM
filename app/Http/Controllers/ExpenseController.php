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

class ExpenseController extends Controller
{
    /**
     * Akun biaya operasional dibuat saat pertama dipakai agar usaha lama tidak perlu migrasi data.
     *
     * @var array<string, string>
     */
    private const CATEGORIES = [
        '6010' => 'Gaji & upah', '6020' => 'Sewa tempat', '6030' => 'Listrik, air & internet', '6040' => 'Transportasi & bensin',
        '6050' => 'Pemasaran & iklan', '6060' => 'Perlengkapan & kemasan', '6070' => 'Perawatan & perbaikan', '6090' => 'Biaya lain-lain',
    ];

    public function index(Request $request): View
    {
        $tenant = $request->attributes->get('tenant');
        $outlet = $request->attributes->get('outlet');
        $month = preg_match('/^\d{4}-\d{2}$/', (string) $request->query('month')) ? $request->query('month') : now($tenant->timezone)->format('Y-m');
        $start = Carbon::createFromFormat('Y-m-d', $month.'-01', $tenant->timezone)->startOfDay();
        $end = $start->copy()->endOfMonth();

        $base = DB::table('expenses')->join('expense_items', 'expense_items.expense_id', '=', 'expenses.id')
            ->join('chart_of_accounts', 'chart_of_accounts.id', '=', 'expense_items.account_id')
            ->where('expenses.tenant_id', $tenant->id)->where('expenses.outlet_id', $outlet->id)
            ->whereBetween('expenses.spent_at', [$start, $end]);
        $expenses = (clone $base)->orderByDesc('expenses.spent_at')->orderByDesc('expenses.created_at')
            ->select('expenses.*', 'chart_of_accounts.name as category_name', 'expense_items.description as item_description')->get();
        $byCategory = (clone $base)->where('expenses.status', 'posted')->groupBy('chart_of_accounts.name')
            ->selectRaw('chart_of_accounts.name as name, SUM(expense_items.amount) as total')->orderByDesc('total')->get();
        $total = (float) $byCategory->sum('total');
        $sales = (float) DB::table('sales')->where('tenant_id', $tenant->id)->where('outlet_id', $outlet->id)->where('status', 'completed')
            ->whereBetween('sold_at', [$start, $end])->sum(DB::raw('grand_total - tax_total'));
        $grossProfit = (float) DB::table('sale_items')->join('sales', 'sales.id', '=', 'sale_items.sale_id')
            ->where('sales.tenant_id', $tenant->id)->where('sales.outlet_id', $outlet->id)->where('sales.status', 'completed')
            ->whereBetween('sales.sold_at', [$start, $end])
            ->sum(DB::raw('sale_items.line_total - sale_items.unit_cost_snapshot * sale_items.quantity'));

        return view('expenses.index', [
            'expenses' => $expenses, 'byCategory' => $byCategory, 'total' => $total, 'month' => $month,
            'monthLabel' => $start->locale('id')->translatedFormat('F Y'), 'categories' => self::CATEGORIES,
            'sales' => $sales, 'grossProfit' => $grossProfit, 'netProfit' => $grossProfit - $total,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $tenant = $request->attributes->get('tenant');
        $outlet = $request->attributes->get('outlet');
        $data = $request->validate([
            'category' => ['required', 'in:'.implode(',', array_keys(self::CATEGORIES))],
            'amount' => ['required', 'numeric', 'gt:0', 'max:99999999999999'], 'method' => ['required', 'in:cash,bank_transfer'],
            'spent_at' => ['required', 'date', 'before_or_equal:today'], 'payee' => ['nullable', 'string', 'max:160'],
            'description' => ['nullable', 'string', 'max:500'],
        ]);

        DB::transaction(function () use ($tenant, $outlet, $data) {
            $now = now($tenant->timezone);
            $cashAccount = DB::table('cash_accounts')->where('tenant_id', $tenant->id)->where('outlet_id', $outlet->id)
                ->where('type', $data['method'] === 'cash' ? 'cash' : 'bank')->where('is_active', true)->first();
            if (! $cashAccount || ! $cashAccount->chart_account_id) {
                throw ValidationException::withMessages(['method' => 'Akun kas atau bank belum disiapkan.']);
            }
            $account = $this->expenseAccount($tenant->id, $data['category'], $now);
            $amount = round((float) $data['amount'], 2);
            $expenseId = (string) Str::uuid();
            $number = $this->nextNumber($tenant->id, $outlet->id, $now);
            $spentAt = Carbon::parse($data['spent_at'], $tenant->timezone)->setTimeFrom($now);
            AccountingPeriod::assertOpen($tenant->id, $spentAt);

            $entryId = (string) Str::uuid();
            DB::table('journal_entries')->insert([
                'id' => $entryId, 'tenant_id' => $tenant->id, 'outlet_id' => $outlet->id, 'entry_number' => 'JV-'.$number,
                'entry_date' => $spentAt->toDateString(), 'status' => 'posted', 'description' => 'Biaya '.self::CATEGORIES[$data['category']].' '.$number,
                'source_type' => 'expense', 'source_id' => $expenseId, 'idempotency_key' => 'expense:'.$expenseId.':journal',
                'posted_at' => $now, 'posted_by_user_id' => auth()->id(), 'created_by_user_id' => auth()->id(), 'created_at' => $now, 'updated_at' => $now,
            ]);
            foreach ([[$account->id, $amount, 0], [$cashAccount->chart_account_id, 0, $amount]] as [$accountId, $debit, $credit]) {
                DB::table('journal_lines')->insert([
                    'id' => (string) Str::uuid(), 'tenant_id' => $tenant->id, 'journal_entry_id' => $entryId, 'account_id' => $accountId,
                    'description' => 'Biaya '.$number, 'debit' => $debit, 'credit' => $credit, 'created_at' => $now, 'updated_at' => $now,
                ]);
            }
            $openShift = $data['method'] === 'cash'
                ? DB::table('cash_shifts')->where('tenant_id', $tenant->id)->where('outlet_id', $outlet->id)
                    ->where('cashier_user_id', auth()->id())->where('status', 'open')->first()
                : null;
            DB::table('expenses')->insert([
                'id' => $expenseId, 'tenant_id' => $tenant->id, 'outlet_id' => $outlet->id, 'cash_shift_id' => $openShift?->id, 'cash_account_id' => $cashAccount->id,
                'created_by_user_id' => auth()->id(), 'expense_number' => $number, 'spent_at' => $spentAt, 'payee' => $data['payee'] ?? null,
                'description' => $data['description'] ?? null, 'total' => $amount, 'status' => 'posted', 'journal_entry_id' => $entryId,
                'created_at' => $now, 'updated_at' => $now,
            ]);
            DB::table('expense_items')->insert([
                'id' => (string) Str::uuid(), 'tenant_id' => $tenant->id, 'expense_id' => $expenseId, 'account_id' => $account->id,
                'description' => $data['description'] ?? null, 'amount' => $amount, 'created_at' => $now, 'updated_at' => $now,
            ]);
            $this->audit($tenant->id, $outlet->id, 'expense.created', $expenseId, ['number' => $number, 'amount' => $amount], null, $now);
        }, attempts: 3);

        return redirect()->route('expenses.index', ['month' => Carbon::parse($data['spent_at'])->format('Y-m')])->with('success', 'Pengeluaran dicatat dan masuk ke jurnal.');
    }

    public function void(Request $request, string $id): RedirectResponse
    {
        $tenant = $request->attributes->get('tenant');
        $outlet = $request->attributes->get('outlet');
        $data = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:500']]);

        DB::transaction(function () use ($tenant, $outlet, $id, $data) {
            $now = now($tenant->timezone);
            $expense = DB::table('expenses')->where('tenant_id', $tenant->id)->where('outlet_id', $outlet->id)->where('id', $id)->lockForUpdate()->first();
            abort_unless($expense && $expense->status === 'posted', 422, 'Pengeluaran ini tidak bisa dibatalkan.');
            AccountingPeriod::assertOpen($tenant->id, $expense->spent_at);
            $original = DB::table('journal_entries')->where('id', $expense->journal_entry_id)->first();
            if ($original && $original->status === 'posted') {
                $reversalId = (string) Str::uuid();
                DB::table('journal_entries')->insert([
                    'id' => $reversalId, 'tenant_id' => $tenant->id, 'outlet_id' => $outlet->id, 'entry_number' => 'RV-'.$original->entry_number,
                    'entry_date' => $now->toDateString(), 'status' => 'posted', 'description' => 'Pembatalan '.$original->description,
                    'source_type' => 'expense_void', 'source_id' => $expense->id, 'idempotency_key' => 'expense:'.$expense->id.':reversal',
                    'posted_at' => $now, 'posted_by_user_id' => auth()->id(), 'created_by_user_id' => auth()->id(),
                    'reversal_of_entry_id' => $original->id, 'created_at' => $now, 'updated_at' => $now,
                ]);
                foreach (DB::table('journal_lines')->where('journal_entry_id', $original->id)->get() as $line) {
                    DB::table('journal_lines')->insert([
                        'id' => (string) Str::uuid(), 'tenant_id' => $tenant->id, 'journal_entry_id' => $reversalId, 'account_id' => $line->account_id,
                        'description' => 'Pembalik: '.$line->description, 'debit' => $line->credit, 'credit' => $line->debit, 'created_at' => $now, 'updated_at' => $now,
                    ]);
                }
                DB::table('journal_entries')->where('id', $original->id)->update(['status' => 'reversed', 'updated_at' => $now]);
            }
            DB::table('expenses')->where('id', $expense->id)->update(['status' => 'voided', 'updated_at' => $now]);
            $this->audit($tenant->id, $outlet->id, 'expense.voided', $expense->id, ['status' => 'voided'], $data['reason'], $now);
        }, attempts: 3);

        return back()->with('success', 'Pengeluaran dibatalkan dan jurnalnya dibalik.');
    }

    private function expenseAccount(string $tenantId, string $code, $now): object
    {
        $account = DB::table('chart_of_accounts')->where('tenant_id', $tenantId)->where('code', $code)->first();
        if ($account) {
            return $account;
        }
        DB::table('chart_of_accounts')->insert([
            'id' => (string) Str::uuid(), 'tenant_id' => $tenantId, 'code' => $code, 'name' => self::CATEGORIES[$code],
            'account_type' => 'expense', 'normal_balance' => 'debit', 'is_system' => true, 'allow_manual_posting' => false,
            'is_active' => true, 'created_at' => $now, 'updated_at' => $now,
        ]);

        return DB::table('chart_of_accounts')->where('tenant_id', $tenantId)->where('code', $code)->first();
    }

    private function nextNumber(string $tenantId, string $outletId, $now): string
    {
        $scope = ['tenant_id' => $tenantId, 'outlet_id' => $outletId, 'document_type' => 'expense', 'period_key' => $now->format('Y')];
        $counter = DB::table('document_counters')->where($scope)->lockForUpdate()->first();
        if (! $counter) {
            DB::table('document_counters')->insert($scope + [
                'id' => (string) Str::uuid(), 'prefix' => 'EXP', 'next_number' => 1, 'padding' => 5, 'created_at' => $now, 'updated_at' => $now,
            ]);
            $counter = DB::table('document_counters')->where($scope)->lockForUpdate()->first();
        }
        DB::table('document_counters')->where('id', $counter->id)->update(['next_number' => $counter->next_number + 1, 'updated_at' => $now]);

        return $counter->prefix.'/'.$scope['period_key'].'/'.str_pad((string) $counter->next_number, $counter->padding, '0', STR_PAD_LEFT);
    }

    /**
     * @param  array<string, mixed>  $after
     */
    private function audit(string $tenantId, string $outletId, string $action, string $subjectId, array $after, ?string $reason, $now): void
    {
        DB::table('audit_logs')->insert([
            'id' => (string) Str::uuid(), 'tenant_id' => $tenantId, 'outlet_id' => $outletId, 'actor_user_id' => auth()->id(),
            'action' => $action, 'subject_type' => 'expense', 'subject_id' => $subjectId, 'after' => json_encode($after),
            'reason' => $reason, 'occurred_at' => $now,
        ]);

    }
}
