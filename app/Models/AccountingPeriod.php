<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Helper for the accounting period lock ("tutup periode").
 */
class AccountingPeriod
{
    /**
     * The last closed day for a tenant, or null when no period is closed.
     */
    public static function closedThrough(string $tenantId): ?string
    {
        $value = DB::table('accounting_periods')->where('tenant_id', $tenantId)->where('status', 'closed')->max('ends_on');

        return $value ? (string) $value : null;
    }

    /**
     * Reject any posting dated on or before the last closed month. Closing a month locks everything up to its end.
     *
     * @throws ValidationException
     */
    public static function assertOpen(string $tenantId, CarbonInterface|string $date): void
    {
        $closed = self::closedThrough($tenantId);
        $day = $date instanceof CarbonInterface ? $date->toDateString() : substr((string) $date, 0, 10);
        if ($closed !== null && $day <= $closed) {
            throw ValidationException::withMessages([
                'period' => 'Periode sampai '.date('d/m/Y', strtotime($closed)).' sudah ditutup. Buka kembali periode (khusus pemilik) untuk mengubah data di rentang itu.',
            ]);
        }
    }
}
