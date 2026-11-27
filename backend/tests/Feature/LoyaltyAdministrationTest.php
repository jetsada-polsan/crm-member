<?php

namespace Tests\Feature;

use App\Models\StaffUser;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class LoyaltyAdministrationTest extends TestCase
{
    use LazilyRefreshDatabase;

    private function createStaff(string $role): StaffUser
    {
        $id = DB::table('staff_users')->insertGetId([
            'name' => 'Staff '.$role,
            'email' => $role.uniqid().'@popstar.local',
            'password' => Hash::make('Secret123!'),
            'role' => $role,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return StaffUser::query()->findOrFail($id);
    }

    /**
     * A member who spent 50 points on a reward that has 1 item left in stock.
     *
     * @return array{redemption_id: int, reward_id: int, member_id: int}
     */
    private function pendingRedemption(): array
    {
        $memberId = DB::table('member_accounts')->insertGetId([
            'name' => 'Member', 'email' => uniqid().'@member.local', 'password' => Hash::make('MemberSecret123!'),
            'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $rewardId = DB::table('loyalty_rewards')->insertGetId([
            'reward_code' => 'RWD-'.uniqid(), 'title' => 'Mug', 'points_cost' => 50, 'stock_qty' => 1,
            'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $spendId = DB::table('loyalty_points_ledger')->insertGetId([
            'member_account_id' => $memberId, 'event_key' => 'REDEEM:'.uniqid(), 'points_delta' => -50,
            'reason' => 'Redeem', 'entry_type' => 'redemption', 'created_at' => now(),
        ]);
        $redemptionId = DB::table('loyalty_redemptions')->insertGetId([
            'member_account_id' => $memberId, 'reward_id' => $rewardId, 'ledger_entry_id' => $spendId,
            'points_spent' => 50, 'status' => 'pending', 'voucher_code' => 'V-'.uniqid(),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return ['redemption_id' => $redemptionId, 'reward_id' => $rewardId, 'member_id' => $memberId];
    }

    private function pointsBalance(int $memberId): int
    {
        return (int) DB::table('loyalty_points_ledger')->where('member_account_id', $memberId)->sum('points_delta');
    }

    public function test_sales_staff_cannot_change_redemption_status(): void
    {
        $redemption = $this->pendingRedemption();
        $this->actingAs($this->createStaff('sales'), 'staff');

        $this->postJson("/api/crm/loyalty/redemptions/{$redemption['redemption_id']}/status", ['status' => 'cancelled'])
            ->assertForbidden();

        $this->assertSame('pending', DB::table('loyalty_redemptions')->where('id', $redemption['redemption_id'])->value('status'));
        $this->assertSame(-50, $this->pointsBalance($redemption['member_id']));
    }

    public function test_fulfilled_redemption_is_final_and_cannot_be_cancelled_for_a_refund(): void
    {
        $this->freezeTime();
        $redemption = $this->pendingRedemption();
        $manager = $this->createStaff('manager');
        $this->actingAs($manager, 'staff');

        $this->postJson("/api/crm/loyalty/redemptions/{$redemption['redemption_id']}/status", ['status' => 'fulfilled'])
            ->assertOk()
            ->assertJson(['ok' => true, 'status' => 'fulfilled']);

        $row = DB::table('loyalty_redemptions')->where('id', $redemption['redemption_id'])->first();
        $this->assertSame('fulfilled', $row->status);
        $this->assertSame($manager->id, (int) $row->reviewed_by);
        $this->assertNotNull($row->fulfilled_at);

        $this->postJson("/api/crm/loyalty/redemptions/{$redemption['redemption_id']}/status", ['status' => 'cancelled'])
            ->assertStatus(409);

        $this->assertSame(-50, $this->pointsBalance($redemption['member_id']));
        $this->assertSame(1, (int) DB::table('loyalty_rewards')->where('id', $redemption['reward_id'])->value('stock_qty'));
    }

    public function test_redemption_status_rejects_unknown_values_and_missing_redemptions(): void
    {
        $redemption = $this->pendingRedemption();
        $this->actingAs($this->createStaff('admin'), 'staff');

        $this->postJson("/api/crm/loyalty/redemptions/{$redemption['redemption_id']}/status", ['status' => 'pending'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('status');
        $this->postJson('/api/crm/loyalty/redemptions/999999/status', ['status' => 'approved'])
            ->assertNotFound();
    }

    public function test_manager_updates_a_loyalty_rule_and_invalid_rates_are_rejected(): void
    {
        $ruleId = DB::table('loyalty_rules')->insertGetId([
            'rule_code' => 'BASE', 'name' => 'Base', 'baht_per_point' => 100, 'points_per_unit' => 1,
            'min_invoice_amount' => 0, 'is_active' => false, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $manager = $this->createStaff('manager');
        $this->actingAs($manager, 'staff');

        $this->patchJson("/api/crm/loyalty/rules/{$ruleId}", [
            'baht_per_point' => 50,
            'exclude_categories' => ['CC', 'PK'],
            'is_active' => true,
        ])->assertOk()->assertJson(['ok' => true, 'id' => $ruleId]);

        $rule = DB::table('loyalty_rules')->where('id', $ruleId)->first();
        $this->assertEquals(50, $rule->baht_per_point);
        $this->assertSame(['CC', 'PK'], json_decode($rule->exclude_categories, true));
        $this->assertTrue((bool) $rule->is_active);
        $this->assertSame($manager->id, (int) $rule->configured_by);

        $this->patchJson("/api/crm/loyalty/rules/{$ruleId}", ['baht_per_point' => 0])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('baht_per_point');
        $this->patchJson('/api/crm/loyalty/rules/999999', ['is_active' => false])->assertNotFound();
    }

    public function test_sales_and_executive_staff_cannot_update_loyalty_rules(): void
    {
        $ruleId = DB::table('loyalty_rules')->insertGetId([
            'rule_code' => 'BASE', 'name' => 'Base', 'baht_per_point' => 100, 'points_per_unit' => 1,
            'min_invoice_amount' => 0, 'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);

        foreach (['sales', 'executive'] as $role) {
            $this->actingAs($this->createStaff($role), 'staff');
            $this->patchJson("/api/crm/loyalty/rules/{$ruleId}", ['baht_per_point' => 1])->assertForbidden();
        }

        $this->assertEquals(100, DB::table('loyalty_rules')->where('id', $ruleId)->value('baht_per_point'));
    }

    public function test_redemption_and_ledger_lists_are_manager_only(): void
    {
        $this->pendingRedemption();

        $this->actingAs($this->createStaff('sales'), 'staff');
        $this->getJson('/api/crm/loyalty/redemptions')->assertForbidden();
        $this->getJson('/api/crm/loyalty/ledger')->assertForbidden();

        $this->actingAs($this->createStaff('manager'), 'staff');
        $this->getJson('/api/crm/loyalty/redemptions')->assertOk()->assertJsonCount(1);
        $this->getJson('/api/crm/loyalty/ledger')->assertOk();
        $this->getJson('/api/crm/loyalty/rewards')->assertOk();
        $this->getJson('/api/crm/loyalty/verifications')->assertOk();
    }
}
