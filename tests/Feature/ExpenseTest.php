<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ExpenseTest extends TestCase
{
    use RefreshDatabase;

    private function balance(string $code): float
    {
        return (float) DB::table('journal_lines')
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.journal_entry_id')
            ->join('chart_of_accounts', 'chart_of_accounts.id', '=', 'journal_lines.account_id')
            ->where('chart_of_accounts.code', $code)->whereIn('journal_entries.status', ['posted', 'reversed'])
            ->sum(DB::raw('journal_lines.debit - journal_lines.credit'));
    }

    private function setupOwner(): void
    {
        $this->post('/setup', [
            'owner_name' => 'Pemilik Uji', 'business_name' => 'Warung Uji', 'business_type' => 'grocery',
            'phone' => '081234567890', 'password' => 'rahasia123', 'password_confirmation' => 'rahasia123',
        ]);
    }

    public function test_expense_posts_journal_and_shows_in_month_summary(): void
    {
        $this->setupOwner();
        $this->post(route('expenses.store'), [
            'category' => '6020', 'amount' => 500000, 'method' => 'cash', 'spent_at' => now()->toDateString(), 'payee' => 'Pak Haji',
        ])->assertRedirect();

        $this->assertEquals(500000.0, $this->balance('6020'));
        $this->assertEquals(-500000.0, $this->balance('1010'));
        $this->get(route('expenses.index'))->assertOk()->assertSee('Sewa tempat')->assertSee('Rp 500.000');
    }

    public function test_voiding_expense_reverses_the_journal(): void
    {
        $this->setupOwner();
        $this->post(route('expenses.store'), ['category' => '6030', 'amount' => 250000, 'method' => 'bank_transfer', 'spent_at' => now()->toDateString()]);
        $expense = DB::table('expenses')->first();

        $this->post(route('expenses.void', $expense->id), ['reason' => 'Salah catat'])->assertRedirect();

        $this->assertSame('voided', DB::table('expenses')->value('status'));
        $this->assertEquals(0.0, $this->balance('6030'));
        $this->assertEquals(0.0, $this->balance('1020'));
        $this->post(route('expenses.void', $expense->id), ['reason' => 'Lagi'])->assertStatus(422);
    }

    public function test_future_date_is_rejected(): void
    {
        $this->setupOwner();
        $this->post(route('expenses.store'), ['category' => '6010', 'amount' => 1000, 'method' => 'cash', 'spent_at' => now()->addDay()->toDateString()])
            ->assertSessionHasErrors('spent_at');
    }
}
