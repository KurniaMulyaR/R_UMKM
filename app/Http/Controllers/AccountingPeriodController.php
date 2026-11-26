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

class AccountingPeriodController extends Controller
{
    public function index(Request $request): View
    {
        $tenant = $request->attributes->get('tenant');
        $history = DB::table('accounting_periods')->leftJoin('users', 'users.id', '=', 'accounting_periods.closed_by_user_id')
            ->where('accounting_periods.tenant_id', $tenant->id)->orderByDesc('accounting_periods.ends_on')->limit(24)
            ->select('accounting_periods.*', 'users.name as closed_by_name')->get();
        $closedThrough = AccountingPeriod::closedThrough($tenant->id);
        $lastClosable = Carbon::now($tenant->timezone)->startOfMonth()->subDay();
        $suggested = $closedThrough ? Carbon::parse($closedThrough)->addDay()->format('Y-m') : $lastClosable->format('Y-m');

        return view('reports.periods', ['closedThrough' => $closedThrough, 'history' => $history, 'suggested' => $suggested, 'lastClosable' => $lastClosable->format('Y-m')]);
    }

    public function close(Request $request): RedirectResponse
    {
        $tenant = $request->attributes->get('tenant');
        $data = $request->validate(['period' => ['required', 'date_format:Y-m'], 'note' => ['nullable', 'string', 'max:300']]);
        $start = Carbon::createFromFormat('Y-m-d', $data['period'].'-01', $tenant->timezone)->startOfMonth();
        $end = $start->copy()->endOfMonth();
        if ($end->toDateString() > Carbon::now($tenant->timezone)->startOfMonth()->subDay()->toDateString()) {
            throw ValidationException::withMessages(['period' => 'Hanya bulan yang sudah berakhir yang bisa ditutup.']);
        }

        DB::transaction(function () use ($tenant, $start, $end, $data) {
            $closed = DB::table('accounting_periods')->where('tenant_id', $tenant->id)->where('status', 'closed')->orderByDesc('ends_on')->lockForUpdate()->first();
            if ($closed && $end->toDateString() <= $closed->ends_on) {
                throw ValidationException::withMessages(['period' => 'Periode tersebut sudah ditutup.']);
            }
            if ($closed && $start->toDateString() !== Carbon::parse($closed->ends_on)->addDay()->toDateString()) {
                throw ValidationException::withMessages(['period' => 'Tutup bulan secara berurutan, mulai '.Carbon::parse($closed->ends_on)->addDay()->format('m/Y').'.']);
            }
            $now = now($tenant->timezone);
            DB::table('accounting_periods')->updateOrInsert(
                ['tenant_id' => $tenant->id, 'starts_on' => $start->toDateString(), 'ends_on' => $end->toDateString()],
                ['id' => (string) Str::uuid(), 'status' => 'closed', 'closed_at' => $now, 'closed_by_user_id' => auth()->id(), 'close_note' => $data['note'] ?? null, 'reopened_at' => null, 'created_at' => $now, 'updated_at' => $now],
            );
            $this->audit($tenant->id, 'period.closed', ['ends_on' => $end->toDateString()], $now);
        });

        return back()->with('success', 'Periode '.$start->format('m/Y').' ditutup.');
    }

    public function reopen(Request $request): RedirectResponse
    {
        $tenant = $request->attributes->get('tenant');
        $request->validate(['reason' => ['required', 'string', 'min:3', 'max:300']]);

        DB::transaction(function () use ($tenant, $request) {
            $latest = DB::table('accounting_periods')->where('tenant_id', $tenant->id)->where('status', 'closed')->orderByDesc('ends_on')->lockForUpdate()->first();
            abort_unless($latest, 422, 'Tidak ada periode yang sedang ditutup.');
            $now = now($tenant->timezone);
            DB::table('accounting_periods')->where('id', $latest->id)->update([
                'status' => 'open', 'reopened_at' => $now, 'reopened_by_user_id' => auth()->id(), 'updated_at' => $now,
            ]);
            $this->audit($tenant->id, 'period.reopened', ['ends_on' => $latest->ends_on, 'reason' => $request->input('reason')], $now);
        });

        return back()->with('success', 'Periode terakhir dibuka kembali.');
    }

    /**
     * @param  array<string, mixed>  $after
     */
    private function audit(string $tenantId, string $action, array $after, $now): void
    {
        DB::table('audit_logs')->insert([
            'id' => (string) Str::uuid(), 'tenant_id' => $tenantId, 'actor_user_id' => auth()->id(), 'action' => $action,
            'subject_type' => 'accounting_period', 'subject_id' => (string) Str::uuid(), 'after' => json_encode($after),
            'occurred_at' => $now,
        ]);

    }
}
