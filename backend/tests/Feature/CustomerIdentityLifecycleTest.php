<?php

namespace Tests\Feature;

use App\Models\MemberAccount;
use App\Models\StaffUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class CustomerIdentityLifecycleTest extends TestCase
{
    use RefreshDatabase;

    private function staff(string $role, ?string $salesCode = null): StaffUser
    {
        $id = DB::table('staff_users')->insertGetId([
            'name' => ucfirst($role).' Identity Test', 'email' => uniqid($role).'@identity.test',
            'password' => Hash::make('StaffSecret123!'), 'role' => $role,
            'salesperson_code' => $salesCode, 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return StaffUser::query()->findOrFail($id);
    }

    public function test_lead_member_matching_verification_invoice_points_and_unlink_lifecycle(): void
    {
        $rep = $this->staff('sales', 'SL01');
        $manager = $this->staff('manager');
        $admin = $this->staff('admin');

        // A member can register with a phone before a Bplus debtor exists.
        $memberId = $this->postJson('/api/member/register', [
            'name' => 'Mali Store Owner', 'phone' => '0812345678',
            'password' => 'MemberSecret123!', 'password_confirmation' => 'MemberSecret123!', 'consent' => true,
        ])->assertCreated()->json('id');
        $member = MemberAccount::query()->findOrFail($memberId);

        $this->actingAs($rep, 'staff');
        $lead = $this->postJson('/api/crm/leads/quick', [
            'shop_name' => 'Mali Grocery', 'phone' => '0812345678', 'shop_type' => 'ร้านค้าปลีก',
            'contact_name' => 'Mali', 'province' => 'Bangkok', 'district' => 'Bang Kapi',
            'address' => '12 Example Road', 'latitude' => 13.7654, 'longitude' => 100.5432,
        ])->assertCreated()->json();
        $this->assertSame(0, DB::table('erp_customers')->count());
        $masterId = (int) $lead['customer_master_id'];
        $leadStageBeforeErp = DB::table('crm_leads')->where('id', $lead['id'])->value('pipeline_stage');

        $this->actingAs($member, 'member');
        $requestId = $this->postJson('/api/member/verification-request', [
            'requested_phone' => '0812345678', 'evidence_note' => 'เป็นเจ้าของร้าน Mali Grocery',
        ])->assertCreated()->json('id');
        $this->getJson('/api/member/profile')->assertOk()->assertJsonPath('identity_status', 'member_verification_pending');
        $this->getJson('/api/member/profile')->assertJsonPath('purchase_summary', null);
        $this->getJson('/api/member/invoices')->assertForbidden();
        $this->getJson('/api/member/points')->assertForbidden();

        $this->actingAs($rep, 'staff');
        $this->postJson("/api/crm/customer-master/leads/{$lead['id']}/request-erp-creation", [
            'note' => 'ขอให้แอดมินเปิดลูกหนี้ใน Bplus ตามขั้นตอนเดิม',
        ])->assertOk()->assertJsonPath('status', 'erp_creation_requested');
        $this->actingAs($manager, 'staff');
        $this->getJson('/api/crm/customer-master/erp-creation-requests')->assertOk()->assertJsonFragment(['crm_lead_id' => $lead['id']]);

        // Simulate the administrator creating the debtor outside CRM. Two sources
        // deliberately share a code to verify source-qualified identity keys.
        $erpId = DB::table('erp_customers')->insertGetId([
            'source_system' => 'BPLUS', 'erp_customer_code' => 'AR-MALI-01',
            'customer_name' => 'Mali Grocery', 'phone' => '0812345678', 'province' => 'Bangkok',
            'address' => '12 Example Road', 'salesperson_code' => 'SL01', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $otherSourceErpId = DB::table('erp_customers')->insertGetId([
            'source_system' => 'BPLUS-ARCHIVE', 'erp_customer_code' => 'AR-MALI-01',
            'customer_name' => 'Mali Grocery', 'phone' => '0812345678', 'province' => 'Bangkok',
            'address' => '12 Example Road', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $duplicateMasterId = DB::table('crm_customer_masters')->insertGetId([
            'internal_customer_code' => sprintf('POP-ERP-%06d', $erpId), 'erp_customer_id' => $erpId,
            'erp_source_system' => 'BPLUS', 'erp_customer_code' => 'AR-MALI-01', 'link_status' => 'linked',
            'verified_at' => now(), 'display_name' => 'Mali Grocery', 'phone' => '0812345678',
            'address' => '12 Example Road', 'province' => 'Bangkok', 'created_source' => 'erp_mirror',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        // The owner is verified to the internal customer before it has an ERP code.
        $this->postJson("/api/crm/loyalty/verifications/{$requestId}/review", [
            'decision' => 'approved', 'crm_customer_master_id' => $masterId,
            'review_note' => 'ตรวจหลักฐานเจ้าของร้านแล้ว; รอ ERP',
        ])->assertOk()->assertJsonPath('member_status', 'pending_erp');
        $this->actingAs($member, 'member');
        $this->getJson('/api/member/profile')->assertOk()->assertJsonPath('identity_status', 'pending_erp');
        $this->getJson('/api/member/invoices')->assertForbidden();
        $this->getJson('/api/member/points')->assertForbidden();

        // Prior visits, GPS, photo, and follow-up remain attached to the ERP-only
        // master that will be merged into the stable CRM lead master.
        $visitId = DB::table('crm_customer_visits')->insertGetId([
            'erp_customer_id' => $erpId, 'staff_user_id' => $rep->id, 'visited_at' => now(),
            'result' => 'visited', 'shop_type' => 'existing', 'checkin_latitude' => 13.7654,
            'checkin_longitude' => 100.5432, 'customer_master_id' => $duplicateMasterId,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $photoId = DB::table('crm_visit_photos')->insertGetId([
            'visit_id' => $visitId, 'storage_path' => 'visits/mali.jpg', 'category' => 'storefront',
            'original_name' => 'mali.jpg', 'uploaded_by' => $rep->id, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $taskId = DB::table('crm_follow_up_tasks')->insertGetId([
            'visit_id' => $visitId, 'crm_lead_id' => $lead['id'], 'assigned_to' => $rep->id,
            'title' => 'Follow up Mali Grocery', 'status' => 'open', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->actingAs($manager, 'staff');
        $this->postJson('/api/crm/customer-master/match-candidates/refresh', [])->assertOk();
        $candidates = $this->getJson('/api/crm/customer-master/match-candidates')->assertOk()->json();
        $this->assertCount(2, $candidates);
        $this->assertNull(DB::table('crm_leads')->where('id', $lead['id'])->value('converted_erp_customer_id'));
        $this->assertDatabaseHas('customer_match_candidates', ['crm_lead_id' => $lead['id'], 'status' => 'pending']);
        $candidate = collect($candidates)->firstWhere('source_system', 'BPLUS');
        $this->assertNotNull($candidate);

        $this->postJson("/api/crm/customer-master/match-candidates/{$candidate['id']}/confirm", [
            'note' => 'ยืนยันจากรหัส ERP ที่ซิงก์และหลักฐานที่ตรวจแล้ว',
        ])->assertOk()->assertJsonPath('customer_master.id', $masterId);
        $this->assertDatabaseHas('crm_customer_masters', [
            'id' => $duplicateMasterId, 'link_status' => 'merged',
            'merged_into_customer_master_id' => $masterId, 'erp_customer_id' => null,
        ]);
        $this->assertSame($leadStageBeforeErp, DB::table('crm_leads')->where('id', $lead['id'])->value('pipeline_stage'));
        $this->getJson("/api/crm/customer-master/{$duplicateMasterId}")->assertOk()
            ->assertJsonPath('internal_customer.id', $masterId)
            ->assertJsonPath('requested_internal_customer_id', $duplicateMasterId);
        $this->assertDatabaseHas('crm_customer_visits', [
            'id' => $visitId, 'customer_master_id' => $masterId,
            'checkin_latitude' => 13.7654, 'checkin_longitude' => 100.5432,
        ]);
        $this->assertDatabaseHas('crm_visit_photos', ['id' => $photoId, 'visit_id' => $visitId]);
        $this->assertDatabaseHas('crm_follow_up_tasks', ['id' => $taskId, 'visit_id' => $visitId, 'crm_lead_id' => $lead['id']]);
        $this->assertDatabaseHas('member_erp_links', [
            'member_account_id' => $memberId, 'crm_customer_master_id' => $masterId,
            'erp_customer_id' => $erpId, 'verification_status' => 'verified',
        ]);

        DB::table('loyalty_rules')->insert([
            'rule_code' => 'ERP-SALE-100', 'name' => 'One point per 100 baht', 'baht_per_point' => 100,
            'points_per_unit' => 1, 'min_invoice_amount' => 0, 'exclude_reservation' => true,
            'exclude_cancelled' => true, 'reverse_on_return' => true, 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $invoice = [
            'di_key' => 10, 'branch_code' => '001', 'doc_type' => 'IV', 'dt_properties' => 307,
            'doc_category' => 'sale', 'invoice_no' => 'IV-MALI-1', 'erp_customer_code' => 'AR-MALI-01',
            'sold_at' => '2026-11-15 10:00:00', 'before_vat_amount' => 1000, 'vat_amount' => 70,
            'gross_amount' => 1070, 'net_amount' => 1000, 'status' => 'posted', 'items_complete' => true, 'items' => [],
        ];
        $cancelled = [...$invoice, 'status' => 'cancelled'];
        $returned = [...$invoice, 'di_key' => 11, 'doc_type' => 'CN', 'dt_properties' => 308,
            'doc_category' => 'return', 'invoice_no' => 'CN-MALI-1', 'before_vat_amount' => 200,
            'vat_amount' => 14, 'gross_amount' => 214, 'net_amount' => 200, 'status' => 'returned'];
        Http::fake([
            'http://host.docker.internal:18900/export/invoices*' => Http::sequence()
                ->push(['ok' => true, 'source' => 'BPLUS', 'next_cursor' => 10, 'has_more' => false, 'records' => [$invoice]], 200)
                ->push(['ok' => true, 'source' => 'BPLUS', 'next_cursor' => 10, 'has_more' => false, 'records' => [$invoice]], 200)
                ->push(['ok' => true, 'source' => 'BPLUS', 'next_cursor' => 10, 'has_more' => false, 'records' => [$cancelled]], 200)
                ->push(['ok' => true, 'source' => 'BPLUS', 'next_cursor' => 11, 'has_more' => false, 'records' => [$returned]], 200),
        ]);
        $this->actingAs($admin, 'staff');
        $this->postJson('/api/crm/sync-monitor/sync/invoices')->assertOk()->assertJson(['inserted' => 1]);
        $this->assertSame(1, DB::table('loyalty_points_ledger')->where('entry_type', 'erp_invoice')->where('reference_type', 'erp_sales_invoice')->count());
        $this->postJson('/api/crm/sync-monitor/sync/invoices')->assertOk()->assertJson(['inserted' => 0, 'updated' => 1]);
        $this->assertSame(1, DB::table('loyalty_points_ledger')->where('entry_type', 'erp_invoice')->where('reference_type', 'erp_sales_invoice')->count());
        $this->postJson('/api/crm/sync-monitor/sync/invoices')->assertOk()->assertJson(['updated' => 1]);
        $this->assertSame(0, (int) DB::table('loyalty_points_ledger')->where('entry_type', 'erp_invoice')->where('reference_type', 'erp_sales_invoice')->sum('points_delta'));
        $this->postJson('/api/crm/sync-monitor/sync/invoices')->assertOk()->assertJson(['inserted' => 1]);

        // Same ERP business code in another source does not leak into this member's history.
        DB::table('erp_sales_invoices')->insert([
            'source_system' => 'BPLUS-ARCHIVE', 'branch_code' => '001', 'doc_type' => 'IV', 'dt_properties' => 307,
            'doc_category' => 'sale', 'invoice_no' => 'IV-OTHER-SOURCE', 'erp_customer_code' => 'AR-MALI-01',
            'sold_at' => '2026-11-14', 'before_vat_amount' => 900, 'vat_amount' => 63, 'gross_amount' => 963,
            'net_amount' => 900, 'status' => 'posted', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->actingAs($member, 'member');
        $points = $this->getJson('/api/member/points')->assertOk()->json();
        $this->assertSame(-2, $points['balance']);
        $invoices = $this->getJson('/api/member/invoices')->assertOk()->json();
        $this->assertCount(1, $invoices);
        $this->assertSame('CN-MALI-1', $invoices[0]['invoice_no']);
        $profile = $this->getJson('/api/member/profile')->assertOk()->json();
        $this->assertSame(0, (int) $profile['purchase_summary']['sale_invoice_count']);
        $this->assertSame(1, (int) $profile['purchase_summary']['return_count']);
        $this->assertEquals(200.0, (float) $profile['purchase_summary']['return_amount_before_vat']);
        $this->assertEquals(-200.0, (float) $profile['purchase_summary']['net_purchase_before_vat']);
        $this->assertNull($profile['purchase_summary']['last_purchase_at']);

        $this->actingAs($manager, 'staff');
        $this->postJson("/api/crm/customer-master/{$masterId}/unlink-erp", ['note' => 'แก้การผูกผิดบัญชี'])->assertOk();
        $this->actingAs($member, 'member');
        $this->getJson('/api/member/invoices')->assertForbidden();
        $this->getJson('/api/member/points')->assertForbidden();
        $profile = $this->getJson('/api/member/profile')->assertOk()->assertJsonPath('identity_status', 'member_verification_pending')->json();
        $this->assertNull($profile['erp_link']['erp_customer_code']);
        $this->assertNull($profile['purchase_summary']);
        $this->actingAs($manager, 'staff');
        $audit = $this->getJson("/api/crm/customer-master/{$masterId}/audit")->assertOk()->json();
        $this->assertNotEmpty(array_filter($audit, fn ($row) => $row['action'] === 'erp_unlinked'));
        $this->assertDatabaseHas('erp_customers', ['id' => $otherSourceErpId, 'source_system' => 'BPLUS-ARCHIVE', 'erp_customer_code' => 'AR-MALI-01']);
    }
}
