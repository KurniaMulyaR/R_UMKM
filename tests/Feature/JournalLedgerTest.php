<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class JournalLedgerTest extends TestCase
{
    use RefreshDatabase;

    private function setupOwner(): void
    {
        $this->post('/setup', [
            'owner_name' => 'Pemilik Uji', 'business_name' => 'Warung Uji', 'business_type' => 'grocery',
            'phone' => '081234567890', 'password' => 'rahasia123', 'password_confirmation' => 'rahasia123',
        ]);
    }

    private function accountId(string $code): string
    {
        return DB::table('chart_of_accounts')->where('code', $code)->value('id');
    }

    public function test_manual_journal_must_balance_and_blocks_control_accounts(): void
    {
        $this->setupOwner();
        $date = now()->toDateString();
        $unbalanced = ['entry_date' => $date, 'description' => 'Uji', 'lines' => [
            ['account_id' => $this->accountId('1010'), 'debit' => 1000], ['account_id' => $this->accountId('4010'), 'credit' => 900],
        ]];
        $this->post(route('journals.store'), $unbalanced)->assertSessionHasErrors('lines');
        $this->assertSame(0, DB::table('journal_entries')->where('source_type', 'manual_journal')->count());

        $control = ['entry_date' => $date, 'description' => 'Uji', 'lines' => [
            ['account_id' => $this->accountId('1100'), 'debit' => 1000], ['account_id' => $this->accountId('4010'), 'credit' => 1000],
        ]];
        $this->post(route('journals.store'), $control)->assertSessionHasErrors('lines');

        $ok = ['entry_date' => $date, 'description' => 'Setoran', 'lines' => [
            ['account_id' => $this->accountId('1010'), 'debit' => 1000], ['account_id' => $this->accountId('4010'), 'credit' => 1000],
        ]];
        $this->post(route('journals.store'), $ok)->assertSessionHasNoErrors();
        $this->assertSame(1, DB::table('journal_entries')->where('source_type', 'manual_journal')->count());
    }

    public function test_csv_import_is_all_or_nothing_and_idempotent(): void
    {
        $this->setupOwner();
        $good = "tanggal,nomor_jurnal,keterangan,kode_akun,debit,kredit,memo\n".now()->toDateString().",A1,Saldo awal,1010,5000000,0,\n".now()->toDateString().",A1,Saldo awal,3010,0,5000000,\n";
        $bad = $good.now()->toDateString().",B1,Salah,1010,100,0,\n".now()->toDateString().",B1,Salah,3010,0,50,\n";

        $this->post(route('journals.import.store'), ['file' => UploadedFile::fake()->createWithContent('j.csv', $bad)])->assertSessionHasErrors('file');
        $this->assertSame(0, DB::table('journal_entries')->where('source_type', 'manual_import')->count());

        $this->post(route('journals.import.store'), ['file' => UploadedFile::fake()->createWithContent('j.csv', $good)])->assertSessionHasNoErrors();
        $this->assertSame(1, DB::table('journal_entries')->where('entry_number', 'IMP-A1')->count());

        $this->post(route('journals.import.store'), ['file' => UploadedFile::fake()->createWithContent('j.csv', $good)])->assertSessionHasErrors('file');
        $this->assertSame(1, DB::table('journal_entries')->where('source_type', 'manual_import')->count());
    }

    public function test_ledger_shows_running_balance_and_opening(): void
    {
        $this->setupOwner();
        $cash = $this->accountId('1010');
        $this->post(route('reports.equity.store'), ['kind' => 'capital', 'method' => 'cash', 'amount' => 1000000, 'entry_date' => now()->subMonths(2)->toDateString()]);
        $this->post(route('reports.equity.store'), ['kind' => 'draw', 'method' => 'cash', 'amount' => 250000, 'entry_date' => now()->toDateString()]);

        $response = $this->get(route('ledger.index', ['account' => $cash, 'from' => now()->startOfMonth()->toDateString(), 'to' => now()->toDateString()]))->assertOk();
        $this->assertEquals(1000000.0, $response->viewData('opening'));
        $this->assertEquals(750000.0, $response->viewData('closing'));
        $this->assertCount(1, $response->viewData('rows'));
    }

    public function test_period_lock_blocks_manual_journal(): void
    {
        $this->setupOwner();
        $lastMonth = now()->startOfMonth()->subMonth();
        $this->post(route('reports.periods.close'), ['period' => $lastMonth->format('Y-m')]);
        $payload = ['entry_date' => $lastMonth->copy()->day(5)->toDateString(), 'description' => 'Telat', 'lines' => [
            ['account_id' => $this->accountId('1010'), 'debit' => 1000], ['account_id' => $this->accountId('4010'), 'credit' => 1000],
        ]];
        $this->post(route('journals.store'), $payload)->assertSessionHasErrors('period');
    }
}
