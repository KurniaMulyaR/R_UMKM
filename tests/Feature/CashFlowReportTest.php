<?php

namespace Tests\Feature;

use App\Http\Controllers\CashFlowController;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class CashFlowReportTest extends TestCase
{
    use RefreshDatabase;

    private function prepare(): void
    {
        $this->post('/setup', [
            'owner_name' => 'Pemilik Uji', 'business_name' => 'Warung Uji', 'business_type' => 'grocery',
            'phone' => '081234567890', 'password' => 'rahasia123', 'password_confirmation' => 'rahasia123',
        ]);
        $this->post(route('products.store'), ['name' => 'Teh', 'unit_id' => DB::table('units')->value('id'), 'price' => 10000, 'cost_price' => 6000, 'initial_stock' => 10]);
        $this->post(route('shifts.open'), ['opening_cash' => 0]);
    }

    public function test_sections_split_operating_and_financing_and_reconcile_with_balances(): void
    {
        $this->prepare();
        $today = now()->toDateString();
        $this->post(route('reports.equity.store'), ['kind' => 'capital', 'method' => 'cash', 'amount' => 1000000, 'entry_date' => $today])->assertSessionHasNoErrors();
        $this->post(route('pos.checkout'), [
            'items' => [['variant_id' => DB::table('product_variants')->value('id'), 'quantity' => 3]], 'cash_received' => 30000,
            'payments' => [['method' => 'cash', 'amount' => 30000]],
        ])->assertSessionHasNoErrors();
        $this->post(route('expenses.store'), ['category' => '6020', 'amount' => 5000, 'method' => 'cash', 'spent_at' => $today])->assertSessionHasNoErrors();

        $tenantId = DB::table('tenants')->value('id');
        $report = app(CashFlowController::class)->build($tenantId, $today, $today);

        $this->assertEquals(1000000.0, $report['totals']['financing']);
        $this->assertEquals(25000.0, $report['totals']['operating']);
        $this->assertEquals(0.0, $report['totals']['investing']);
        $this->assertEquals(1025000.0, $report['net']);
        $this->assertEquals(0.0, $report['opening']);
        $this->assertEquals($report['opening'] + $report['net'], $report['closing']);

        $this->get(route('reports.cash-flow'))->assertOk()->assertSee('Arus kas dari operasional');
        $this->get(route('reports.cash-flow', ['format' => 'csv']))->assertOk()->assertHeader('content-type', 'text/csv; charset=UTF-8');
    }

    public function test_period_before_activity_is_empty_and_opening_carries_prior_balance(): void
    {
        $this->prepare();
        $today = now()->toDateString();
        $this->post(route('reports.equity.store'), ['kind' => 'capital', 'method' => 'bank_transfer', 'amount' => 500000, 'entry_date' => $today]);
        $tenantId = DB::table('tenants')->value('id');
        $tomorrow = now()->addDay()->toDateString();
        $report = app(CashFlowController::class)->build($tenantId, $tomorrow, $tomorrow);
        $this->assertEquals(500000.0, $report['opening']);
        $this->assertEquals(0.0, $report['net']);
        $this->assertEquals(500000.0, $report['closing']);
        $this->assertSame([], $report['sections']['financing']);
    }
}
