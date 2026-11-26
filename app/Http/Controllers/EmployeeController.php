<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\View\View;

class EmployeeController extends Controller
{
    public function index(Request $request): View
    {
        $tenant = $request->attributes->get('tenant');
        $employees = DB::table('employees')->leftJoin('outlets', 'outlets.id', '=', 'employees.outlet_id')->where('employees.tenant_id', $tenant->id)
            ->orderByRaw("CASE employees.status WHEN 'active' THEN 0 ELSE 1 END")->orderBy('employees.name')->select('employees.*', 'outlets.name as outlet_name')->get();
        $outlets = DB::table('outlets')->where('tenant_id', $tenant->id)->where('status', 'active')->orderBy('name')->get(['id', 'name']);

        return view('employees.index', compact('employees', 'outlets'));
    }

    public function store(Request $request): RedirectResponse
    {
        $tenant = $request->attributes->get('tenant');
        $data = $this->validated($request, $tenant->id);
        $id = (string) Str::uuid();
        DB::table('employees')->insert($data + ['id' => $id, 'tenant_id' => $tenant->id, 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        $this->audit($tenant->id, $request, 'employee.created', $id, ['name' => $data['name'], 'base_salary' => $data['base_salary']]);

        return redirect()->route('employees.show', $id)->with('success', 'Karyawan ditambahkan.');
    }

    public function show(Request $request, string $id): View
    {
        $tenant = $request->attributes->get('tenant');
        $employee = $this->find($tenant->id, $id);
        $reviews = DB::table('employee_reviews')->leftJoin('users', 'users.id', '=', 'employee_reviews.reviewed_by_user_id')->where('employee_reviews.employee_id', $id)
            ->orderByDesc('reviewed_on')->orderByDesc('employee_reviews.created_at')->select('employee_reviews.*', 'users.name as reviewer_name')->get();
        $slips = DB::table('payroll_items')->join('payroll_runs', 'payroll_runs.id', '=', 'payroll_items.payroll_run_id')->where('payroll_items.employee_id', $id)
            ->orderByDesc('payroll_runs.period_key')->limit(12)->select('payroll_items.*', 'payroll_runs.period_key', 'payroll_runs.status as run_status')->get();
        $outlets = DB::table('outlets')->where('tenant_id', $tenant->id)->where('status', 'active')->orderBy('name')->get(['id', 'name']);

        return view('employees.show', compact('employee', 'reviews', 'slips', 'outlets'));
    }

    public function update(Request $request, string $id): RedirectResponse
    {
        $tenant = $request->attributes->get('tenant');
        $employee = $this->find($tenant->id, $id);
        $data = $this->validated($request, $tenant->id);
        unset($data['base_salary']);
        DB::table('employees')->where('id', $id)->update($data + ['updated_at' => now()]);
        $this->audit($tenant->id, $request, 'employee.updated', $id, ['name' => $data['name'], 'before_name' => $employee->name]);

        return back()->with('success', 'Data karyawan diperbarui. Gaji pokok diubah lewat evaluasi.');
    }

    public function setStatus(Request $request, string $id, string $status): RedirectResponse
    {
        abort_unless(in_array($status, ['active', 'inactive'], true), 404);
        $tenant = $request->attributes->get('tenant');
        $this->find($tenant->id, $id);
        DB::table('employees')->where('id', $id)->update(['status' => $status, 'updated_at' => now()]);
        $this->audit($tenant->id, $request, $status === 'active' ? 'employee.activated' : 'employee.deactivated', $id, []);

        return back()->with('success', $status === 'active' ? 'Karyawan diaktifkan kembali.' : 'Karyawan dinonaktifkan; tidak ikut payroll berikutnya.');
    }

    /**
     * Evaluasi berkala. Jika gaji pokok baru diisi, gaji karyawan berubah mulai payroll berikutnya (payroll draft tidak ikut berubah).
     */
    public function storeReview(Request $request, string $id): RedirectResponse
    {
        $tenant = $request->attributes->get('tenant');
        $employee = $this->find($tenant->id, $id);
        $data = $request->validate([
            'reviewed_on' => ['required', 'date'], 'rating' => ['required', 'integer', 'between:1,5'], 'notes' => ['nullable', 'string', 'max:2000'],
            'reward_note' => ['nullable', 'string', 'max:255'], 'new_salary' => ['nullable', 'numeric', 'min:0', 'max:99999999999999'],
        ]);
        $newSalary = ($data['new_salary'] ?? '') === '' ? null : round((float) $data['new_salary'], 2);
        DB::transaction(function () use ($tenant, $employee, $id, $data, $newSalary, $request) {
            DB::table('employee_reviews')->insert([
                'id' => (string) Str::uuid(), 'tenant_id' => $tenant->id, 'employee_id' => $id, 'reviewed_on' => $data['reviewed_on'], 'rating' => $data['rating'], 'notes' => $data['notes'] ?? null,
                'reward_note' => $data['reward_note'] ?? null, 'previous_salary' => $employee->base_salary, 'new_salary' => $newSalary, 'reviewed_by_user_id' => auth()->id(), 'created_at' => now(), 'updated_at' => now(),
            ]);
            if ($newSalary !== null && abs($newSalary - (float) $employee->base_salary) > 0.004) {
                DB::table('employees')->where('id', $id)->update(['base_salary' => $newSalary, 'updated_at' => now()]);
                $this->audit($tenant->id, $request, 'employee.salary_changed', $id, ['from' => (float) $employee->base_salary, 'to' => $newSalary]);
            }
        });

        return back()->with('success', 'Evaluasi dicatat.');
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, string $tenantId): array
    {
        $rules = [
            'name' => ['required', 'string', 'max:160'], 'position' => ['nullable', 'string', 'max:120'], 'phone' => ['nullable', 'string', 'max:32'],
            'joined_on' => ['nullable', 'date'], 'fixed_allowance' => ['nullable', 'numeric', 'min:0', 'max:99999999999999'], 'outlet_id' => ['nullable', 'uuid'], 'notes' => ['nullable', 'string', 'max:2000'],
        ];
        if ($request->isMethod('post')) {
            $rules['base_salary'] = ['required', 'numeric', 'min:0', 'max:99999999999999'];
        }
        $data = $request->validate($rules);
        if (! empty($data['outlet_id'])) {
            abort_unless(DB::table('outlets')->where('tenant_id', $tenantId)->where('id', $data['outlet_id'])->exists(), 422, 'Outlet tidak valid.');
        }

        return [
            'name' => $data['name'], 'position' => $data['position'] ?? null, 'phone' => $data['phone'] ?? null, 'joined_on' => $data['joined_on'] ?? null,
            'fixed_allowance' => round((float) ($data['fixed_allowance'] ?? 0), 2), 'outlet_id' => $data['outlet_id'] ?? null, 'notes' => $data['notes'] ?? null,
        ] + (isset($data['base_salary']) ? ['base_salary' => round((float) $data['base_salary'], 2)] : []);
    }

    private function find(string $tenantId, string $id): object
    {
        $employee = DB::table('employees')->where('tenant_id', $tenantId)->where('id', $id)->first();
        abort_unless($employee, 404);

        return $employee;
    }

    /**
     * @param  array<string, mixed>  $after
     */
    private function audit(string $tenantId, Request $request, string $action, string $subjectId, array $after): void
    {
        DB::table('audit_logs')->insert([
            'id' => (string) Str::uuid(), 'tenant_id' => $tenantId, 'outlet_id' => $request->attributes->get('outlet')->id ?? null, 'actor_user_id' => auth()->id(), 'action' => $action,
            'subject_type' => 'employee', 'subject_id' => $subjectId, 'after' => json_encode($after), 'occurred_at' => now(),
        ]);
    }
}
