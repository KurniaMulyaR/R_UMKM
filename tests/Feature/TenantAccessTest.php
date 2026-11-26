<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class TenantAccessTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private User $staff;

    private function prepare(): void
    {
        $this->post('/setup', [
            'owner_name' => 'Pemilik Uji', 'business_name' => 'Warung Uji', 'business_type' => 'grocery',
            'phone' => '081234567890', 'password' => 'rahasia123', 'password_confirmation' => 'rahasia123',
        ]);
        $this->owner = User::where('phone', '081234567890')->firstOrFail();
        $this->post(route('staff.store'), ['name' => 'Budi', 'phone' => '081111111111', 'role' => 'cashier', 'password' => 'sandi12345', 'password_confirmation' => 'sandi12345'])->assertSessionHasNoErrors();
        $this->staff = User::where('phone', '081111111111')->firstOrFail();
    }

    private function roleKeyOf(User $user): string
    {
        return (string) DB::table('tenant_user')->join('tenant_user_roles', 'tenant_user_roles.tenant_user_id', '=', 'tenant_user.id')
            ->join('roles', 'roles.id', '=', 'tenant_user_roles.role_id')->where('tenant_user.user_id', $user->id)->value('roles.key');
    }

    public function test_owner_changes_role_and_details_but_not_self_or_owner(): void
    {
        $this->prepare();
        $this->put(route('staff.update', $this->staff->id), ['name' => 'Budi S', 'phone' => '081111111111', 'email' => 'budi@example.com', 'role' => 'manager'])->assertSessionHasNoErrors();
        $this->assertSame('manager', $this->roleKeyOf($this->staff));
        $this->assertSame('Budi S', DB::table('users')->where('id', $this->staff->id)->value('name'));
        $this->put(route('staff.update', $this->owner->id), ['name' => 'X', 'phone' => '0811', 'role' => 'cashier'])->assertStatus(422);
        $this->assertSame('owner', $this->roleKeyOf($this->owner));
    }

    public function test_deactivated_staff_is_blocked_and_reactivation_restores_access(): void
    {
        $this->prepare();
        $this->post(route('staff.status', [$this->staff->id, 'inactive']))->assertSessionHasNoErrors();
        $this->actingAs($this->staff)->get(route('dashboard'))->assertForbidden();
        $this->actingAs($this->owner)->post(route('staff.status', [$this->staff->id, 'active']))->assertSessionHasNoErrors();
        $this->actingAs($this->staff)->get(route('dashboard'))->assertOk();
    }

    public function test_password_reset_changes_hash_and_requires_confirmation(): void
    {
        $this->prepare();
        $this->post(route('staff.password', $this->staff->id), ['password' => 'baru12345', 'password_confirmation' => 'beda'])->assertSessionHasErrors('password');
        $this->post(route('staff.password', $this->staff->id), ['password' => 'baru12345', 'password_confirmation' => 'baru12345'])->assertSessionHasNoErrors();
        $this->assertTrue(Hash::check('baru12345', DB::table('users')->where('id', $this->staff->id)->value('password')));
    }

    public function test_only_owner_can_manage_staff_and_settings(): void
    {
        $this->prepare();
        $this->actingAs($this->staff)->put(route('staff.update', $this->staff->id), ['name' => 'X', 'phone' => '0811', 'role' => 'manager'])->assertForbidden();
        $this->actingAs($this->staff)->get(route('settings.edit'))->assertForbidden();
        $this->actingAs($this->staff)->get(route('access.matrix'))->assertForbidden();
    }

    public function test_settings_update_and_validation(): void
    {
        $this->prepare();
        $this->actingAs($this->owner)->put(route('settings.update'), ['name' => 'Toko Baru', 'legal_name' => 'PT Toko Baru', 'timezone' => 'Asia/Makassar', 'document_footer' => 'Terima kasih', 'receipt_width' => 58])->assertSessionHasNoErrors();
        $tenant = DB::table('tenants')->first();
        $this->assertSame('Toko Baru', $tenant->name);
        $this->assertSame('Asia/Makassar', $tenant->timezone);
        $this->assertSame('Terima kasih', json_decode($tenant->settings, true)['document_footer']);
        $this->put(route('settings.update'), ['name' => 'Toko Baru', 'timezone' => 'Mars/Olympus', 'receipt_width' => 80])->assertSessionHasErrors('timezone');
        $this->assertSame(1, DB::table('audit_logs')->where('action', 'tenant.updated')->count());
    }

    public function test_access_matrix_lists_modules_and_roles(): void
    {
        $this->prepare();
        $this->actingAs($this->owner)->get(route('access.matrix'))->assertOk()->assertSee('Kasir')->assertSee('Audit log')->assertSee('Admin gudang');
    }
}
