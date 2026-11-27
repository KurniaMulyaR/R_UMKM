<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ThermalReceiptTest extends TestCase
{
    use RefreshDatabase;

    private string $variantId;

    private function prepare(): void
    {
        $this->post('/setup', [
            'owner_name' => 'Pemilik Uji', 'business_name' => 'Warung Uji', 'business_type' => 'grocery',
            'phone' => '081234567890', 'password' => 'rahasia123', 'password_confirmation' => 'rahasia123',
        ]);
        $this->post(route('products.store'), ['name' => 'Teh', 'unit_id' => DB::table('units')->value('id'), 'price' => 5000, 'cost_price' => 3000, 'initial_stock' => 10]);
        $this->post(route('shifts.open'), ['opening_cash' => 0]);
        $this->variantId = DB::table('product_variants')->value('id');
    }

    private function checkout(array $extra = [])
    {
        return $this->post(route('pos.checkout'), ['items' => [['variant_id' => $this->variantId, 'quantity' => 2]], 'cash_received' => 20000, 'payments' => [['method' => 'cash', 'amount' => 10000]]] + $extra);
    }

    public function test_checkout_without_print_flag_goes_to_sale_detail(): void
    {
        $this->prepare();
        $this->checkout()->assertRedirect(route('sales.show', DB::table('sales')->value('id')));
    }

    public function test_print_flag_redirects_to_thermal_receipt_with_auto_print(): void
    {
        $this->prepare();
        $this->checkout(['print_receipt' => 1]);
        $saleId = DB::table('sales')->value('id');
        $sale = DB::table('sales')->first();
        $page = $this->get(route('documents.thermal', ['id' => $saleId, 'auto' => 1]))->assertOk();
        $page->assertSee($sale->sale_number)->assertSee('TOTAL')->assertSee('window.print()', false)->assertSee('Warung Uji');
        $page->assertSee('Tgl : '.\Illuminate\Support\Carbon::parse($sale->sold_at, 'Asia/Jakarta')->format('d/m/Y H:i'));
        $page->assertSee('size: 80mm auto', false);
        $this->get(route('documents.thermal', ['id' => $saleId, 'w' => 58]))->assertOk()->assertSee('size: 58mm auto', false)->assertDontSee('window.print(); });', false);
    }

    public function test_receipt_width_setting_is_default_and_voided_sale_is_blocked(): void
    {
        $this->prepare();
        $this->put(route('settings.update'), ['name' => 'Warung Uji', 'timezone' => 'Asia/Jakarta', 'receipt_width' => 58])->assertSessionHasNoErrors();
        $this->checkout();
        $saleId = DB::table('sales')->value('id');
        $this->get(route('documents.thermal', $saleId))->assertOk()->assertSee('size: 58mm auto', false);
        $this->post(route('sales.void', $saleId), ['reason' => 'Salah input'])->assertSessionHasNoErrors();
        $this->get(route('documents.thermal', $saleId))->assertStatus(422);
    }
}
