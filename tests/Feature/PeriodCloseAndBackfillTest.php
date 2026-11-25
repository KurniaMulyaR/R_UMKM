<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PeriodCloseAndBackfillTest extends TestCase
{
    use RefreshDatabase;

    private function setupOwner(): void
    {
        $this->post('/setup', [
            'owner_name' => 'Pemilik Uji', 'business_name' => 'Warung Uji', 'business_type' => 'grocery',
            'phone' => '081234567890', 'password' => 'rahasia123', 'password_confirmation' => 'rahasia123',
        ]);
    }

    public function test_closed_month_blocks_backdated_expense_and_can_be_reopened(): void
    {
        $this->setupOwner();
        $lastMonth = now()->startOfMonth()->subMonth();
        $this->post(route('reports.periods.close'), ['period' => $lastMonth->format('Y-m')])->assertSessionHasNoErrors();
        $this->assertSame($lastMonth->endOfMonth()->toDateString(), DB::table('accounting_periods')->where('status', 'closed')->value('ends_on'));

        $payload = ['category' => '6020', 'amount' => 50000, 'method' => 'cash', 'spent_at' => $lastMonth->copy()->day(10)->toDateString()];
        $this->post(route('expenses.store'), $payload)->assertSessionHasErrors('period');
        $this->assertSame(0, DB::table('expenses')->count());

        $this->post(route('expenses.store'), ['spent_at' => now()->toDateString()] + $payload)->assertSessionHasNoErrors();
        $this->assertSame(1, DB::table('expenses')->count());

        $this->post(route('reports.periods.reopen'), ['reason' => 'koreksi'])->assertSessionHasNoErrors();
        $this->post(route('expenses.store'), $payload)->assertSessionHasNoErrors();
        $this->assertSame(2, DB::table('expenses')->count());
    }

    public function test_current_month_cannot_be_closed(): void
    {
        $this->setupOwner();
        $this->post(route('reports.periods.close'), ['period' => now()->format('Y-m')])->assertSessionHasErrors('period');
        $this->assertSame(0, DB::table('accounting_periods')->where('status', 'closed')->count());
    }

    public function test_backfill_creates_missing_opening_journal_once_and_supports_dry_run(): void
    {
        $this->setupOwner();
        $this->post(route('products.store'), ['name' => 'Gula 1kg', 'unit_id' => DB::table('units')->value('id'), 'price' => 20000, 'cost_price' => 10000, 'initial_stock' => 20]);
        DB::table('journal_entries')->where('source_type', 'product_opening')->delete();
        $this->assertSame(0, DB::table('journal_entries')->where('source_type', 'product_opening')->count());

        $this->artisan('accounting:backfill-opening-stock', ['--dry-run' => true])->assertSuccessful();
        $this->assertSame(0, DB::table('journal_entries')->where('source_type', 'product_opening')->count());

        $this->artisan('accounting:backfill-opening-stock')->assertSuccessful();
        $this->artisan('accounting:backfill-opening-stock')->assertSuccessful();
        $this->assertSame(1, DB::table('journal_entries')->where('source_type', 'product_opening')->count());
        $inventory = (float) DB::table('journal_lines')->join('chart_of_accounts', 'chart_of_accounts.id', '=', 'journal_lines.account_id')
            ->where('code', '1100')->sum(DB::raw('debit - credit'));
        $this->assertEquals(200000.0, $inventory);
    }

    public function test_pos_sale_number_is_created_for_a_new_year(): void
    {
        $this->setupOwner();
        DB::table('document_counters')->where('document_type', 'sale')->update(['period_key' => (string) (now()->year - 1)]);
        $this->post(route('products.store'), ['name' => 'Teh', 'unit_id' => DB::table('units')->value('id'), 'price' => 5000, 'cost_price' => 3000, 'initial_stock' => 10]);
        $this->post(route('shifts.open'), ['opening_cash' => 100000]);
        $variantId = DB::table('product_variants')->value('id');
        $this->post(route('pos.checkout'), [
            'items' => [['variant_id' => $variantId, 'quantity' => 2]], 'cash_received' => 10000,
            'payments' => [['method' => 'cash', 'amount' => 10000]],
        ])->assertSessionHasNoErrors();
        $this->assertSame(1, DB::table('sales')->count());
        $this->assertSame(1, DB::table('document_counters')->where('document_type', 'sale')->where('period_key', (string) now()->year)->count());
    }
}
