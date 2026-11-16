<?php

namespace Tests\Feature;

use App\Models\StaffUser;
use App\Modules\ERPIntegration\ErpMirrorUpserter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class VisitPresentationTest extends TestCase
{
    use RefreshDatabase;

    public function test_report_scopes_visits_sales_and_photos_and_excludes_non_sales(): void
    {
        $now = now();
        $staff = collect(['SL01', 'SL02'])->map(function ($code) use ($now) {
            return StaffUser::query()->findOrFail(DB::table('staff_users')->insertGetId([
                'name' => $code, 'email' => $code.'@example.test', 'password' => Hash::make('test'),
                'role' => 'sales', 'salesperson_code' => $code, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now,
            ]));
        });
        $manager = StaffUser::query()->findOrFail(DB::table('staff_users')->insertGetId([
            'name' => 'Manager', 'email' => 'manager@example.test', 'password' => Hash::make('test'),
            'role' => 'manager', 'is_active' => true, 'created_at' => $now, 'updated_at' => $now,
        ]));
        $customers = [];
        foreach (['SL01', 'SL02'] as $index => $code) {
            $customers[] = DB::table('erp_customers')->insertGetId([
                'source_system' => 'BPLUS', 'erp_customer_code' => 'AR'.$index,
                'customer_name' => 'Shop '.$index, 'salesperson_code' => $code,
                'route_code' => $index ? 'R02' : 'R01', 'route_name' => 'Route',
                'created_at' => $now, 'updated_at' => $now,
            ]);
            DB::table('crm_customer_visits')->insert([
                'erp_customer_id' => $customers[$index], 'staff_user_id' => $staff[$index]->id,
                'visited_at' => '2026-11-14 10:00:00', 'result' => 'visited', 'shop_type' => 'existing',
                'customer_issues' => 'Real issue', 'created_at' => $now, 'updated_at' => $now,
            ]);
            ErpMirrorUpserter::upsertInvoiceWithItems('BPLUS', [
                'branch_code' => '001', 'doc_type' => 'IV', 'invoice_no' => 'INV'.$index,
                'erp_customer_code' => 'AR'.$index, 'salesperson_code' => $code, 'sold_at' => '2026-11-14 12:00:00',
                'dt_properties' => 307, 'status' => 'posted', 'before_vat_amount' => 1000,
            ], $now);
        }
        $visitId = DB::table('crm_customer_visits')->where('staff_user_id', $staff[0]->id)->value('id');
        DB::table('crm_customer_visits')->insert([
            'erp_customer_id' => $customers[0], 'staff_user_id' => $staff[0]->id,
            'visited_at' => '2026-11-13 20:00:00', 'result' => 'visited', 'shop_type' => 'existing',
            'created_at' => $now, 'updated_at' => $now,
        ]);
        DB::table('crm_visit_photos')->insert(['visit_id' => $visitId, 'storage_path' => 'visits/photo.jpg', 'category' => 'storefront', 'uploaded_by' => $staff[0]->id, 'created_at' => $now, 'updated_at' => $now]);
        ErpMirrorUpserter::upsertInvoiceWithItems('BPLUS', [
            'branch_code' => '001', 'doc_type' => 'CN', 'invoice_no' => 'RET1', 'erp_customer_code' => 'AR0',
            'salesperson_code' => 'SL01', 'sold_at' => '2026-11-14 12:00:00', 'dt_properties' => 308,
            'status' => 'returned', 'before_vat_amount' => 200,
        ], $now);
        ErpMirrorUpserter::upsertInvoiceWithItems('BPLUS', [
            'branch_code' => '001', 'doc_type' => 'SO', 'invoice_no' => 'SO1', 'erp_customer_code' => 'AR0',
            'salesperson_code' => 'SL01', 'sold_at' => '2026-11-14 12:00:00', 'dt_properties' => 207,
            'status' => 'reserved', 'before_vat_amount' => 9000,
        ], $now);

        $url = '/api/crm/reports/visit-presentation?from=2026-11-14&to=2026-11-14';
        $this->actingAs($staff[0], 'staff');
        $response = $this->getJson($url)->assertOk()->json();
        $this->assertCount(1, $response['visits']);
        $this->assertSame('Real issue', $response['visits'][0]['customer_issues']);
        $this->assertCount(1, $response['visits'][0]['photos']);
        $this->assertEquals(800, (float) $response['sales']['net_before_vat']);
        $this->assertCount(1, $response['daily_sales']);
        $this->assertEquals(800, (float) $response['daily_sales'][0]['net_before_vat']);
        $this->getJson($url.'&staff_id='.$staff[1]->id)->assertForbidden();
        $this->getJson($url.'&route_code=R02')->assertOk()->assertJsonCount(0, 'visits');

        $this->actingAs($manager, 'staff');
        $this->getJson($url.'&staff_id='.$staff[1]->id)->assertOk()->assertJsonCount(1, 'visits')
            ->assertJsonPath('visits.0.staff_name', 'SL02');
        $this->getJson('/api/crm/reports/visit-presentation?from=2026-01-01&to=2026-02-01')->assertStatus(422);
    }
}
