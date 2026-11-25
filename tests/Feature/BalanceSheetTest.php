<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class BalanceSheetTest extends TestCase
{
    use RefreshDatabase;

    private function setupOwner(): void
    {
        $this->post('/setup', [
            'owner_name' => 'Pemilik Uji', 'business_name' => 'Warung Uji', 'business_type' => 'grocery',
            'phone' => '081234567890', 'password' => 'rahasia123', 'password_confirmation' => 'rahasia123',
        ]);
    }

    public function test_balance_sheet_balances_across_capital_stock_expense_and_asset(): void
    {
        $this->setupOwner();
        $this->post(route('reports.equity.store'), ['kind' => 'capital', 'method' => 'cash', 'amount' => 5000000, 'entry_date' => now()->toDateString()])->assertRedirect();
        $this->post(route('products.store'), ['name' => 'Gula 1kg', 'unit_id' => DB::table('units')->value('id'), 'price' => 20000, 'cost_price' => 10000, 'initial_stock' => 20]);
        $this->post(route('expenses.store'), ['category' => '6020', 'amount' => 400000, 'method' => 'cash', 'spent_at' => now()->toDateString()]);
        $this->post(route('assets.store'), [
            'name' => 'Freezer', 'category' => 'equipment', 'purchase_date' => now()->startOfMonth()->subMonths(2)->toDateString(),
            'purchase_cost' => 6000000, 'useful_life_months' => 60, 'funding' => 'owner_equity',
        ]);
        $this->post(route('assets.depreciation.store'), ['period' => now()->format('Y-m')]);

        $response = $this->get(route('reports.balance-sheet'))->assertOk();
        $response->assertDontSee('Neraca tidak seimbang');
        $response->assertSee('Aset Tetap')->assertSee('Akumulasi Penyusutan')->assertSee('Modal Pemilik');

        $assets = (float) DB::table('journal_lines')->join('chart_of_accounts', 'chart_of_accounts.id', '=', 'journal_lines.account_id')
            ->where('account_type', 'asset')->sum(DB::raw('debit - credit'));
        // kas 5.000.000 - 400.000 + persediaan 200.000 + aset 6.000.000 - akumulasi 200.000
        $this->assertEquals(10600000.0, $assets);
    }

    public function test_only_owner_can_record_capital_and_future_date_is_rejected(): void
    {
        $this->setupOwner();
        $this->post(route('reports.equity.store'), ['kind' => 'draw', 'method' => 'cash', 'amount' => 1000, 'entry_date' => now()->addDay()->toDateString()])
            ->assertSessionHasErrors('entry_date');
    }

    public function test_acquisition_cannot_be_posted_twice(): void
    {
        $this->setupOwner();
        $this->post(route('assets.store'), [
            'name' => 'Rak', 'category' => 'furniture', 'purchase_date' => now()->toDateString(), 'purchase_cost' => 1000000,
            'useful_life_months' => 24, 'funding' => 'bank_transfer',
        ]);
        $asset = DB::table('business_assets')->first();
        $this->assertNotNull($asset->acquisition_journal_entry_id);

        $this->post(route('assets.acquisition.store', $asset->id), ['funding' => 'cash'])->assertStatus(422);
    }
}
