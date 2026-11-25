<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ReconciliationTest extends TestCase
{
    use RefreshDatabase;

    private function setupWithBankMovements(): string
    {
        $this->post('/setup', [
            'owner_name' => 'Pemilik Uji', 'business_name' => 'Warung Uji', 'business_type' => 'grocery',
            'phone' => '081234567890', 'password' => 'rahasia123', 'password_confirmation' => 'rahasia123',
        ]);
        $this->post(route('reports.equity.store'), ['kind' => 'capital', 'method' => 'bank_transfer', 'amount' => 1000000, 'entry_date' => now()->subDay()->toDateString()]);
        $this->post(route('reports.equity.store'), ['kind' => 'draw', 'method' => 'bank_transfer', 'amount' => 200000, 'entry_date' => now()->toDateString()]);

        return DB::table('chart_of_accounts')->where('code', '1020')->value('id');
    }

    private function bankLineIds(string $accountId): array
    {
        return DB::table('journal_lines')->where('account_id', $accountId)->pluck('id')->all();
    }

    public function test_reconciliation_completes_only_when_difference_is_zero(): void
    {
        $accountId = $this->setupWithBankMovements();
        $this->post(route('reconciliations.store'), ['account_id' => $accountId, 'statement_date' => now()->toDateString(), 'statement_balance' => 800000]);
        $recId = DB::table('bank_reconciliations')->value('id');
        $lines = $this->bankLineIds($accountId);
        $this->assertCount(2, $lines);

        $this->put(route('reconciliations.update', $recId), ['lines' => [$lines[0]], 'complete' => 1])->assertSessionHasErrors('lines');
        $this->assertSame('draft', DB::table('bank_reconciliations')->where('id', $recId)->value('status'));

        $this->put(route('reconciliations.update', $recId), ['lines' => $lines, 'complete' => 1])->assertSessionHasNoErrors();
        $this->assertSame('completed', DB::table('bank_reconciliations')->where('id', $recId)->value('status'));
        $this->assertSame(2, DB::table('reconciled_journal_lines')->count());
    }

    public function test_cleared_lines_are_not_offered_again_and_second_draft_is_blocked(): void
    {
        $accountId = $this->setupWithBankMovements();
        $this->post(route('reconciliations.store'), ['account_id' => $accountId, 'statement_date' => now()->toDateString(), 'statement_balance' => 800000]);
        $firstId = DB::table('bank_reconciliations')->value('id');
        $this->post(route('reconciliations.store'), ['account_id' => $accountId, 'statement_date' => now()->toDateString(), 'statement_balance' => 1])->assertSessionHasErrors('account_id');
        $this->put(route('reconciliations.update', $firstId), ['lines' => $this->bankLineIds($accountId), 'complete' => 1]);

        $this->post(route('reconciliations.store'), ['account_id' => $accountId, 'statement_date' => now()->toDateString(), 'statement_balance' => 800000])->assertSessionHasNoErrors();
        $secondId = DB::table('bank_reconciliations')->where('status', 'draft')->value('id');
        $response = $this->get(route('reconciliations.show', $secondId))->assertOk();
        $this->assertCount(0, $response->viewData('candidates'));
        $this->assertEquals(0.0, $response->viewData('difference'));
    }
}
