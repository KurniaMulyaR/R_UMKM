<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AssetTest extends TestCase
{
    use RefreshDatabase;

    private function createAsset(): object
    {
        $this->post('/setup', [
            'owner_name' => 'Pemilik Uji', 'business_name' => 'Warung Uji', 'business_type' => 'grocery',
            'phone' => '081234567890', 'password' => 'rahasia123', 'password_confirmation' => 'rahasia123',
        ]);
        $this->post(route('assets.store'), [
            'name' => 'Freezer 2 Pintu', 'category' => 'equipment', 'purchase_date' => now()->subMonths(12)->toDateString(),
            'purchase_cost' => 6000000, 'useful_life_months' => 60, 'salvage_value' => 0, 'maintenance_interval_days' => 90, 'funding' => 'none',
        ])->assertRedirect(route('assets.index'));

        return DB::table('business_assets')->first();
    }

    public function test_asset_gets_code_age_and_straight_line_book_value(): void
    {
        $asset = $this->createAsset();
        $this->assertSame('AST-0001', $asset->code);

        $this->get(route('assets.show', $asset->id))->assertOk()
            ->assertSee('Freezer 2 Pintu')->assertSee('Rp 4.800.000')->assertSee('Rp 1.200.000');
    }

    public function test_maintenance_record_moves_next_due_date(): void
    {
        $asset = $this->createAsset();
        $this->post(route('assets.maintenance.store', $asset->id), [
            'performed_at' => now()->toDateString(), 'description' => 'Ganti kompresor', 'cost' => 350000,
        ])->assertRedirect();

        $this->assertSame(1, DB::table('asset_maintenance_records')->count());
        $this->assertSame(now()->addDays(90)->toDateString(), DB::table('business_assets')->value('next_maintenance_due'));
    }

    public function test_disposed_asset_rejects_maintenance(): void
    {
        $asset = $this->createAsset();
        $this->post(route('assets.dispose', $asset->id), ['reason' => 'Dijual'])->assertRedirect();
        $this->assertSame('disposed', DB::table('business_assets')->value('status'));

        $this->post(route('assets.maintenance.store', $asset->id), ['performed_at' => now()->toDateString(), 'description' => 'Servis'])->assertNotFound();
    }
}
