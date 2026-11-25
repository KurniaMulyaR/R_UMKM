<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class StockTransferTest extends TestCase
{
    use RefreshDatabase;

    private string $mainOutletId;

    private string $branchId;

    private string $variantId;

    private function prepare(): void
    {
        $this->post('/setup', [
            'owner_name' => 'Pemilik Uji', 'business_name' => 'Warung Uji', 'business_type' => 'grocery',
            'phone' => '081234567890', 'password' => 'rahasia123', 'password_confirmation' => 'rahasia123',
        ]);
        $this->post(route('products.store'), ['name' => 'Gula 1kg', 'unit_id' => DB::table('units')->value('id'), 'price' => 20000, 'cost_price' => 10000, 'initial_stock' => 20]);
        $this->variantId = DB::table('product_variants')->value('id');
        $this->mainOutletId = DB::table('outlets')->value('id');
        $this->post(route('outlets.store'), ['name' => 'Cabang Dua', 'code' => 'cb2'])->assertSessionHasNoErrors();
        $this->branchId = DB::table('outlets')->where('code', 'CB2')->value('id');
    }

    private function account(string $code): float
    {
        return (float) DB::table('journal_lines')->join('chart_of_accounts', 'chart_of_accounts.id', '=', 'journal_lines.account_id')
            ->where('code', $code)->sum(DB::raw('debit - credit'));
    }

    private function onHand(string $outletId): float
    {
        return (float) DB::table('stock_balances')->where('outlet_id', $outletId)->where('product_variant_id', $this->variantId)->value('quantity_on_hand');
    }

    public function test_outlet_creation_makes_cash_accounts_and_switching_works(): void
    {
        $this->prepare();
        $this->assertSame(3, DB::table('cash_accounts')->where('outlet_id', $this->branchId)->count());
        $this->post(route('outlets.store'), ['name' => 'Dobel', 'code' => 'CB2'])->assertSessionHasErrors('code');
        $this->post(route('outlets.switch', $this->branchId))->assertSessionHasNoErrors();
        $this->assertSame($this->branchId, session('active_outlet_id'));
    }

    public function test_send_moves_value_to_in_transit_and_receive_completes_it(): void
    {
        $this->prepare();
        $this->post(route('stock-transfers.store'), ['to_outlet_id' => $this->branchId, 'items' => [$this->variantId => 8]])->assertSessionHasNoErrors();
        $transfer = DB::table('stock_transfers')->first();
        $this->assertSame('in_transit', $transfer->status);
        $this->assertSame(12.0, $this->onHand($this->mainOutletId));
        $this->assertEquals(80000.0, $this->account('1110'));
        $this->assertEquals(120000.0, $this->account('1100'));

        $this->post(route('outlets.switch', $this->branchId));
        $this->post(route('stock-transfers.receive', $transfer->id), ['received' => [DB::table('stock_transfer_items')->value('id') => 8]])->assertSessionHasNoErrors();
        $this->assertSame(8.0, $this->onHand($this->branchId));
        $this->assertEquals(0.0, $this->account('1110'));
        $this->assertEquals(200000.0, $this->account('1100'));
        $this->assertSame('received', DB::table('stock_transfers')->value('status'));
    }

    public function test_shortage_on_receive_is_expensed_and_ledger_stays_balanced(): void
    {
        $this->prepare();
        $this->post(route('stock-transfers.store'), ['to_outlet_id' => $this->branchId, 'items' => [$this->variantId => 10]]);
        $this->post(route('outlets.switch', $this->branchId));
        $this->post(route('stock-transfers.receive', DB::table('stock_transfers')->value('id')), ['received' => [DB::table('stock_transfer_items')->value('id') => 7]])->assertSessionHasNoErrors();

        $this->assertSame(7.0, $this->onHand($this->branchId));
        $this->assertEquals(30000.0, $this->account('5020'));
        $this->assertEquals(0.0, $this->account('1110'));
        $this->assertEquals((float) DB::table('stock_balances')->sum('inventory_value'), $this->account('1100'));
        $this->assertEquals(30000.0, (float) DB::table('stock_transfers')->value('shortage_value'));
        $this->post(route('stock-transfers.receive', DB::table('stock_transfers')->value('id')))->assertStatus(422);
    }

    public function test_cancel_returns_stock_and_clears_in_transit(): void
    {
        $this->prepare();
        $this->post(route('stock-transfers.store'), ['to_outlet_id' => $this->branchId, 'items' => [$this->variantId => 5]]);
        $this->post(route('stock-transfers.cancel', DB::table('stock_transfers')->value('id')))->assertSessionHasNoErrors();
        $this->assertSame(20.0, $this->onHand($this->mainOutletId));
        $this->assertEquals(0.0, $this->account('1110'));
        $this->assertEquals(200000.0, $this->account('1100'));
        $this->assertSame('cancelled', DB::table('stock_transfers')->value('status'));
    }

    public function test_cannot_send_more_than_on_hand_or_to_same_outlet(): void
    {
        $this->prepare();
        $this->post(route('stock-transfers.store'), ['to_outlet_id' => $this->branchId, 'items' => [$this->variantId => 25]])->assertSessionHasErrors('items');
        $this->post(route('stock-transfers.store'), ['to_outlet_id' => $this->mainOutletId, 'items' => [$this->variantId => 1]])->assertStatus(422);
        $this->assertSame(0, DB::table('stock_transfers')->count());
        $this->assertSame(20.0, $this->onHand($this->mainOutletId));
    }
}
