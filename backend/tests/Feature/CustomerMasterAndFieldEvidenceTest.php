<?php

namespace Tests\Feature;

use App\Models\StaffUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class CustomerMasterAndFieldEvidenceTest extends TestCase
{
    use RefreshDatabase;

    private function fakePng(string $name = 'photo.png'): \Illuminate\Http\Testing\File
    {
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==');
        return UploadedFile::fake()->createWithContent($name, $png);
    }

    private function createStaff(string $role, ?string $salesCode = null): StaffUser
    {
        $id = DB::table('staff_users')->insertGetId([
            'name' => ucfirst($role) . ' User',
            'email' => $role . uniqid() . '@popstar.local',
            'password' => Hash::make('Secret123!'),
            'role' => $role,
            'salesperson_code' => $salesCode,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return StaffUser::query()->findOrFail($id);
    }

    public function test_server_pagination_and_filters_preserve_legacy_array_mode(): void
    {
        $mgr = $this->createStaff('manager');
        $this->actingAs($mgr, 'staff');

        for ($i = 1; $i <= 15; $i++) {
            DB::table('erp_customers')->insert([
                'source_system' => 'BPLUS',
                'erp_customer_code' => sprintf('AR-%03d', $i),
                'customer_name' => sprintf('Shop %02d', $i),
                'phone' => sprintf('08100000%02d', $i),
                'province' => $i <= 5 ? 'กรุงเทพมหานคร' : 'เชียงใหม่',
                'salesperson_code' => 'SL01',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        // Legacy mode returns flat JSON array
        $legacy = $this->getJson('/api/crm/customers')->assertOk()->json();
        $this->assertCount(15, $legacy);

        // Paginated mode respects per_page & province filter
        $page1 = $this->getJson('/api/crm/customers?paginate=1&per_page=10&page=1')->assertOk()->json();
        $this->assertCount(10, $page1['data']);
        $this->assertSame(15, $page1['meta']['total']);
        $this->assertSame(2, $page1['meta']['last_page']);

        $bkk = $this->getJson('/api/crm/customers?paginate=1&province=' . urlencode('กรุงเทพมหานคร'))->assertOk()->json();
        $this->assertSame(5, $bkk['meta']['total']);
    }

    public function test_quick_lead_and_customer_master_never_auto_create_erp_or_phone_verify_member(): void
    {
        Storage::fake('local');
        $rep = $this->createStaff('sales', 'SL01');
        $otherRep = $this->createStaff('sales', 'SL02');
        $mgr = $this->createStaff('manager');

        // Existing ERP customer owned by SL01
        $erpId = DB::table('erp_customers')->insertGetId([
            'source_system' => 'BPLUS',
            'erp_customer_code' => 'AR-REAL-01',
            'customer_name' => 'ร้านเดิมใน ERP',
            'phone' => '0891112222',
            'province' => 'นนทบุรี',
            'salesperson_code' => 'SL01',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Unlinked Member sharing same phone should NEVER auto-verify or link
        DB::table('member_accounts')->insert([
            'name' => 'Phone Match Only',
            'email' => 'member@popstar.local',
            'password' => Hash::make('Secret123!'),
            'phone' => '0891112222',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($rep, 'staff');

        // Duplicate check detects erp_customer_code too
        $dup = $this->postJson('/api/crm/leads/check-duplicate', [
            'shop_name' => 'ร้านเปิดใหม่ไม่ซ้ำชื่อ',
            'erp_customer_code' => 'AR-REAL-01',
        ])->assertOk()->json();
        $this->assertNotEmpty($dup['duplicates']);
        $this->assertSame('AR-REAL-01', $dup['duplicates'][0]['code']);

        // Quick create genuine new lead with extended fields
        $created = $this->post('/api/crm/leads/quick', [
            'shop_name' => 'ร้านสตาร์มาร์ท เปิดใหม่',
            'phone' => '0825556666',
            'shop_type' => 'มินิมาร์ท',
            'contact_name' => 'คุณสมศรี',
            'province' => 'ปทุมธานี',
            'district' => 'คลองหลวง',
            'supplier_source' => 'ยี่ปั๊วตลาดไท',
            'product_categories' => 'เครื่องดื่ม, ขนม',
            'assigned_salesperson_code' => 'SL01',
            'latitude' => 14.0701,
            'longitude' => 100.6123,
            'photo' => $this->fakePng('storefront.png'),
            'compressed_on_client' => '1',
        ])->assertStatus(201)->json();

        $leadId = $created['id'];
        $masterId = $created['customer_master_id'];
        $this->assertNotNull($masterId);

        // Must NOT auto-create ERP customer row
        $this->assertSame(1, DB::table('erp_customers')->count());

        // Customer Master lists separated items & enforces rep RBAC
        $repMaster = $this->getJson('/api/crm/customer-master')->assertOk()->json();
        $this->assertSame(2, $repMaster['meta']['total']);
        foreach ($repMaster['data'] as $row) {
            $this->assertNull($row['member_verification_status']);
        }

        // Other sales rep cannot read SL01's Customer Master detail
        $this->actingAs($otherRep, 'staff');
        $this->getJson("/api/crm/customer-master/{$masterId}")->assertStatus(403);
        $this->assertSame(0, $this->getJson('/api/crm/customer-master')->assertOk()->json('meta.total'));

        // Manager links lead explicitly to existing ERP customer without erasing lead GPS
        $this->actingAs($mgr, 'staff');
        $this->postJson("/api/crm/customer-master/leads/{$leadId}/link-erp", [
            'erp_customer_id' => $erpId,
            'note' => 'Verified new account converted in Bplus',
        ])->assertOk()->assertJsonPath('customer_master.link_status', 'linked');

        $detail = $this->getJson("/api/crm/customer-master/{$masterId}")->assertOk()->json();
        $this->assertSame('AR-REAL-01', $detail['erp_customer']['erp_customer_code']);
        $this->assertNotNull($detail['crm_location']);
        $this->assertTrue($detail['separation_guarantees']['erp_sync_preserves_crm_gps_photos_visits']);
    }

    public function test_long_erp_phone_never_truncates_customer_master(): void
    {
        $mgr = $this->createStaff('manager');
        DB::table('erp_customers')->insert([
            'source_system' => 'BPLUS',
            'erp_customer_code' => 'AR-LONG-PHONE',
            'customer_name' => 'ร้านสาขาหลายเบอร์โทร',
            'phone' => '081-234-5678, 082-345-6789, 083-456-7890, 084-567-8901, 085-678-9012',
            'province' => 'กรุงเทพมหานคร',
            'salesperson_code' => 'SL02',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($mgr, 'staff');
        $longRes = $this->getJson('/api/crm/customer-master?q=AR-LONG-PHONE')->assertOk()->json();
        $this->assertSame(1, $longRes['meta']['total']);
        $this->assertStringContainsString('085-678-9012', $longRes['data'][0]['phone']);
    }

    public function test_visit_evidence_idempotency_photo_consent_and_timestamps(): void
    {
        Storage::fake('local');
        $rep = $this->createStaff('sales', 'SL01');
        $otherRep = $this->createStaff('sales', 'SL02');

        $erpId = DB::table('erp_customers')->insertGetId([
            'source_system' => 'BPLUS',
            'erp_customer_code' => 'AR-V1',
            'customer_name' => 'ร้านตัวอย่างเข้าพบ',
            'salesperson_code' => 'SL01',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($rep, 'staff');
        $payload = [
            'erp_customer_id' => $erpId,
            'client_draft_id' => 'draft-mobile-uuid-001',
            'visited_at' => '2026-11-15 10:15:00',
            'device_recorded_at' => '2026-11-15 10:14:55',
            'result' => 'follow_up',
            'shop_type' => 'existing',
            'supplier_name' => 'ซัพเดิม A',
            'product_types' => 'Popstar Orange',
            'customer_issues' => 'ตู้แช่เต็ม',
            'customer_feedback' => 'ขอดูโปรเดือนหน้า',
            'follow_up_action' => 'ส่งใบเสนอราคา',
            'follow_up_date' => '2026-11-20',
            'checkin_latitude' => 13.7563,
            'checkin_longitude' => 100.5018,
            'checkin_accuracy_m' => 12,
            'gps_permission_status' => 'granted',
        ];

        $first = $this->postJson('/api/crm/field-visits', $payload)->assertStatus(201)->json();
        $this->assertFalse($first['deduplicated']);
        $this->assertNotEmpty($first['server_recorded_at']);

        // Replay same offline draft returns deduplicated 200 without duplicating DB row
        $replay = $this->postJson('/api/crm/field-visits', $payload)->assertOk()->json();
        $this->assertTrue($replay['deduplicated']);
        $this->assertSame($first['id'], $replay['id']);
        $this->assertSame(1, DB::table('crm_customer_visits')->count());

        $visitId = $first['id'];

        // Photo with customer_discussion requires consent_confirmed = true
        $this->postJson("/api/crm/field-visits/{$visitId}/photos", [
            'category' => 'customer_discussion',
            'consent_confirmed' => false,
            'photo' => $this->fakePng('cust.png'),
        ])->assertStatus(422);

        $photoRes = $this->post("/api/crm/field-visits/{$visitId}/photos", [
            'category' => 'customer_discussion',
            'consent_confirmed' => '1',
            'compressed_on_client' => '1',
            'latitude' => 13.7563,
            'longitude' => 100.5018,
            'accuracy_m' => 12,
            'image_width' => 640,
            'image_height' => 480,
            'photo' => $this->fakePng('cust_ok.png'),
        ])->assertStatus(201)->json();

        // Inline download allowed for owner, forbidden for other rep
        $this->get("/api/crm/photos/{$photoRes['id']}?inline=1")->assertOk();
        $otherErpId = DB::table('erp_customers')->insertGetId([
            'source_system' => 'BPLUS',
            'erp_customer_code' => 'AR-V2',
            'customer_name' => 'ร้านอีกสายขาย',
            'salesperson_code' => 'SL02',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->actingAs($otherRep, 'staff');
        $this->getJson("/api/crm/field-visits/{$visitId}/photos")->assertStatus(403);
        $this->get("/api/crm/photos/{$photoRes['id']}?inline=1")->assertStatus(403);
        $this->postJson('/api/crm/field-visits', $payload)->assertStatus(403);
        $this->postJson('/api/crm/field-visits', [...$payload, 'erp_customer_id' => $otherErpId])->assertStatus(409);

        // Visit check-in GPS auto-saved to crm_customer_locations and exposed with latest visit & authorized photos on /api/crm/pins
        $this->actingAs($rep, 'staff');
        $repPins = $this->getJson('/api/crm/pins')->assertOk()->json();
        $this->assertCount(1, $repPins);
        $this->assertSame($erpId, (int) $repPins[0]['erp_customer_id']);
        $this->assertSame(13.7563, round((float) $repPins[0]['latitude'], 4));
        $this->assertSame(1, (int) $repPins[0]['visit_count']);
        $this->assertSame($visitId, (int) $repPins[0]['latest_visit']['id']);
        $this->assertSame(1, (int) $repPins[0]['photo_count']);
        $this->assertCount(1, $repPins[0]['photos']);
        $this->assertSame("/api/crm/photos/{$photoRes['id']}?inline=1", $repPins[0]['photos'][0]['url']);

        // Legacy/historical visit rows with GPS (without crm_customer_locations row) also appear for authorized staff
        DB::table('crm_customer_visits')->insert([
            'erp_customer_id' => $otherErpId,
            'staff_user_id' => $otherRep->id,
            'visited_at' => now(),
            'result' => 'visited',
            'shop_type' => 'existing',
            'checkin_latitude' => 15.365976,
            'checkin_longitude' => 104.8080375,
            'gps_permission_status' => 'granted',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Own rep still sees only their own scoped pin, while manual pin is preserved against subsequent GPS check-in
        $this->assertCount(1, $this->getJson('/api/crm/pins')->assertOk()->json());
        $this->postJson('/api/crm/pins', [
            'erp_customer_id' => $erpId,
            'latitude' => 15.2287,
            'longitude' => 104.8564,
            'source' => 'manual',
        ])->assertOk();
        $this->postJson('/api/crm/field-visits', [
            ...$payload,
            'client_draft_id' => 'draft-mobile-uuid-002',
            'checkin_latitude' => 13.75,
            'checkin_longitude' => 100.51,
        ])->assertStatus(201);
        $updatedPins = $this->getJson('/api/crm/pins')->assertOk()->json();
        $this->assertSame(15.2287, round((float) $updatedPins[0]['latitude'], 4));
        $this->assertSame(2, (int) $updatedPins[0]['visit_count']);

        // Manager sees both pinned locations + visit photos across reps
        $mgr = $this->createStaff('manager');
        $this->actingAs($mgr, 'staff');
        $mgrPins = $this->getJson('/api/crm/pins')->assertOk()->json();
        $this->assertCount(2, $mgrPins);
        $erpPin = collect($mgrPins)->firstWhere('erp_customer_id', $erpId);
        $this->assertSame(1, (int) $erpPin['photo_count']);
        $this->get($erpPin['photos'][0]['url'])->assertOk();
    }
}
