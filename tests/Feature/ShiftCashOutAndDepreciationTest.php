<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ShiftCashOutAndDepreciationTest extends TestCase
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

    public function test_cash_expense_reduces_expected_cash_when_closing_shift(): void
    {
        $this->setupOwner();
        $this->post(route('shifts.open'), ['opening_cash' => 100000]);
        $this->post(route('expenses.store'), ['category' => '6040', 'amount' => 30000, 'method' => 'cash', 'spent_at' => now()->toDateString()]);
        $this->post(route('expenses.store'), ['category' => '6030', 'amount' => 90000, 'method' => 'bank_transfer', 'spent_at' => now()->toDateString()]);

        $this->post(route('shifts.close'), ['counted_cash' => 70000])->assertRedirect();

        $shift = DB::table('cash_shifts')->first();
        $this->assertSame('closed', $shift->status);
        $this->assertEquals(70000.0, (float) $shift->expected_cash);
    }

    public function test_voided_cash_expense_no_longer_reduces_expected_cash(): void
    {
        $this->setupOwner();
        $this->post(route('shifts.open'), ['opening_cash' => 100000]);
        $this->post(route('expenses.store'), ['category' => '6040', 'amount' => 30000, 'method' => 'cash', 'spent_at' => now()->toDateString()]);
        $this->post(route('expenses.void', DB::table('expenses')->value('id')), ['reason' => 'Salah catat']);

        $this->post(route('shifts.close'), ['counted_cash' => 100000]);

        $this->assertEquals(100000.0, (float) DB::table('cash_shifts')->value('expected_cash'));
    }

    public function test_depreciation_posts_catch_up_months_once(): void
    {
        $this->setupOwner();
        $this->post(route('assets.store'), [
            'name' => 'Freezer', 'category' => 'equipment', 'purchase_date' => now()->startOfMonth()->subMonths(3)->toDateString(),
            'purchase_cost' => 6000000, 'useful_life_months' => 60, 'salvage_value' => 0, 'funding' => 'none',
        ]);

        $this->post(route('assets.depreciation.store'), ['period' => now()->format('Y-m')])->assertRedirect();
        $this->assertSame(3, DB::table('asset_depreciation_entries')->count());
        $this->assertEquals(300000.0, $this->balance('6080'));
        $this->assertEquals(-300000.0, $this->balance('1590'));

        $this->post(route('assets.depreciation.store'), ['period' => now()->format('Y-m')])->assertRedirect();
        $this->assertSame(3, DB::table('asset_depreciation_entries')->count());
        $this->assertEquals(300000.0, $this->balance('6080'));
    }

    public function test_depreciation_for_future_month_is_rejected(): void
    {
        $this->setupOwner();
        $this->post(route('assets.depreciation.store'), ['period' => now()->addMonths(2)->format('Y-m')])->assertSessionHasErrors('period');
    }
}
