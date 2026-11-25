<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class ReconciliationController extends Controller
{
    private const COUNTED_STATUSES = ['posted', 'reversed'];

    private const ACCOUNT_CODES = ['1010', '1020', '1030'];

    public function index(Request $request): View
    {
        $tenant = $request->attributes->get('tenant');
        $history = DB::table('bank_reconciliations')->join('chart_of_accounts', 'chart_of_accounts.id', '=', 'bank_reconciliations.account_id')
            ->where('bank_reconciliations.tenant_id', $tenant->id)->orderByDesc('bank_reconciliations.created_at')->limit(30)
            ->select('bank_reconciliations.*', 'chart_of_accounts.code', 'chart_of_accounts.name as account_name')->get();

        return view('reconciliations.index', ['history' => $history, 'accounts' => $this->accounts($tenant->id)]);
    }

    public function store(Request $request): RedirectResponse
    {
        $tenant = $request->attributes->get('tenant');
        $data = $request->validate([
            'account_id' => ['required', 'uuid'], 'statement_date' => ['required', 'date', 'before_or_equal:today'],
            'statement_balance' => ['required', 'numeric', 'min:-99999999999999', 'max:99999999999999'], 'note' => ['nullable', 'string', 'max:300'],
        ]);
        abort_unless($this->accounts($tenant->id)->contains('id', $data['account_id']), 404);
        if (DB::table('bank_reconciliations')->where('tenant_id', $tenant->id)->where('account_id', $data['account_id'])->where('status', 'draft')->exists()) {
            throw ValidationException::withMessages(['account_id' => 'Masih ada rekonsiliasi draf untuk akun ini. Selesaikan atau hapus dulu.']);
        }
        $now = now($tenant->timezone);
        $id = (string) Str::uuid();
        DB::table('bank_reconciliations')->insert([
            'id' => $id, 'tenant_id' => $tenant->id, 'account_id' => $data['account_id'], 'statement_date' => $data['statement_date'],
            'statement_balance' => round((float) $data['statement_balance'], 2), 'status' => 'draft', 'note' => $data['note'] ?? null,
            'created_by_user_id' => auth()->id(), 'created_at' => $now, 'updated_at' => $now,
        ]);

        return redirect()->route('reconciliations.show', $id);
    }

    public function show(Request $request, string $id): View
    {
        $tenant = $request->attributes->get('tenant');
        $rec = $this->find($tenant->id, $id);
        $account = DB::table('chart_of_accounts')->where('id', $rec->account_id)->first();
        $selected = DB::table('reconciled_journal_lines')->where('bank_reconciliation_id', $id)->pluck('journal_line_id')->all();

        $candidates = $this->baseLines($tenant->id, $rec->account_id)
            ->where('journal_entries.entry_date', '<=', $rec->statement_date)
            ->where(fn ($query) => $query->whereNotIn('journal_lines.id', DB::table('reconciled_journal_lines')->where('tenant_id', $tenant->id)->select('journal_line_id'))
                ->orWhereIn('journal_lines.id', $selected ?: ['-']))
            ->orderBy('journal_entries.entry_date')->orderBy('journal_entries.created_at')
            ->select('journal_lines.id', 'journal_lines.debit', 'journal_lines.credit', 'journal_lines.description', 'journal_entries.entry_date', 'journal_entries.entry_number')->get();

        $cleared = $this->clearedBalance($tenant->id, $rec->account_id, $id);
        $selectedNet = (float) $candidates->whereIn('id', $selected)->sum(fn ($line) => (float) $line->debit - (float) $line->credit);
        $previouslyCleared = round($cleared - $selectedNet, 2);

        return view('reconciliations.show', [
            'rec' => $rec, 'account' => $account, 'candidates' => $candidates, 'selected' => $selected,
            'previouslyCleared' => $previouslyCleared, 'cleared' => $cleared, 'difference' => round((float) $rec->statement_balance - $cleared, 2),
            'bookBalance' => $this->bookBalance($tenant->id, $rec->account_id, $rec->statement_date),
        ]);
    }

    public function update(Request $request, string $id): RedirectResponse
    {
        $tenant = $request->attributes->get('tenant');
        $rec = $this->find($tenant->id, $id);
        abort_unless($rec->status === 'draft', 422, 'Rekonsiliasi ini sudah selesai.');
        $data = $request->validate(['lines' => ['nullable', 'array', 'max:5000'], 'lines.*' => ['uuid'], 'complete' => ['nullable', 'boolean']]);

        DB::transaction(function () use ($tenant, $rec, $id, $data) {
            $now = now($tenant->timezone);
            $valid = $this->baseLines($tenant->id, $rec->account_id)->where('journal_entries.entry_date', '<=', $rec->statement_date)
                ->whereNotIn('journal_lines.id', DB::table('reconciled_journal_lines')->where('tenant_id', $tenant->id)->where('bank_reconciliation_id', '!=', $id)->select('journal_line_id'))
                ->pluck('journal_lines.id')->all();
            $chosen = array_values(array_intersect($data['lines'] ?? [], $valid));
            DB::table('reconciled_journal_lines')->where('bank_reconciliation_id', $id)->delete();
            foreach ($chosen as $lineId) {
                DB::table('reconciled_journal_lines')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $tenant->id, 'bank_reconciliation_id' => $id, 'journal_line_id' => $lineId, 'created_at' => $now, 'updated_at' => $now]);
            }
            if (! empty($data['complete'])) {
                $difference = round((float) $rec->statement_balance - $this->clearedBalance($tenant->id, $rec->account_id, $id), 2);
                if (abs($difference) > 0.004) {
                    throw ValidationException::withMessages(['lines' => 'Selisih masih Rp '.number_format(abs($difference), 0, ',', '.').'. Rekonsiliasi hanya bisa diselesaikan saat selisih nol.']);
                }
                DB::table('bank_reconciliations')->where('id', $id)->update(['status' => 'completed', 'completed_at' => $now, 'completed_by_user_id' => auth()->id(), 'updated_at' => $now]);
            }
        });

        return empty($data['complete']) ? back()->with('success', 'Pilihan disimpan.') : redirect()->route('reconciliations.index')->with('success', 'Rekonsiliasi selesai.');
    }

    public function destroy(Request $request, string $id): RedirectResponse
    {
        $tenant = $request->attributes->get('tenant');
        $rec = $this->find($tenant->id, $id);
        abort_unless($rec->status === 'draft', 422, 'Hanya draf yang bisa dihapus.');
        DB::table('bank_reconciliations')->where('id', $id)->delete();

        return redirect()->route('reconciliations.index')->with('success', 'Draf dihapus.');
    }

    private function find(string $tenantId, string $id): object
    {
        $rec = DB::table('bank_reconciliations')->where('tenant_id', $tenantId)->where('id', $id)->first();
        abort_unless($rec, 404);

        return $rec;
    }

    private function accounts(string $tenantId)
    {
        return DB::table('chart_of_accounts')->where('tenant_id', $tenantId)->whereIn('code', self::ACCOUNT_CODES)->orderBy('code')->get(['id', 'code', 'name']);
    }

    private function baseLines(string $tenantId, string $accountId)
    {
        return DB::table('journal_lines')->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.journal_entry_id')
            ->where('journal_lines.tenant_id', $tenantId)->where('journal_lines.account_id', $accountId)->whereIn('journal_entries.status', self::COUNTED_STATUSES);
    }

    /**
     * Net balance of lines already cleared by completed reconciliations, plus this draft's selection.
     */
    private function clearedBalance(string $tenantId, string $accountId, string $currentId): float
    {
        return round((float) DB::table('reconciled_journal_lines')->join('journal_lines', 'journal_lines.id', '=', 'reconciled_journal_lines.journal_line_id')
            ->join('bank_reconciliations', 'bank_reconciliations.id', '=', 'reconciled_journal_lines.bank_reconciliation_id')
            ->where('reconciled_journal_lines.tenant_id', $tenantId)->where('journal_lines.account_id', $accountId)
            ->where(fn ($query) => $query->where('bank_reconciliations.status', 'completed')->orWhere('bank_reconciliations.id', $currentId))
            ->sum(DB::raw('journal_lines.debit - journal_lines.credit')), 2);
    }

    private function bookBalance(string $tenantId, string $accountId, string $date): float
    {
        return round((float) $this->baseLines($tenantId, $accountId)->where('journal_entries.entry_date', '<=', $date)->sum(DB::raw('journal_lines.debit - journal_lines.credit')), 2);
    }
}
