<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

class PlatformPanelTest extends TestCase
{
    use RefreshDatabase;

    private function registerTenant(string $business, string $phone): string
    {
        $this->post('/setup', [
            'owner_name' => 'Pemilik '.$business, 'business_name' => $business, 'business_type' => 'grocery',
            'phone' => $phone, 'password' => 'rahasia123', 'password_confirmation' => 'rahasia123',
        ])->assertSessionHasNoErrors();
        $this->post('/logout');

        return (string) DB::table('tenants')->where('name', $business)->value('id');
    }

    private function actAsPlatformAdmin(): string
    {
        $id = (string) Str::uuid();
        DB::table('users')->insert([
            'id' => $id, 'name' => 'Admin Platform', 'email' => 'admin@rkasir.test', 'password' => Hash::make('rahasia123'),
            'status' => 'active', 'is_platform_admin' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->post('/login', ['identity' => 'admin@rkasir.test', 'password' => 'rahasia123'])->assertRedirect(route('platform.dashboard'));

        return $id;
    }

    public function test_tenant_owner_cannot_open_platform_panel(): void
    {
        $this->post('/setup', [
            'owner_name' => 'Pemilik', 'business_name' => 'Warung A', 'business_type' => 'grocery',
            'phone' => '081200000001', 'password' => 'rahasia123', 'password_confirmation' => 'rahasia123',
        ]);

        $this->get(route('platform.dashboard'))->assertForbidden();
    }

    public function test_platform_admin_sees_dashboard_and_tenant_list(): void
    {
        $this->registerTenant('Warung A', '081200000001');
        $this->registerTenant('Warung B', '081200000002');
        $this->actAsPlatformAdmin();

        $this->get(route('platform.dashboard'))->assertOk()->assertSee('Usaha terdaftar');
        $this->get(route('platform.tenants', ['q' => 'Warung A']))->assertOk()->assertSee('Warung A')->assertDontSee('Warung B');
    }

    public function test_suspend_requires_reason_blocks_tenant_and_is_logged_to_the_store(): void
    {
        $tenantId = $this->registerTenant('Warung A', '081200000001');
        $this->actAsPlatformAdmin();

        $this->post(route('platform.tenants.suspend', $tenantId), ['reason' => ''])->assertSessionHasErrors('reason');
        $this->post(route('platform.tenants.suspend', $tenantId), ['reason' => 'Tunggakan langganan'])->assertSessionHasNoErrors();

        $this->assertSame('suspended', DB::table('tenants')->where('id', $tenantId)->value('status'));
        $log = DB::table('audit_logs')->where('tenant_id', $tenantId)->where('action', 'platform.suspended')->first();
        $this->assertNotNull($log);
        $this->assertSame('Tunggakan langganan', $log->reason);

        $this->post('/logout');
        $this->post('/login', ['identity' => '081200000001', 'password' => 'rahasia123']);
        $this->get(route('dashboard'))->assertForbidden();

        $this->post('/logout');
        $this->post('/login', ['identity' => 'admin@rkasir.test', 'password' => 'rahasia123']);
        $this->post(route('platform.tenants.activate', $tenantId), ['reason' => 'Sudah bayar'])->assertSessionHasNoErrors();
        $this->assertSame('active', DB::table('tenants')->where('id', $tenantId)->value('status'));
    }

    public function test_plan_change_is_audited(): void
    {
        $tenantId = $this->registerTenant('Warung A', '081200000001');
        $this->actAsPlatformAdmin();

        $this->post(route('platform.tenants.plan', $tenantId), ['plan' => 'pro', 'plan_expires_at' => '2027-12-31', 'reason' => 'Upgrade'])->assertSessionHasNoErrors();

        $this->assertSame('pro', DB::table('tenants')->where('id', $tenantId)->value('plan'));
        $this->assertTrue(DB::table('audit_logs')->where('tenant_id', $tenantId)->where('action', 'platform.plan_changed')->exists());
    }

    public function test_ticket_flow_between_store_and_platform(): void
    {
        $tenantId = $this->registerTenant('Warung A', '081200000001');
        $this->post('/login', ['identity' => '081200000001', 'password' => 'rahasia123']);
        $this->post(route('support.store'), ['subject' => 'Printer tidak jalan', 'category' => 'bug', 'priority' => 'high', 'body' => 'Struk tidak keluar'])->assertSessionHasNoErrors();
        $ticketId = (string) DB::table('support_tickets')->where('tenant_id', $tenantId)->value('id');
        $this->assertNotSame('', $ticketId);

        $this->post('/logout');
        $this->actAsPlatformAdmin();
        $this->get(route('platform.tickets.index'))->assertOk()->assertSee('Printer tidak jalan');
        $this->post(route('platform.tickets.reply', $ticketId), ['body' => 'Coba cek koneksi Bluetooth'])->assertSessionHasNoErrors();
        $this->assertSame('in_progress', DB::table('support_tickets')->where('id', $ticketId)->value('status'));
        $this->post(route('platform.tickets.status', $ticketId), ['status' => 'closed']);
        $this->assertSame('closed', DB::table('support_tickets')->where('id', $ticketId)->value('status'));
    }

    public function test_store_cannot_read_another_stores_ticket(): void
    {
        $tenantA = $this->registerTenant('Warung A', '081200000001');
        $this->registerTenant('Warung B', '081200000002');
        $ticketId = (string) Str::uuid();
        DB::table('support_tickets')->insert(['id' => $ticketId, 'tenant_id' => $tenantA, 'subject' => 'Rahasia A', 'category' => 'other', 'priority' => 'normal', 'status' => 'open', 'last_message_at' => now(), 'created_at' => now(), 'updated_at' => now()]);

        $this->post('/login', ['identity' => '081200000002', 'password' => 'rahasia123']);
        $this->get(route('support.show', $ticketId))->assertNotFound();
    }
}
