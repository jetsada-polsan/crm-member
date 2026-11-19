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
        DB::table('crm_follow_up_tasks')->insert([
            'visit_id' => $visitId, 'assigned_to' => $staff[0]->id, 'title' => 'Call back', 'due_date' => '2026-11-18',
            'status' => 'open', 'created_at' => $now, 'updated_at' => $now,
        ]);
        DB::table('crm_leads')->insert([
            'shop_name' => 'Lead A', 'owner_staff_id' => $staff[0]->id, 'pipeline_stage' => 'interested',
            'created_at' => '2026-11-10 10:00:00', 'updated_at' => $now,
        ]);
        DB::table('crm_sales_targets')->insert([
            'staff_user_id' => $staff[0]->id, 'period_start' => '2026-11-14', 'period_end' => '2026-11-14',
            'target_amount' => 1200, 'target_visits' => 3, 'created_at' => $now, 'updated_at' => $now,
        ]);
        $planId = DB::table('crm_visit_plans')->insertGetId([
            'route_name' => 'Route 1', 'visit_date' => '2026-11-14', 'assigned_to' => $staff[0]->id,
            'created_by' => $manager->id, 'status' => 'planned', 'created_at' => $now, 'updated_at' => $now,
        ]);
        $stopId = DB::table('crm_visit_plan_stops')->insertGetId([
            'plan_id' => $planId, 'stop_order' => 1, 'erp_customer_id' => $customers[0],
            'status' => 'pending', 'created_at' => $now, 'updated_at' => $now,
        ]);
        DB::table('crm_customer_visits')->where('id', $visitId)->update(['plan_stop_id' => $stopId]);
        $nextPlan = DB::table('crm_visit_plans')->insertGetId([
            'route_name' => 'Next route', 'visit_date' => '2026-11-20', 'assigned_to' => $staff[0]->id,
            'created_by' => $manager->id, 'status' => 'planned', 'created_at' => $now, 'updated_at' => $now,
        ]);
        DB::table('crm_visit_plan_stops')->insert([
            'plan_id' => $nextPlan, 'stop_order' => 1, 'erp_customer_id' => $customers[0],
            'status' => 'pending', 'created_at' => $now, 'updated_at' => $now,
        ]);
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
        $this->assertSame('Call back', $response['follow_ups'][0]['title']);
        $this->assertSame('interested', $response['pipeline'][0]['pipeline_stage']);
        $this->assertEquals(1200, (float) $response['target']['target_amount']);
        $this->assertSame(1, $response['plan_summary']['planned_stops']);
        $this->assertSame(1, $response['plan_summary']['visited_planned_stops']);
        $this->assertSame('Next route', $response['upcoming_stops'][0]['route_name']);
        $this->getJson($url.'&staff_id='.$staff[1]->id)->assertForbidden();
        $this->getJson($url.'&route_code=R02')->assertOk()->assertJsonCount(0, 'visits')->assertJsonCount(0, 'follow_ups')->assertJsonCount(0, 'upcoming_stops');
        $this->getJson('/api/crm/visits?paginate=1&from=2026-11-14&to=2026-11-14&route_code=R01')
            ->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.id', $visitId);
        $this->getJson('/api/crm/visits?paginate=1&from=2026-11-14&to=2026-11-14&route_code=R02')
            ->assertOk()->assertJsonPath('meta.total', 0);
        $this->getJson('/api/crm/visits?from=2026-11-15&to=2026-11-14')->assertUnprocessable();
        DB::table('crm_customer_visits')->insert([
            'erp_customer_id' => $customers[0], 'staff_user_id' => $staff[1]->id,
            'visited_at' => '2026-11-15 10:00:00', 'result' => 'visited',
            'created_at' => $now, 'updated_at' => $now,
        ]);
        $this->getJson('/api/crm/customer-master')->assertOk();
        $masterId = DB::table('crm_customer_masters')->where('erp_customer_id', $customers[0])->value('id');
        $this->getJson('/api/crm/customer-master/'.$masterId)->assertOk()->assertJsonCount(2, 'visits');

        $this->actingAs($manager, 'staff');
        $this->getJson($url.'&staff_id='.$staff[1]->id)->assertOk()->assertJsonCount(1, 'visits')
            ->assertJsonPath('visits.0.staff_name', 'SL02');
        $this->getJson('/api/crm/reports/visit-presentation?from=2026-01-01&to=2026-02-01')->assertStatus(422);
    }
}
