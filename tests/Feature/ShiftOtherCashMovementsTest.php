<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ShiftOtherCashMovementsTest extends TestCase
{
    use RefreshDatabase;

    private function prepare(): void
    {
        $this->post('/setup', [
            'owner_name' => 'Pemilik Uji', 'business_name' => 'Warung Uji', 'business_type' => 'grocery',
            'phone' => '081234567890', 'password' => 'rahasia123', 'password_confirmation' => 'rahasia123',
        ]);
        $this->post(route('shifts.open'), ['opening_cash' => 100000]);
    }

    private function buyAsset(string $funding, float $cost): void
    {
        $this->post(route('assets.store'), [
            'name' => 'Rak '.$funding, 'category' => 'furniture', 'purchase_date' => now()->toDateString(), 'purchase_cost' => $cost,
            'useful_life_months' => 24, 'funding' => $funding,
        ])->assertSessionHasNoErrors();
    }

    public function test_cash_asset_purchase_reduces_expected_cash_but_bank_purchase_does_not(): void
    {
        $this->prepare();
        $this->buyAsset('cash', 30000);
        $this->buyAsset('bank_transfer', 500000);
        $this->post(route('shifts.close'), ['counted_cash' => 70000])->assertSessionHasNoErrors();
        $shift = DB::table('cash_shifts')->first();
        $this->assertEquals(70000.0, (float) $shift->expected_cash);
        $this->assertEquals(0.0, (float) $shift->cash_difference);
    }

    public function test_cash_capital_and_owner_draw_move_expected_cash(): void
    {
        $this->prepare();
        $today = now()->toDateString();
        $this->post(route('reports.equity.store'), ['kind' => 'capital', 'method' => 'cash', 'amount' => 50000, 'entry_date' => $today])->assertSessionHasNoErrors();
        $this->post(route('reports.equity.store'), ['kind' => 'draw', 'method' => 'cash', 'amount' => 20000, 'entry_date' => $today])->assertSessionHasNoErrors();
        $this->post(route('reports.equity.store'), ['kind' => 'capital', 'method' => 'bank_transfer', 'amount' => 900000, 'entry_date' => $today]);
        $this->post(route('shifts.close'), ['counted_cash' => 130000])->assertSessionHasNoErrors();
        $this->assertEquals(130000.0, (float) DB::table('cash_shifts')->value('expected_cash'));
    }

    public function test_acquisition_without_open_shift_does_not_touch_any_shift(): void
    {
        $this->prepare();
        $this->post(route('shifts.close'), ['counted_cash' => 100000]);
        $this->buyAsset('cash', 30000);
        $this->assertNull(DB::table('journal_entries')->where('source_type', 'asset_acquisition')->value('cash_shift_id'));
        $this->assertEquals(100000.0, (float) DB::table('cash_shifts')->value('expected_cash'));
    }
}
