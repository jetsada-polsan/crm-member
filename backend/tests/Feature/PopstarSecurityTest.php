<?php

namespace Tests\Feature;

use App\Models\MemberAccount;
use App\Models\StaffUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class PopstarSecurityTest extends TestCase
{
    use RefreshDatabase;

    public function test_sync_endpoints_reject_missing_or_short_bearer_token(): void
    {
        config()->set('popstar.sync_api_token', str_repeat('x', 40));

        $payload = ['source' => 'BPLUS', 'batch_id' => '00000000-0000-0000-0000-000000000001', 'records' => []];
        $this->postJson('/api/sync/v1/customers', $payload)->assertStatus(401);
        $this->postJson('/api/sync/v1/salespersons', $payload, ['Authorization' => 'Bearer wrong'])->assertStatus(401);
        $this->postJson('/api/sync/v1/members', $payload, ['Authorization' => 'Bearer short'])->assertStatus(401);
    }

    public function test_line_webhook_requires_valid_hmac_signature(): void
    {
        config()->set('popstar.line_channel_secret', 'line-test-secret-1234567890');
        $body = json_encode(['events' => []]);

        $this->postJson('/api/line/webhook', ['events' => []])->assertStatus(401);
        $this->call(
            'POST',
            '/api/line/webhook',
            [],
            [],
            [],
            ['HTTP_X_LINE_SIGNATURE' => 'invalid-sig', 'CONTENT_TYPE' => 'application/json'],
            $body
        )->assertStatus(401);

        $validSig = base64_encode(hash_hmac('sha256', $body, 'line-test-secret-1234567890', true));
        $this->call(
            'POST',
            '/api/line/webhook',
            [],
            [],
            [],
            ['HTTP_X_LINE_SIGNATURE' => $validSig, 'CONTENT_TYPE' => 'application/json'],
            $body
        )->assertOk();
    }

    public function test_sales_rep_rbac_isolates_customers_and_admin_endpoints(): void
    {
        $repId = DB::table('staff_users')->insertGetId([
            'name' => 'Sales Rep 1',
            'email' => 'rep1@popstar.local',
            'password' => Hash::make('Secret123!'),
            'role' => 'sales',
            'salesperson_code' => 'SL01',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $rep = StaffUser::query()->findOrFail($repId);

        $ownCustId = DB::table('erp_customers')->insertGetId([
            'source_system' => 'BPLUS',
            'erp_customer_code' => 'AR-OWN',
            'customer_name' => 'Own Shop',
            'salesperson_code' => 'SL01',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $otherCustId = DB::table('erp_customers')->insertGetId([
            'source_system' => 'BPLUS',
            'erp_customer_code' => 'AR-OTHER',
            'customer_name' => 'Other Rep Shop',
            'salesperson_code' => 'SL99',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Unauthenticated fails (both JSON and plain HTTP requests return 401 cleanly)
        $this->getJson('/api/crm/dashboard')->assertStatus(401);
        $this->get('/api/crm/dashboard')->assertStatus(401);
        $this->getJson('/api/crm/sync-monitor/status')->assertStatus(401);

        $this->actingAs($rep, 'staff');

        // Rep only sees own customer
        $list = $this->getJson('/api/crm/customers')->assertOk()->json();
        $this->assertCount(1, $list);
        $this->assertSame('AR-OWN', $list[0]['erp_customer_code']);

        // Rep blocked from viewing other rep customer detail or logging visit
        $this->getJson("/api/crm/customers/{$otherCustId}")->assertStatus(404);
        $this->getJson("/api/crm/customers/{$ownCustId}")->assertOk();
        $this->postJson('/api/crm/visits', [
            'erp_customer_id' => $otherCustId,
            'visited_at' => '2026-11-15',
            'result' => 'visited',
        ])->assertStatus(403);

        // Rep blocked from admin sync-monitor and member-links verification
        $this->getJson('/api/crm/sync-monitor/status')->assertStatus(403);
        $this->postJson('/api/crm/sync-monitor/sync/customers')->assertStatus(403);
        $this->getJson('/api/crm/member-links')->assertStatus(403);
    }

    public function test_member_only_sees_verified_link_actual_invoices_and_points(): void
    {
        $adminId = DB::table('staff_users')->insertGetId([
            'name' => 'Manager',
            'email' => 'mgr@popstar.local',
            'password' => Hash::make('Secret123!'),
            'role' => 'manager',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $admin = StaffUser::query()->findOrFail($adminId);

        $memberId = DB::table('member_accounts')->insertGetId([
            'name' => 'Member One',
            'email' => 'member1@popstar.local',
            'password' => Hash::make('Secret123!'),
            'phone' => '0811111111',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $member = MemberAccount::query()->findOrFail($memberId);

        $custId = DB::table('erp_customers')->insertGetId([
            'source_system' => 'BPLUS',
            'erp_customer_code' => 'AR-M1',
            'customer_name' => 'ร้านสมาชิก 1',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('erp_members')->insert([
            'source_system' => 'BPLUS',
            'member_code' => 'MB-001',
            'full_name' => 'ร้านสมาชิก 1',
            'erp_point_balance' => 320,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Seed 1 actual sale + 1 reservation 207 + 1 cancelled invoice
        DB::table('erp_sales_invoices')->insert([
            [
                'source_system' => 'BPLUS', 'branch_code' => '001', 'doc_type' => 'IV', 'dt_properties' => 307,
                'doc_category' => 'sale', 'invoice_no' => 'IV-M1', 'erp_customer_code' => 'AR-M1',
                'sold_at' => '2026-11-10', 'before_vat_amount' => 1000, 'vat_amount' => 70, 'gross_amount' => 1070,
                'net_amount' => 1000, 'status' => 'posted', 'created_at' => now(), 'updated_at' => now(),
            ],
            [
                'source_system' => 'BPLUS', 'branch_code' => '001', 'doc_type' => 'SO', 'dt_properties' => 207,
                'doc_category' => 'reservation', 'invoice_no' => 'SO-M1', 'erp_customer_code' => 'AR-M1',
                'sold_at' => '2026-11-11', 'before_vat_amount' => 5000, 'vat_amount' => 350, 'gross_amount' => 5350,
                'net_amount' => 5000, 'status' => 'reserved', 'created_at' => now(), 'updated_at' => now(),
            ],
            [
                'source_system' => 'BPLUS', 'branch_code' => '001', 'doc_type' => 'IV', 'dt_properties' => 307,
                'doc_category' => 'sale', 'invoice_no' => 'IV-CXL', 'erp_customer_code' => 'AR-M1',
                'sold_at' => '2026-11-12', 'before_vat_amount' => 2000, 'vat_amount' => 140, 'gross_amount' => 2140,
                'net_amount' => 2000, 'status' => 'cancelled', 'created_at' => now(), 'updated_at' => now(),
            ],
        ]);

        // Pending link created by admin -> Member MUST NOT see ERP invoices or points yet
        $this->actingAs($admin, 'staff');
        $created = $this->postJson('/api/crm/member-links', [
            'member_account_id' => $memberId,
            'erp_customer_id' => $custId,
            'erp_member_code' => 'MB-001',
            'verification_status' => 'pending',
        ])->assertStatus(201)->json();

        $this->actingAs($member, 'member');
        $this->getJson('/api/member/invoices')->assertStatus(403);
        $this->getJson('/api/member/points')->assertStatus(403);

        // Admin verifies link -> Member sees ONLY IV-M1 (no SO-207 reservation, no cancelled) + ERP points 320
        $this->actingAs($admin, 'staff');
        $this->postJson("/api/crm/member-links/{$created['id']}/verify", [
            'verification_status' => 'verified',
            'verification_note' => 'Matched tax ID & phone',
        ])->assertOk();

        $this->actingAs($member, 'member');
        $invs = $this->getJson('/api/member/invoices')->assertOk()->json();
        $this->assertCount(1, $invs);
        $this->assertSame('IV-M1', $invs[0]['invoice_no']);
        $this->assertEquals(1000.0, (float) $invs[0]['before_vat_amount']);
        $this->assertEquals(1070.0, (float) $invs[0]['gross_amount']);

        $pts = $this->getJson('/api/member/points')->assertOk()->json();
        $this->assertSame(320, $pts['erp_point_balance']);

        // Member profile update
        $this->patchJson('/api/member/profile', [
            'name' => 'Member Updated',
            'phone' => '0899999999',
        ])->assertOk()->assertJsonPath('member.name', 'Member Updated');

        $memberBId = DB::table('member_accounts')->insertGetId([
            'name' => 'Member Two',
            'email' => 'member2@popstar.local',
            'password' => Hash::make('Secret123!'),
            'phone' => '0822222222',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $customerBId = DB::table('erp_customers')->insertGetId([
            'source_system' => 'BPLUS',
            'erp_customer_code' => 'AR-M2',
            'customer_name' => 'Member Two Shop',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('erp_sales_invoices')->insert([
            'source_system' => 'BPLUS',
            'branch_code' => '001',
            'doc_type' => 'IV',
            'dt_properties' => 307,
            'doc_category' => 'sale',
            'invoice_no' => 'IV-M2',
            'erp_customer_code' => 'AR-M2',
            'sold_at' => '2026-11-13',
            'before_vat_amount' => 2000,
            'vat_amount' => 140,
            'gross_amount' => 2140,
            'net_amount' => 2000,
            'status' => 'posted',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $linkB = $this->actingAs($admin, 'staff')->postJson('/api/crm/member-links', [
            'member_account_id' => $memberBId,
            'erp_customer_id' => $customerBId,
            'verification_status' => 'pending',
        ])->assertStatus(201)->json();
        $this->postJson("/api/crm/member-links/{$linkB['id']}/verify", [
            'verification_status' => 'verified',
            'verification_note' => 'Test isolation link',
        ])->assertOk();

        $memberB = MemberAccount::query()->findOrFail($memberBId);
        $this->actingAs($memberB, 'member');
        $memberBInvoices = $this->getJson('/api/member/invoices')->assertOk()->json();
        $this->assertCount(1, $memberBInvoices);
        $this->assertSame('IV-M2', $memberBInvoices[0]['invoice_no']);
        $this->assertSame('AR-M2', $this->getJson('/api/member/profile')->assertOk()->json('erp_link.erp_customer_code'));
        $this->assertSame(0, $this->getJson('/api/member/points')->assertOk()->json('balance'));
    }

    public function test_cross_role_access_and_portal_redirect_contracts(): void
    {
        $staffId = DB::table('staff_users')->insertGetId([
            'name' => 'Cross Guard Staff',
            'email' => 'cross-staff@popstar.local',
            'password' => Hash::make('StaffStrongPass#2026!'),
            'role' => 'sales',
            'salesperson_code' => 'SL02',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Unauthenticated requests to staff and member routes fail consistently
        $this->getJson('/api/crm/me')->assertStatus(401);
        $this->getJson('/api/member/me')->assertStatus(401);

        // Staff login returns explicit CRM portal redirect contract and rotates session
        $this->getJson('/api/crm/csrf')->assertOk();
        $oldSessionId = session()->getId();
        $this->postJson('/api/crm/login', [
            'email' => 'cross-staff@popstar.local',
            'password' => 'wrong-password',
        ])->assertStatus(422)->assertJson(['error' => 'Invalid credentials']);

        $this->postJson('/api/crm/login', [
            'email' => 'cross-staff@popstar.local',
            'password' => 'StaffStrongPass#2026!',
        ])->assertOk()
            ->assertJsonPath('portal', 'crm')
            ->assertJsonPath('redirect_path', '/dashboard')
            ->assertJsonPath('role', 'sales');
        $this->assertNotSame($oldSessionId, session()->getId());

        // Staff guard cannot access Member endpoints
        $this->getJson('/api/member/me')->assertStatus(401);
        $this->getJson('/api/member/profile')->assertStatus(401);
        $this->getJson('/api/member/invoices')->assertStatus(401);
        $this->getJson('/api/member/points')->assertStatus(401);
        $this->postJson('/api/member/verification-request', ['requested_customer_code' => 'AR01'])->assertStatus(401);

        // Member registration rejects Staff-only route access and returns Member portal contract
        $reg = $this->postJson('/api/member/register', [
            'name' => 'Cross Guard Member',
            'phone' => '0898765432',
            'password' => 'MemberStrongPass#2026!',
            'password_confirmation' => 'MemberStrongPass#2026!',
            'consent' => true,
        ])->assertStatus(201)
            ->assertJsonPath('portal', 'member')
            ->assertJsonPath('redirect_path', '/overview')
            ->assertJsonPath('identity_status', 'member_verification_pending');
        $memberId = $reg->json('id');

        // Logout staff & authenticate strictly as member
        Auth::guard('staff')->logout();
        $member = MemberAccount::query()->findOrFail($memberId);
        $this->actingAs($member, 'member');

        // Member guard cannot access Staff CRM endpoints
        $this->getJson('/api/crm/me')->assertStatus(401);
        $this->getJson('/api/crm/dashboard')->assertStatus(401);
        $this->getJson('/api/crm/customers')->assertStatus(401);
        $this->getJson('/api/crm/leads')->assertStatus(401);
        $this->getJson('/api/crm/customer-master')->assertStatus(401);
        $this->getJson('/api/crm/settings/overview')->assertStatus(401);

        // Cross-guard login endpoints reject credentials belonging only to the other guard with generic 422
        $this->postJson('/api/crm/login', [
            'email' => 'member@nonstaff.local',
            'password' => 'MemberStrongPass#2026!',
        ])->assertStatus(422)->assertJson(['error' => 'Invalid credentials']);

        $this->postJson('/api/member/login', [
            'email' => 'cross-staff@popstar.local',
            'password' => 'StaffStrongPass#2026!',
        ])->assertStatus(422)->assertJson(['error' => 'Invalid credentials']);
    }

    public function test_login_rate_limiters_are_isolated_between_member_and_crm_portals(): void
    {
        Cache::flush();

        DB::table('staff_users')->insert([
            'name' => 'Rate Limit Staff',
            'email' => 'ratelimit-staff@popstar.local',
            'password' => Hash::make('StaffRatePass#2026!'),
            'role' => 'admin',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Exhaust Member login rate limit (6 per minute for same IP+identifier)
        for ($i = 0; $i < 6; $i++) {
            $this->postJson('/api/member/login', [
                'phone' => '0891112222',
                'password' => 'wrong-member-pass',
            ])->assertStatus(422);
        }

        // 7th Member login attempt is throttled with 429
        $this->postJson('/api/member/login', [
            'phone' => '0891112222',
            'password' => 'wrong-member-pass',
        ])->assertStatus(429);

        // Staff CRM login stays unaffected by Member login throttling
        $this->postJson('/api/crm/login', [
            'email' => 'ratelimit-staff@popstar.local',
            'password' => 'StaffRatePass#2026!',
        ])->assertOk()
            ->assertJsonPath('portal', 'crm');
    }
}
