<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PurchasingTest extends TestCase
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

    private function prepareOrder(): object
    {
        $this->post('/setup', [
            'owner_name' => 'Pemilik Uji', 'business_name' => 'Warung Uji', 'business_type' => 'grocery',
            'phone' => '081234567890', 'password' => 'rahasia123', 'password_confirmation' => 'rahasia123',
        ])->assertRedirect(route('dashboard'));

        $this->post(route('purchasing.suppliers.store'), ['name' => 'CV Sumber Rejeki', 'payment_terms_days' => 14])->assertRedirect();
        $this->post(route('products.store'), [
            'name' => 'Gula 1kg', 'unit_id' => DB::table('units')->value('id'), 'price' => 20000, 'cost_price' => 10000, 'initial_stock' => 10,
        ]);
        $variant = DB::table('product_variants')->first();

        $this->post(route('purchasing.store'), [
            'supplier_id' => DB::table('suppliers')->value('id'),
            'items' => [['variant_id' => $variant->id, 'quantity' => 10, 'unit_cost' => 12000]],
        ])->assertRedirect();

        return DB::table('purchase_orders')->first();
    }

    public function test_purchase_order_flows_from_draft_to_received_and_paid(): void
    {
        $order = $this->prepareOrder();
        $this->assertSame('draft', $order->status);
        $this->assertEquals(120000.0, (float) $order->total);

        $this->post(route('purchasing.approve', $order->id))->assertRedirect();
        $item = DB::table('purchase_order_items')->first();

        $this->post(route('purchasing.receive', $order->id), ['quantities' => [$item->id => 4]])
            ->assertRedirect(route('purchasing.show', $order->id));

        $this->assertSame('partially_received', DB::table('purchase_orders')->value('status'));
        $balance = DB::table('stock_balances')->first();
        $this->assertEquals(14.0, (float) $balance->quantity_on_hand);
        $this->assertEquals(148000.0, (float) $balance->inventory_value);
        $this->assertEquals(148000.0, $this->balance('1100'));
        $this->assertEquals(-48000.0, $this->balance('2010'));

        $bill = DB::table('supplier_bills')->first();
        $this->assertEquals(48000.0, (float) $bill->balance_due);

        $this->post(route('purchasing.pay', $bill->id), ['method' => 'cash', 'amount' => 20000])->assertRedirect();
        $this->assertSame('partial', DB::table('supplier_bills')->value('status'));
        $this->assertEquals(-28000.0, $this->balance('2010'));

        $this->post(route('purchasing.pay', $bill->id), ['method' => 'cash', 'amount' => 28000])->assertRedirect();
        $this->assertSame('paid', DB::table('supplier_bills')->value('status'));
        $this->assertEquals(0.0, $this->balance('2010'));
        $this->assertEquals(-48000.0, $this->balance('1010'));

        $this->post(route('purchasing.receive', $order->id), ['quantities' => [$item->id => 6]])->assertRedirect();
        $this->assertSame('received', DB::table('purchase_orders')->value('status'));
    }

    public function test_receiving_more_than_ordered_is_rejected(): void
    {
        $order = $this->prepareOrder();
        $this->post(route('purchasing.approve', $order->id));
        $item = DB::table('purchase_order_items')->first();

        $this->post(route('purchasing.receive', $order->id), ['quantities' => [$item->id => 11]])->assertSessionHasErrors('quantities');
        $this->assertSame(0, DB::table('goods_receipts')->count());
        $this->assertEquals(10.0, (float) DB::table('stock_balances')->value('quantity_on_hand'));
    }

    public function test_draft_order_cannot_receive_goods(): void
    {
        $order = $this->prepareOrder();
        $item = DB::table('purchase_order_items')->first();

        $this->post(route('purchasing.receive', $order->id), ['quantities' => [$item->id => 1]])->assertStatus(422);
    }
}
