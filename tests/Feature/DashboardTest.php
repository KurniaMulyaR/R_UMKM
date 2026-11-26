<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use RefreshDatabase;

    private string $variantId;

    private function prepare(): void
    {
        $this->post('/setup', [
            'owner_name' => 'Pemilik Uji', 'business_name' => 'Warung Uji', 'business_type' => 'grocery',
            'phone' => '081234567890', 'password' => 'rahasia123', 'password_confirmation' => 'rahasia123',
        ]);
        $this->post(route('products.store'), ['name' => 'Teh', 'unit_id' => DB::table('units')->value('id'), 'price' => 10000, 'cost_price' => 6000, 'initial_stock' => 10]);
        $this->post(route('shifts.open'), ['opening_cash' => 0]);
        $this->variantId = DB::table('product_variants')->value('id');
        $this->post(route('pos.checkout'), ['items' => [['variant_id' => $this->variantId, 'quantity' => 4]], 'cash_received' => 40000, 'payments' => [['method' => 'cash', 'amount' => 40000]]])->assertSessionHasNoErrors();
    }

    private function overview(string $range = 'today'): array
    {
        $html = $this->get(route('dashboard', ['range' => $range]))->assertOk()->getContent();
        preg_match('/<script id="dashboard-bootstrap" type="application\/json">(.*?)<\/script>/s', $html, $match);

        return json_decode(html_entity_decode($match[1]), true);
    }

    public function test_gross_profit_is_reduced_by_returns_and_restocked_cost(): void
    {
        $this->prepare();
        $this->assertEquals(40000.0, $this->overview()['summary']['revenue']);
        $this->assertEquals(16000.0, $this->overview()['summary']['grossProfit']);

        $itemId = DB::table('sale_items')->value('id');
        $this->post(route('sales.return.store', DB::table('sales')->value('id')), ['reason' => 'Salah beli', 'refund_method' => 'cash', 'items' => [$itemId => ['quantity' => 1, 'condition' => 'good']]])->assertSessionHasNoErrors();
        $summary = $this->overview()['summary'];
        $this->assertEquals(30000.0, $summary['revenue']);
        $this->assertEquals(12000.0, $summary['grossProfit']);
    }

    public function test_period_selector_changes_label_and_range(): void
    {
        $this->prepare();
        DB::table('sales')->update(['sold_at' => now()->subDays(3)]);
        $this->assertEquals(0.0, $this->overview('today')['summary']['revenue']);
        $week = $this->overview('7')['summary'];
        $this->assertEquals(40000.0, $week['revenue']);
        $this->assertSame('7 hari terakhir', $week['periodLabel']);
        $this->assertSame('hari ini', $this->overview('bogus')['summary']['periodLabel']);
    }

    public function test_financial_position_cards_are_shown_to_owner(): void
    {
        $this->prepare();
        $this->get(route('dashboard'))->assertOk()->assertSee('Hutang ke supplier')->assertSee('Nilai persediaan outlet ini')->assertSee('Rp 36.000');
    }
}
