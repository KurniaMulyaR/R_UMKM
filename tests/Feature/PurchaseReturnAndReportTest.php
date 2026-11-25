<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PurchaseReturnAndReportTest extends TestCase
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

    private function receivedBill(): object
    {
        $this->post('/setup', [
            'owner_name' => 'Pemilik Uji', 'business_name' => 'Warung Uji', 'business_type' => 'grocery',
            'phone' => '081234567890', 'password' => 'rahasia123', 'password_confirmation' => 'rahasia123',
        ]);
        $this->post(route('purchasing.suppliers.store'), ['name' => 'CV Sumber Rejeki', 'payment_terms_days' => 14]);
        $this->post(route('products.store'), ['name' => 'Gula 1kg', 'unit_id' => DB::table('units')->value('id'), 'price' => 20000, 'cost_price' => 10000, 'initial_stock' => 0]);
        $this->post(route('purchasing.store'), [
            'supplier_id' => DB::table('suppliers')->value('id'),
            'items' => [['variant_id' => DB::table('product_variants')->value('id'), 'quantity' => 10, 'unit_cost' => 12000]],
        ]);
        $order = DB::table('purchase_orders')->first();
        $this->post(route('purchasing.approve', $order->id));
        $this->post(route('purchasing.receive', $order->id), ['quantities' => [DB::table('purchase_order_items')->value('id') => 10]]);

        return DB::table('supplier_bills')->first();
    }

    public function test_purchase_return_reduces_stock_bill_and_payable(): void
    {
        $bill = $this->receivedBill();
        $item = DB::table('supplier_bill_items')->first();

        $this->post(route('purchasing.return.store', $bill->id), ['quantities' => [$item->id => 3], 'reason' => 'Kemasan rusak'])->assertRedirect();

        $this->assertEquals(7.0, (float) DB::table('stock_balances')->value('quantity_on_hand'));
        $this->assertEquals(84000.0, (float) DB::table('stock_balances')->value('inventory_value'));
        $this->assertEquals(84000.0, (float) DB::table('supplier_bills')->value('balance_due'));
        $this->assertEquals(84000.0, $this->balance('1100'));
        $this->assertEquals(-84000.0, $this->balance('2010'));

        $this->post(route('purchasing.return.store', $bill->id), ['quantities' => [$item->id => 8], 'reason' => 'Lagi'])->assertSessionHasErrors('quantities');
        $this->assertSame(1, DB::table('purchase_returns')->count());
    }

    public function test_return_on_paid_bill_creates_refund_that_can_be_received(): void
    {
        $bill = $this->receivedBill();
        $item = DB::table('supplier_bill_items')->first();
        $this->post(route('purchasing.pay', $bill->id), ['method' => 'bank_transfer', 'amount' => 120000]);
        $this->assertSame('paid', DB::table('supplier_bills')->value('status'));

        $this->post(route('purchasing.return.store', $bill->id), ['quantities' => [$item->id => 5], 'reason' => 'Rusak'])->assertRedirect();
        $return = DB::table('purchase_returns')->first();
        $this->assertEquals(60000.0, (float) $return->refund_due);
        $this->assertEquals(60000.0, $this->balance('1210'));
        $this->assertEquals(0.0, (float) DB::table('supplier_bills')->value('balance_due'));

        $this->post(route('purchasing.return.refund', $return->id), ['method' => 'cash', 'amount' => 70000])->assertSessionHasErrors('amount');
        $this->post(route('purchasing.return.refund', $return->id), ['method' => 'cash', 'amount' => 60000])->assertRedirect();
        $this->assertEquals(0.0, $this->balance('1210'));
        $this->assertEquals(60000.0, (float) DB::table('purchase_returns')->value('refunded_total'));
    }

    public function test_return_spanning_balance_and_refund_splits_the_journal(): void
    {
        $bill = $this->receivedBill();
        $item = DB::table('supplier_bill_items')->first();
        $this->post(route('purchasing.pay', $bill->id), ['method' => 'cash', 'amount' => 100000]);

        $this->post(route('purchasing.return.store', $bill->id), ['quantities' => [$item->id => 5], 'reason' => 'Rusak'])->assertRedirect();

        $this->assertEquals(40000.0, (float) DB::table('purchase_returns')->value('refund_due'));
        $this->assertEquals(0.0, $this->balance('2010'));
        $this->assertEquals(40000.0, $this->balance('1210'));
        $this->assertEquals(60000.0, (float) DB::table('supplier_bills')->value('total'));
    }

    public function test_aging_and_profit_loss_pages_render_from_journals(): void
    {
        $this->receivedBill();
        $this->get(route('purchasing.aging'))->assertOk()->assertSee('CV Sumber Rejeki')->assertSee('Rp 120.000');

        $this->post(route('expenses.store'), ['category' => '6020', 'amount' => 50000, 'method' => 'cash', 'spent_at' => now()->toDateString()]);
        $this->get(route('reports.profit-loss'))->assertOk()->assertSee('Sewa tempat')->assertSee('Rp 50.000')->assertSee('Laba bersih');
    }
}
