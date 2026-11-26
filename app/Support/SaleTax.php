<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * PPN penjualan (opsional per usaha). Pengaturan disimpan di tenants.settings:
 * ppn_enabled (bool), ppn_rate (persen), ppn_mode ('exclusive' = harga belum termasuk PPN, 'inclusive' = harga sudah termasuk PPN).
 */
class SaleTax
{
    public const ACCOUNT_CODE = '2030';

    /**
     * @return array{enabled: bool, rate: float, mode: string}
     */
    public static function settings(object $tenant): array
    {
        $settings = json_decode((string) ($tenant->settings ?? ''), true) ?: [];
        $rate = (float) ($settings['ppn_rate'] ?? 11);

        return [
            'enabled' => (bool) ($settings['ppn_enabled'] ?? false) && $rate > 0,
            'rate' => $rate,
            'mode' => ($settings['ppn_mode'] ?? 'exclusive') === 'inclusive' ? 'inclusive' : 'exclusive',
        ];
    }

    /**
     * Hitung PPN dari nilai setelah diskon. Eksklusif: total = nilai + PPN. Inklusif: total = nilai, PPN sudah di dalamnya.
     *
     * @param  array{enabled: bool, rate: float, mode: string}  $settings
     * @return array{tax: float, total: float}
     */
    public static function compute(array $settings, float $net): array
    {
        $net = round($net, 2);
        if (! $settings['enabled'] || $net <= 0) {
            return ['tax' => 0.0, 'total' => $net];
        }
        if ($settings['mode'] === 'inclusive') {
            return ['tax' => round($net * $settings['rate'] / (100 + $settings['rate']), 2), 'total' => $net];
        }
        $tax = round($net * $settings['rate'] / 100, 2);

        return ['tax' => $tax, 'total' => round($net + $tax, 2)];
    }

    /**
     * Bagi $amount ke beberapa bobot; baris terakhir menampung sisa pembulatan sehingga jumlahnya persis $amount.
     *
     * @param  array<int, float>  $weights
     * @return array<int, float>
     */
    public static function allocate(float $amount, array $weights): array
    {
        $result = [];
        $totalWeight = array_sum($weights);
        $allocated = 0.0;
        $last = count($weights) - 1;
        foreach (array_values($weights) as $index => $weight) {
            $share = $index === $last ? round($amount - $allocated, 2) : ($totalWeight > 0 ? round($amount * $weight / $totalWeight, 2) : 0.0);
            $allocated += $share;
            $result[$index] = $share;
        }

        return $result;
    }

    public static function account(string $tenantId): object
    {
        $account = DB::table('chart_of_accounts')->where('tenant_id', $tenantId)->where('code', self::ACCOUNT_CODE)->first();
        if ($account) {
            return $account;
        }
        DB::table('chart_of_accounts')->insert([
            'id' => (string) Str::uuid(), 'tenant_id' => $tenantId, 'code' => self::ACCOUNT_CODE, 'name' => 'Hutang PPN', 'account_type' => 'liability',
            'normal_balance' => 'credit', 'is_system' => true, 'allow_manual_posting' => false, 'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);

        return DB::table('chart_of_accounts')->where('tenant_id', $tenantId)->where('code', self::ACCOUNT_CODE)->first();
    }
}
