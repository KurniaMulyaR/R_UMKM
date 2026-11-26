<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PayrollTest extends TestCase
{
    use RefreshDatabase;

    private function prepare(): void
    {
        $this->post('/setup', [
            'owner_name' => 'Pemilik Uji', 'business_name' => 'Warung Uji', 'business_type' => 'grocery',
            'phone' => '081234567890', 'password' => 'rahasia123', 'password_confirmation' => 'rahasia123',
        ]);
    }

    private function hire(string $name, float $salary, float $allowance = 0): string
    {
        $this->post(route('employees.store'), ['name' => $name, 'position' => 'Kasir', 'base_salary' => $salary, 'fixed_allowance' => $allowance])->assertSessionHasNoErrors();

        return (string) DB::table('employees')->where('name', $name)->value('id');
    }

    private function balance(string $code): float
    {
        $row = DB::table('journal_lines')->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.journal_entry_id')
            ->join('chart_of_accounts', 'chart_of_accounts.id', '=', 'journal_lines.account_id')->where('chart_of_accounts.code', $code)
            ->whereIn('journal_entries.status', ['posted', 'reversed'])->selectRaw('COALESCE(SUM(debit),0) as d, COALESCE(SUM(credit),0) as c')->first();

        return (float) $row->d - (float) $row->c;
    }

    public function test_payroll_draft_post_and_pay_create_balanced_journals(): void
    {
        $this->prepare();
        $this->hire('Sari', 3000000, 200000);
        $this->hire('Budi', 2500000);
        $this->post(route('payroll.store'), ['period' => now()->format('Y-m')])->assertSessionHasNoErrors();
        $run = DB::table('payroll_runs')->first();
        $this->assertEquals(5700000.0, (float) $run->total_net);

        $items = DB::table('payroll_items')->get();
        $payload = [];
        foreach ($items as $item) {
            $payload[$item->id] = $item->employee_name === 'Sari' ? ['bonus' => 300000, 'deduction' => 100000] : ['bonus' => 0, 'deduction' => 0];
        }
        $this->put(route('payroll.items.update', $run->id), ['items' => $payload])->assertSessionHasNoErrors();
        $this->assertEquals(5900000.0, (float) DB::table('payroll_runs')->where('id', $run->id)->value('total_net'));

        $this->post(route('payroll.post', $run->id))->assertSessionHasNoErrors();
        $this->assertEquals(5900000.0, $this->balance('6010'));
        $this->assertEquals(-5900000.0, $this->balance('2020'));

        $this->put(route('payroll.items.update', $run->id), ['items' => $payload])->assertSessionHasErrors('payroll');
        $this->post(route('payroll.pay', $run->id), ['method' => 'bank_transfer'])->assertSessionHasNoErrors();
        $this->assertEquals(0.0, $this->balance('2020'));
        $this->assertEquals('paid', DB::table('payroll_runs')->value('status'));
        $this->post(route('payroll.post', $run->id))->assertSessionHasErrors('payroll');
    }

    public function test_period_is_unique_and_review_changes_salary(): void
    {
        $this->prepare();
        $id = $this->hire('Sari', 3000000);
        $this->post(route('employees.reviews.store', $id), ['reviewed_on' => now()->toDateString(), 'rating' => 5, 'new_salary' => 3300000])->assertSessionHasNoErrors();
        $this->assertEquals(3300000.0, (float) DB::table('employees')->where('id', $id)->value('base_salary'));
        $this->assertEquals(3000000.0, (float) DB::table('employee_reviews')->value('previous_salary'));
        $this->post(route('payroll.store'), ['period' => now()->format('Y-m')])->assertSessionHasNoErrors();
        $this->post(route('payroll.store'), ['period' => now()->format('Y-m')])->assertSessionHasErrors('period');
    }

    public function test_deduction_cannot_exceed_earnings(): void
    {
        $this->prepare();
        $this->hire('Sari', 1000000);
        $this->post(route('payroll.store'), ['period' => now()->format('Y-m')]);
        $run = DB::table('payroll_runs')->first();
        $itemId = DB::table('payroll_items')->value('id');
        $this->put(route('payroll.items.update', $run->id), ['items' => [$itemId => ['bonus' => 0, 'deduction' => 2000000]]])->assertSessionHasErrors('items');
    }
}
