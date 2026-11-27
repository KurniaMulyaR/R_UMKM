<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class CustomerCreditTest extends TestCase
{
    use RefreshDatabase;

    private string $variantId;

    private string $customerId;

    private function prepare(?float $limit = null): void
    {
        $this->post('/setup', [
            'owner_name' => 'Pemilik Uji', 'business_name' => 'Warung Uji', 'business_type' => 'grocery',
            'phone' => '081234567890', 'password' => 'rahasia123', 'password_confirmation' => 'rahasia123',
        ]);
        $this->post(route('products.store'), ['name' => 'Teh', 'unit_id' => DB::table('units')->value('id'), 'price' => 10000, 'cost_price' => 6000, 'initial_stock' => 50]);
        $this->post(route('shifts.open'), ['opening_cash' => 100000]);
        $this->variantId = DB::table('product_variants')->value('id');
        $this->post(route('customers.store'), ['name' => 'Bu Rina', 'credit_limit' => $limit]);
        $this->customerId = DB::table('customers')->value('id');
        if ($limit !== null) {
            DB::table('customers')->where('id', $this->customerId)->update(['credit_limit' => $limit]);
        }
    }

    private function creditSale(int $qty)
    {
        return $this->post(route('pos.checkout'), [
            'items' => [['variant_id' => $this->variantId, 'quantity' => $qty]], 'customer_id' => $this->customerId,
            'payments' => [['method' => 'credit', 'amount' => $qty * 10000]],
        ]);
    }

    public function test_credit_sale_over_limit_is_rejected_and_within_limit_passes(): void
    {
        $this->prepare(50000);
        $this->creditSale(3)->assertSessionHasNoErrors();
        $this->creditSale(3)->assertSessionHasErrors('customer_id');
        $this->assertSame(1, DB::table('sales')->count());
        $this->creditSale(2)->assertSessionHasNoErrors();
        $this->assertSame(2, DB::table('sales')->count());
    }

    public function test_settlement_frees_credit_room_and_no_limit_means_unlimited(): void
    {
        $this->prepare(30000);
        $this->creditSale(3)->assertSessionHasNoErrors();
        $this->creditSale(1)->assertSessionHasErrors('customer_id');
        $saleId = DB::table('sales')->value('id');
        $this->post(route('sales.payments.store', $saleId), ['method' => 'cash', 'amount' => 10000])->assertSessionHasNoErrors();
        $this->creditSale(1)->assertSessionHasNoErrors();

        DB::table('customers')->where('id', $this->customerId)->update(['credit_limit' => null]);
        $this->assertNull(DB::table('customers')->value('credit_limit'));
        $this->creditSale(10)->assertSessionHasNoErrors();
    }

    public function test_aging_report_buckets_by_sale_age(): void
    {
        $this->prepare(null);
        $this->creditSale(2);
        $this->creditSale(1);
        $old = DB::table('sales')->orderBy('created_at')->first();
        DB::table('sales')->where('id', $old->id)->update(['sold_at' => now()->subDays(45)]);

        $response = $this->get(route('customers.aging'))->assertOk();
        $response->assertSee('Bu Rina');
        $this->assertEquals(30000.0, (float) CustomerCreditTest::totalFor($this->customerId));
        $response->assertSee('31–60 hari');
    }

    public static function totalFor(string $customerId): float
    {
        return \App\Http\Controllers\CustomerController::outstandingFor(DB::table('customers')->where('id', $customerId)->value('tenant_id'), $customerId);
    }
}
