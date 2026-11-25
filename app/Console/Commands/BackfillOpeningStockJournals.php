<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class BackfillOpeningStockJournals extends Command
{
    protected $signature = 'accounting:backfill-opening-stock {--tenant= : Hanya proses satu tenant (UUID)} {--dry-run : Tampilkan saja tanpa menyimpan}';

    protected $description = 'Buat jurnal stok awal (Dr Persediaan / Cr Modal Pemilik) untuk produk lama yang belum punya jurnal.';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $movements = DB::table('stock_movements')->where('movement_type', 'opening')
            ->when($this->option('tenant'), fn ($query, $tenantId) => $query->where('tenant_id', $tenantId))
            ->orderBy('occurred_at')->get();

        $created = 0;
        $skipped = 0;
        $total = 0.0;
        foreach ($movements as $movement) {
            $value = round((float) ($movement->value_delta ?? ((float) $movement->quantity_delta * (float) $movement->unit_cost)), 2);
            $key = 'opening:'.$movement->product_variant_id.':journal';
            if ($value <= 0 || DB::table('journal_entries')->where('tenant_id', $movement->tenant_id)->where('idempotency_key', $key)->exists()) {
                $skipped++;

                continue;
            }
            $created++;
            $total += $value;
            if ($dryRun) {
                $this->line('[dry-run] '.$movement->tenant_id.' varian '.$movement->product_variant_id.' Rp '.number_format($value, 0, ',', '.'));

                continue;
            }
            DB::transaction(fn () => $this->postJournal($movement, $value, $key));
        }

        $this->info(($dryRun ? 'Simulasi: ' : '').$created.' jurnal stok awal '.($dryRun ? 'akan dibuat' : 'dibuat').', '.$skipped.' dilewati, total Rp '.number_format($total, 0, ',', '.').'.');

        return self::SUCCESS;
    }

    private function postJournal(object $movement, float $value, string $key): void
    {
        $now = now();
        $inventory = $this->account($movement->tenant_id, '1100', 'Persediaan', 'asset', 'debit', $now);
        $equity = $this->account($movement->tenant_id, '3010', 'Modal Pemilik', 'equity', 'credit', $now);
        $entryId = (string) Str::uuid();
        $date = substr((string) $movement->occurred_at, 0, 10) ?: $now->toDateString();
        DB::table('journal_entries')->insert([
            'id' => $entryId, 'tenant_id' => $movement->tenant_id, 'outlet_id' => $movement->outlet_id,
            'entry_number' => 'OPN-'.Str::upper(Str::random(8)), 'entry_date' => $date, 'status' => 'posted',
            'description' => 'Stok awal (migrasi data lama)', 'source_type' => 'product_opening', 'source_id' => $movement->source_id,
            'idempotency_key' => $key, 'posted_at' => $now, 'created_at' => $now, 'updated_at' => $now,
        ]);
        foreach ([[$inventory, $value, 0], [$equity, 0, $value]] as [$accountId, $debit, $credit]) {
            DB::table('journal_lines')->insert([
                'id' => (string) Str::uuid(), 'tenant_id' => $movement->tenant_id, 'journal_entry_id' => $entryId, 'account_id' => $accountId,
                'product_variant_id' => $movement->product_variant_id, 'description' => 'Stok awal (migrasi data lama)',
                'debit' => $debit, 'credit' => $credit, 'created_at' => $now, 'updated_at' => $now,
            ]);
        }
    }

    private function account(string $tenantId, string $code, string $name, string $type, string $normal, $now): string
    {
        $account = DB::table('chart_of_accounts')->where('tenant_id', $tenantId)->where('code', $code)->first();
        if ($account) {
            return $account->id;
        }
        $id = (string) Str::uuid();
        DB::table('chart_of_accounts')->insert([
            'id' => $id, 'tenant_id' => $tenantId, 'code' => $code, 'name' => $name, 'account_type' => $type, 'normal_balance' => $normal,
            'is_system' => true, 'allow_manual_posting' => false, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now,
        ]);

        return $id;
    }
}
