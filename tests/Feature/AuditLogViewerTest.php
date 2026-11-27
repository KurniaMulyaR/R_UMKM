<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AuditLogViewerTest extends TestCase
{
    use RefreshDatabase;

    private function prepare(): void
    {
        $this->post('/setup', [
            'owner_name' => 'Pemilik Uji', 'business_name' => 'Warung Uji', 'business_type' => 'grocery',
            'phone' => '081234567890', 'password' => 'rahasia123', 'password_confirmation' => 'rahasia123',
        ]);
    }

    public function test_viewer_lists_logged_actions_and_filters_by_action(): void
    {
        $this->prepare();
        $this->post(route('outlets.store'), ['name' => 'Cabang Dua', 'code' => 'CB2'])->assertSessionHasNoErrors();
        $tenantId = DB::table('tenants')->value('id');
        DB::table('audit_logs')->insert([
            'id' => (string) \Illuminate\Support\Str::uuid(), 'tenant_id' => $tenantId, 'outlet_id' => DB::table('outlets')->value('id'),
            'actor_user_id' => auth()->id(), 'action' => 'member.updated', 'subject_type' => 'customer',
            'subject_id' => (string) \Illuminate\Support\Str::uuid(), 'occurred_at' => now(),
        ]);

        $this->get(route('audit-logs.index'))->assertOk()->assertSee('<td>outlet.created</td>', false)->assertSee('<td>member.updated</td>', false);
        $this->get(route('audit-logs.index', ['action' => 'outlet.created']))->assertOk()
            ->assertSee('<td>outlet.created</td>', false)->assertDontSee('<td>member.updated</td>', false);
    }

    public function test_only_current_tenant_logs_are_shown(): void
    {
        $this->prepare();
        DB::table('audit_logs')->insert(['id' => (string) \Illuminate\Support\Str::uuid(), 'tenant_id' => null, 'action' => 'foreign.secret_action', 'subject_type' => 'x', 'occurred_at' => now()]);
        $this->get(route('audit-logs.index'))->assertOk()->assertDontSee('foreign.secret_action');
    }
}
