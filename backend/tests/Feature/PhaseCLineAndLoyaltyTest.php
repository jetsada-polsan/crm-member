<?php

namespace Tests\Feature;

use App\Models\MemberAccount;
use App\Models\StaffUser;
use App\Modules\Loyalty\LoyaltyController;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class PhaseCLineAndLoyaltyTest extends TestCase
{
    use RefreshDatabase;

    private function createStaff(string $role): StaffUser
    {
        $id = DB::table('staff_users')->insertGetId([
            'name' => 'Staff ' . $role,
            'email' => $role . uniqid() . '@popstar.local',
            'password' => Hash::make('Secret123!'),
            'role' => $role,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return StaffUser::query()->findOrFail($id);
    }

    private function createMember(string $email = 'member@popstar.local'): MemberAccount
    {
        $id = DB::table('member_accounts')->insertGetId([
            'name' => 'สมชาย สมาชิก',
            'email' => $email,
            'password' => Hash::make('MemberSecret123!'),
            'phone' => '0811112222',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return MemberAccount::query()->findOrFail($id);
    }

    public function test_line_account_binding_and_webhook_signature_with_replay_protection(): void
    {
        config()->set('popstar.line_channel_secret', 'secret-phase-c-123');
        config()->set('popstar.line_channel_access_token', ''); // Disabled outbound reply

        $member = $this->createMember();
        $this->actingAs($member, 'member');

        $tokenRes = $this->postJson('/api/member/line/link-token')->assertStatus(201)->json();
        $linkCode = $tokenRes['link_code'];

        $eventBody = json_encode([
            'events' => [[
                'webhookEventId' => 'evt-line-001',
                'type' => 'message',
                'replyToken' => 'reply-tok-1',
                'source' => ['userId' => 'U_LINE_123456'],
                'message' => ['type' => 'text', 'text' => 'LINK:' . $linkCode],
            ]],
        ]);
        $sig = base64_encode(hash_hmac('sha256', $eventBody, 'secret-phase-c-123', true));

        // Bad signature rejected 401
        $this->call('POST', '/api/line/webhook', [], [], [], [
            'HTTP_X_LINE_SIGNATURE' => 'bad',
            'CONTENT_TYPE' => 'application/json',
        ], $eventBody)->assertStatus(401);

        // Valid signature binds LINE user to member
        $this->call('POST', '/api/line/webhook', [], [], [], [
            'HTTP_X_LINE_SIGNATURE' => $sig,
            'CONTENT_TYPE' => 'application/json',
        ], $eventBody)->assertOk()->assertJson(['ok' => true, 'bound' => 1]);

        $this->assertDatabaseHas('member_line_accounts', [
            'member_account_id' => $member->id,
            'line_user_id' => 'U_LINE_123456',
            'binding_status' => 'verified',
        ]);

        // Replaying exact webhookEventId does not double process
        $this->call('POST', '/api/line/webhook', [], [], [], [
            'HTTP_X_LINE_SIGNATURE' => $sig,
            'CONTENT_TYPE' => 'application/json',
        ], $eventBody)->assertOk()->assertJson(['ok' => true, 'bound' => 0]);

        // Profile shows binding & revoke cleanly clears it
        $this->getJson('/api/member/profile')->assertOk()->assertJsonPath('line_binding.binding_status', 'verified');
        $this->deleteJson('/api/member/line/binding')->assertOk()->assertJson(['ok' => true, 'revoked' => true]);
        $this->getJson('/api/member/profile')->assertOk()->assertJsonPath('line_binding', null);
    }

    public function test_member_verification_loyalty_rules_redemption_and_reversal(): void
    {
        $mgr = $this->createStaff('manager');
        $sales = $this->createStaff('sales');
        $member = $this->createMember('m2@popstar.local');

        DB::table('erp_customers')->insert([
            'source_system' => 'BPLUS',
            'erp_customer_code' => 'AR-M01',
            'customer_name' => 'ร้านสมาชิก ทดสอบ',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Sales rep is forbidden from manager loyalty admin endpoints; Member is also blocked (401/403)
        $this->actingAs($sales, 'staff');
        $this->getJson('/api/crm/loyalty/rules')->assertStatus(403);

        // Admin/Manager creates rule (defaults inactive = no automatic calculation) and reward
        $this->actingAs($mgr, 'staff');
        $this->postJson('/api/crm/loyalty/rules', [
            'rule_code' => 'BASE-100',
            'name' => 'ทุก 100 บาท ได้ 1 แต้ม',
            'baht_per_point' => 100,
            'points_per_unit' => 1,
        ])->assertStatus(201);

        $inactiveRule = DB::table('loyalty_rules')->where('rule_code', 'BASE-100')->first();
        $this->assertFalse((bool) $inactiveRule->is_active);
        $this->assertSame(0, LoyaltyController::calculatePointsForAmount(500.0, $inactiveRule, 'sale', 'posted'));

        // Even if active, cancelled/reservations give 0 and returns give negative points
        $activeRule = (object) ['is_active' => true, 'exclude_cancelled' => true, 'exclude_reservation' => true, 'reverse_on_return' => true, 'baht_per_point' => 100, 'points_per_unit' => 1, 'min_invoice_amount' => 0];
        $this->assertSame(0, LoyaltyController::calculatePointsForAmount(9000.0, $activeRule, 'reservation', 'reserved'));
        $this->assertSame(0, LoyaltyController::calculatePointsForAmount(1000.0, $activeRule, 'sale', 'cancelled'));
        $this->assertSame(-2, LoyaltyController::calculatePointsForAmount(-200.0, $activeRule, 'return', 'returned'));

        $rewardId = $this->postJson('/api/crm/loyalty/rewards', [
            'reward_code' => 'RWD-MUG',
            'title' => 'แก้วน้ำ Popstar',
            'points_cost' => 50,
            'stock_qty' => 2,
            'is_active' => true,
        ])->assertStatus(201)->json('id');

        // Member submits verification request (unverified member cannot redeem rewards yet)
        $this->actingAs($member, 'member');
        $vrId = $this->postJson('/api/member/verification-request', [
            'requested_customer_code' => 'AR-M01',
            'requested_member_code' => 'MB-01',
        ])->assertStatus(201)->json('id');

        // Seed manual approved ledger points (100 pts) — still blocked before verification
        $earnLedgerId = DB::table('loyalty_points_ledger')->insertGetId([
            'member_account_id' => $member->id,
            'event_key' => 'OPENING:M2',
            'points_delta' => 100,
            'reason' => 'Opening balance test',
            'entry_type' => 'manual',
            'created_at' => now(),
        ]);

        $this->postJson("/api/member/rewards/{$rewardId}/redeem")->assertStatus(403);

        // Manager approves member verification -> unlocks redeem
        $this->actingAs($mgr, 'staff');
        $this->postJson("/api/crm/loyalty/verifications/{$vrId}/review", [
            'decision' => 'approved',
        ])->assertOk()->assertJson(['ok' => true, 'status' => 'approved']);

        // Verified member redeems reward -> 100 - 50 = 50 remaining, stock decrements 2 -> 1
        $this->actingAs($member, 'member');
        $redeem = $this->postJson("/api/member/rewards/{$rewardId}/redeem")
            ->assertStatus(201)
            ->assertJson(['ok' => true, 'points_spent' => 50, 'remaining_balance' => 50])
            ->json();
        $this->assertCount(1, $this->getJson('/api/member/redemptions')->assertOk()->json());

        // Manager cancels redemption -> reverses spent 50 pts back (balance -> 100) and restores stock (1 -> 2)
        $this->actingAs($mgr, 'staff');
        $this->postJson('/api/crm/loyalty/redemptions/' . $redeem['redemption_id'] . '/status', [
            'status' => 'cancelled',
        ])->assertOk()->assertJson(['ok' => true, 'status' => 'cancelled']);

        $this->actingAs($member, 'member');
        $this->assertSame(100, $this->getJson('/api/member/points')->assertOk()->json('balance'));

        // Transaction reversal endpoint reverses original ledger (-100) and blocks double-reversal (409)
        $this->actingAs($mgr, 'staff');
        $this->postJson('/api/crm/loyalty/ledger/reverse', [
            'ledger_id' => $earnLedgerId,
            'reason' => 'Correction adjustment',
        ])->assertStatus(201)->assertJson(['ok' => true, 'reversed_delta' => -100]);

        $this->postJson('/api/crm/loyalty/ledger/reverse', [
            'ledger_id' => $earnLedgerId,
            'reason' => 'Duplicate retry',
        ])->assertStatus(409);

        $this->actingAs($member, 'member');
        $this->assertSame(0, $this->getJson('/api/member/points')->assertOk()->json('balance'));
    }
}
