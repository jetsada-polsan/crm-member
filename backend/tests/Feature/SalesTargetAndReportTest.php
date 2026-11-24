<?php

namespace Tests\Feature;

use App\Models\StaffUser;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SalesTargetAndReportTest extends TestCase
{
    use LazilyRefreshDatabase;

    private function createStaff(string $role, ?string $salespersonCode = null, bool $isActive = true): StaffUser
    {
        $id = DB::table('staff_users')->insertGetId([
            'name' => 'Staff '.$role,
            'email' => $role.uniqid().'@popstar.local',
            'password' => Hash::make('Secret123!'),
            'role' => $role,
            'salesperson_code' => $salespersonCode,
            'is_active' => $isActive,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return StaffUser::query()->findOrFail($id);
    }

    private function product(string $code): int
    {
        return DB::table('erp_products')->insertGetId([
            'source_system' => 'BPLUS', 'erp_product_code' => $code, 'product_name' => 'Product '.$code,
            'unit' => 'box', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_manager_sets_a_monthly_target_and_saving_again_updates_it(): void
    {
        $sales = $this->createStaff('sales', 'S01');
        $this->actingAs($this->createStaff('manager'), 'staff');

        $payload = ['month' => '2026-12', 'staff_user_id' => $sales->id, 'target_amount' => 150000, 'target_visits' => 40];
        $this->postJson('/api/crm/targets', $payload)->assertOk();
        $this->postJson('/api/crm/targets', [...$payload, 'target_amount' => 180000])->assertOk();

        $targets = DB::table('crm_sales_targets')->where('staff_user_id', $sales->id)->get();
        $this->assertCount(1, $targets);
        $this->assertSame('2026-12-01', substr((string) $targets[0]->period_start, 0, 10));
        $this->assertSame('2026-12-31', substr((string) $targets[0]->period_end, 0, 10));
        $this->assertEquals(180000, $targets[0]->target_amount);
        $this->assertSame(40, (int) $targets[0]->target_visits);
    }

    public function test_apply_to_all_sets_targets_for_active_sales_staff_only(): void
    {
        $activeSales = $this->createStaff('sales', 'S01');
        $inactiveSales = $this->createStaff('sales', 'S02', isActive: false);
        $manager = $this->createStaff('manager');
        $this->actingAs($manager, 'staff');

        $this->postJson('/api/crm/targets', ['month' => '2026-12', 'apply_to_all' => true, 'target_amount' => 1000, 'target_visits' => 5])
            ->assertOk();

        $this->assertSame(
            [$activeSales->id],
            DB::table('crm_sales_targets')->pluck('staff_user_id')->map(fn ($id) => (int) $id)->all()
        );
        $this->assertDatabaseMissing('crm_sales_targets', ['staff_user_id' => $inactiveSales->id]);
    }

    public function test_targets_require_a_manager_and_valid_input(): void
    {
        $sales = $this->createStaff('sales', 'S01');
        $this->actingAs($sales, 'staff');
        $this->postJson('/api/crm/targets', ['staff_user_id' => $sales->id, 'target_amount' => 1, 'target_visits' => 1])
            ->assertForbidden();

        $this->actingAs($this->createStaff('admin'), 'staff');
        $this->postJson('/api/crm/targets', ['staff_user_id' => $sales->id, 'target_amount' => -1, 'target_visits' => 1])
            ->assertUnprocessable()->assertJsonValidationErrors('target_amount');
        $this->postJson('/api/crm/targets', ['month' => '12-2026', 'staff_user_id' => $sales->id, 'target_amount' => 1, 'target_visits' => 1])
            ->assertUnprocessable()->assertJsonValidationErrors('month');
        $this->postJson('/api/crm/targets', ['target_amount' => 1, 'target_visits' => 1])->assertUnprocessable();
        $this->assertDatabaseCount('crm_sales_targets', 0);
    }

    public function test_manager_assigns_changes_and_removes_a_product_size_segment(): void
    {
        $productId = $this->product('FG-001');
        $this->actingAs($this->createStaff('manager'), 'staff');

        $this->postJson('/api/crm/reports/product-size-segments', [
            'source_system' => 'bplus', 'erp_product_code' => 'FG-001', 'size_code' => 'l', 'size_label' => 'ใหญ่',
        ])->assertOk()->assertJson(['size_code' => 'L']);
        $this->postJson('/api/crm/reports/product-size-segments', [
            'source_system' => 'BPLUS', 'erp_product_code' => 'FG-001', 'size_code' => 'M', 'size_label' => 'กลาง',
        ])->assertOk();

        $segments = DB::table('erp_product_size_segments')->where('erp_product_id', $productId)->get();
        $this->assertCount(1, $segments);
        $this->assertSame('M', $segments[0]->size_code);

        $this->getJson('/api/crm/reports/product-size-segments?q=FG-001')
            ->assertOk()
            ->assertJsonPath('0.erp_product_code', 'FG-001')
            ->assertJsonPath('0.size_code', 'M');
        $this->getJson('/api/crm/reports/sales-dimensions')
            ->assertOk()
            ->assertJsonPath('size_segments.0.size_code', 'M')
            ->assertJsonPath('size_segments.0.product_count', 1);

        $this->deleteJson("/api/crm/reports/product-size-segments/{$segments[0]->id}")->assertOk();
        $this->assertDatabaseCount('erp_product_size_segments', 0);
    }

    public function test_size_segments_reject_unknown_products_and_non_managers(): void
    {
        $this->product('FG-001');

        $this->actingAs($this->createStaff('sales', 'S01'), 'staff');
        $this->getJson('/api/crm/reports/product-size-segments')->assertForbidden();
        $this->postJson('/api/crm/reports/product-size-segments', [
            'source_system' => 'BPLUS', 'erp_product_code' => 'FG-001', 'size_code' => 'L', 'size_label' => 'Large',
        ])->assertForbidden();

        $this->actingAs($this->createStaff('admin'), 'staff');
        $this->postJson('/api/crm/reports/product-size-segments', [
            'source_system' => 'BPLUS', 'erp_product_code' => 'NOT-SYNCED', 'size_code' => 'L', 'size_label' => 'Large',
        ])->assertNotFound();
        $this->assertDatabaseCount('erp_product_size_segments', 0);
    }

    public function test_sales_dimensions_show_sales_staff_only_their_own_routes_and_salesperson(): void
    {
        $now = now();
        DB::table('erp_customers')->insert([
            ['source_system' => 'BPLUS', 'erp_customer_code' => 'AR001', 'customer_name' => 'Mine', 'salesperson_code' => 'S01',
                'route_code' => 'R01', 'route_name' => 'Route 1', 'created_at' => $now, 'updated_at' => $now],
            ['source_system' => 'BPLUS', 'erp_customer_code' => 'AR002', 'customer_name' => 'Theirs', 'salesperson_code' => 'S02',
                'route_code' => 'R02', 'route_name' => 'Route 2', 'created_at' => $now, 'updated_at' => $now],
        ]);
        DB::table('erp_salespersons')->insert([
            ['source_system' => 'BPLUS', 'salesperson_code' => 'S01', 'salesperson_name' => 'One', 'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
            ['source_system' => 'BPLUS', 'salesperson_code' => 'S02', 'salesperson_name' => 'Two', 'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
        ]);

        $this->actingAs($this->createStaff('sales', 'S01'), 'staff');
        $this->getJson('/api/crm/reports/sales-dimensions')
            ->assertOk()
            ->assertJsonCount(1, 'routes')
            ->assertJsonPath('routes.0.route_code', 'R01')
            ->assertJsonCount(1, 'salespersons')
            ->assertJsonPath('salespersons.0.salesperson_code', 'S01');

        $this->actingAs($this->createStaff('manager'), 'staff');
        $this->getJson('/api/crm/reports/sales-dimensions')
            ->assertOk()
            ->assertJsonCount(2, 'routes')
            ->assertJsonCount(2, 'salespersons');
    }

    public function test_inactive_customers_report_loads_for_sales_and_managers(): void
    {
        DB::table('erp_customers')->insert([
            'source_system' => 'BPLUS', 'erp_customer_code' => 'AR001', 'customer_name' => 'Quiet shop',
            'salesperson_code' => 'S01', 'created_at' => now(), 'updated_at' => now(),
        ]);

        foreach ([$this->createStaff('sales', 'S01'), $this->createStaff('manager')] as $staff) {
            $this->actingAs($staff, 'staff');
            $this->getJson('/api/crm/inactive-customers?days=30')
                ->assertOk()
                ->assertJsonPath('days_threshold', 30)
                ->assertJsonStructure(['reference_date', 'summary', 'rows']);
        }
    }

    public function test_system_health_is_reported_without_reaching_real_services(): void
    {
        Http::preventStrayRequests();
        Http::fake(['*' => Http::response(['ok' => true], 200)]);
        config()->set('popstar.office_sql_host', '127.0.0.1');
        config()->set('popstar.office_sql_port', 1);
        $this->actingAs($this->createStaff('admin'), 'staff');

        $this->getJson('/api/crm/system-health')
            ->assertOk()
            ->assertJsonStructure(['checked_at', 'overall_status', 'api', 'bridge', 'line', 'mirror', 'crm']);
    }

    public function test_reports_require_a_signed_in_staff_member(): void
    {
        foreach (['/api/crm/reports/sales-dimensions', '/api/crm/reports/product-size-segments', '/api/crm/inactive-customers', '/api/crm/system-health', '/api/crm/targets'] as $path) {
            $this->getJson($path)->assertUnauthorized();
        }
    }
}
