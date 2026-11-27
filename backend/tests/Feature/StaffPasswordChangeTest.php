<?php

namespace Tests\Feature;

use App\Models\StaffUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class StaffPasswordChangeTest extends TestCase
{
    use RefreshDatabase;

    private const CURRENT_PASSWORD = 'Current-Pass-1';

    /**
     * @return array<string, array{0: array<string, string>, 1: string, 2: string}>
     */
    public static function invalidChangeRequests(): array
    {
        return [
            'wrong current password' => [
                ['current_password' => 'not-my-password', 'password' => 'Brand-New-Pass', 'password_confirmation' => 'Brand-New-Pass'],
                'current_password',
                'รหัสผ่านปัจจุบันไม่ถูกต้อง',
            ],
            'same as current password' => [
                ['current_password' => self::CURRENT_PASSWORD, 'password' => self::CURRENT_PASSWORD, 'password_confirmation' => self::CURRENT_PASSWORD],
                'password',
                'รหัสผ่านใหม่ต้องไม่ซ้ำกับรหัสผ่านเดิม',
            ],
            'confirmation mismatch' => [
                ['current_password' => self::CURRENT_PASSWORD, 'password' => 'Brand-New-Pass', 'password_confirmation' => 'Another-Pass'],
                'password',
                'ยืนยันรหัสผ่านใหม่ไม่ตรงกัน',
            ],
            'too short' => [
                ['current_password' => self::CURRENT_PASSWORD, 'password' => 'short1', 'password_confirmation' => 'short1'],
                'password',
                'รหัสผ่านใหม่ต้องมีอย่างน้อย 10 ตัวอักษร',
            ],
        ];
    }

    /**
     * @param  array<string, string>  $payload
     */
    #[DataProvider('invalidChangeRequests')]
    public function test_password_change_rejects_invalid_requests(array $payload, string $field, string $message): void
    {
        $staff = $this->createStaff('sales', mustChange: true);
        $this->actingAs($staff, 'staff');

        $this->postJson('/api/crm/me/password', $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors([$field => $message]);

        $this->assertTrue((bool) DB::table('staff_users')->where('id', $staff->id)->value('must_change_password'));
    }

    public function test_changing_password_unlocks_the_crm_and_replaces_the_hash(): void
    {
        $staff = $this->createStaff('sales', mustChange: true);
        $this->actingAs($staff, 'staff');

        $this->getJson('/api/crm/customers')
            ->assertStatus(423)
            ->assertJsonPath('error', 'password_change_required');

        $this->postJson('/api/crm/me/password', [
            'current_password' => self::CURRENT_PASSWORD,
            'password' => 'Brand-New-Pass',
            'password_confirmation' => 'Brand-New-Pass',
        ])->assertOk();

        $this->getJson('/api/crm/customers')->assertOk();
        $stored = DB::table('staff_users')->where('id', $staff->id)->first();
        $this->assertFalse((bool) $stored->must_change_password);
        $this->assertTrue(Hash::check('Brand-New-Pass', $stored->password));
    }

    public function test_admin_reset_forces_the_owner_to_choose_a_new_password(): void
    {
        $admin = $this->createStaff('admin');
        $rep = $this->createStaff('sales');
        $this->actingAs($admin, 'staff');

        $this->patchJson("/api/crm/settings/staff/{$rep->id}", ['password' => 'Temporary-Reset-1'])->assertOk();

        $this->assertTrue((bool) DB::table('staff_users')->where('id', $rep->id)->value('must_change_password'));
        $audit = json_decode((string) DB::table('crm_audit_logs')
            ->where('entity_type', 'staff_user')
            ->where('entity_id', $rep->id)
            ->latest('id')
            ->value('changes'), true);
        $this->assertTrue($audit['password_reset']);
        $this->assertStringNotContainsString('Temporary-Reset-1', json_encode($audit));
    }

    public function test_admin_changing_own_password_in_settings_is_not_forced_again(): void
    {
        $admin = $this->createStaff('admin');
        $this->actingAs($admin, 'staff');

        $this->patchJson("/api/crm/settings/staff/{$admin->id}", ['password' => 'Admin-Own-Pass-1'])->assertOk();

        $this->assertFalse((bool) DB::table('staff_users')->where('id', $admin->id)->value('must_change_password'));
    }

    public function test_migration_flags_only_accounts_still_on_the_leaked_default_password(): void
    {
        $onDefault = $this->createStaff('sales', password: 'Popstar2026!');
        $ownPassword = $this->createStaff('sales');

        $migration = require database_path('migrations/2026_11_24_222059_add_must_change_password_to_staff_users.php');
        $migration->up();

        $this->assertTrue((bool) DB::table('staff_users')->where('id', $onDefault->id)->value('must_change_password'));
        $this->assertFalse((bool) DB::table('staff_users')->where('id', $ownPassword->id)->value('must_change_password'));
    }

    private function createStaff(string $role, bool $mustChange = false, string $password = self::CURRENT_PASSWORD): StaffUser
    {
        $id = DB::table('staff_users')->insertGetId([
            'name' => "Staff {$role}",
            'email' => $role.'-'.uniqid().'@popstar.local',
            'password' => Hash::make($password),
            'must_change_password' => $mustChange,
            'role' => $role,
            'salesperson_code' => $role === 'sales' ? '01' : null,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return StaffUser::query()->findOrFail($id);
    }
}
