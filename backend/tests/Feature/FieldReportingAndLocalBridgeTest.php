<?php

namespace Tests\Feature;

use App\Models\StaffUser;
use App\Modules\ERPIntegration\ErpLocalSyncController;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class FieldReportingAndLocalBridgeTest extends TestCase
{
    use RefreshDatabase;

    private function createUser(string $role, ?string $slmnCode = null): StaffUser
    {
        $id = DB::table('staff_users')->insertGetId([
            'name' => 'Staff '.$role,
            'email' => $role.uniqid().'@popstar.local',
            'password' => Hash::make('Secret123!'),
            'role' => $role,
            'salesperson_code' => $slmnCode,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return StaffUser::query()->findOrFail($id);
    }

    public function test_field_visit_report_exports_json_safe_csv_and_escaped_html(): void
    {
        $rep = $this->createUser('sales', 'SL01');
        $other = $this->createUser('sales', 'SL02');
        $mgr = $this->createUser('manager');

        $custId = DB::table('erp_customers')->insertGetId([
            'source_system' => 'BPLUS',
            'erp_customer_code' => 'AR-F1',
            'customer_name' => '=CMD("calc")',
            'salesperson_code' => 'SL01',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($rep, 'staff');
        $this->postJson('/api/crm/field-visits', [
            'erp_customer_id' => $custId,
            'visited_at' => '2026-11-14 09:30:00',
            'result' => 'interested',
            'checkin_latitude' => 13.7563,
            'checkin_longitude' => 100.5018,
            'shop_type' => 'existing',
            'shop_phone' => '0812345678',
            'supplier_name' => 'Supplier X',
            'product_types' => 'Popstar Drink',
            'customer_issues' => '<script>alert(1)</script>',
            'customer_feedback' => '+FORMULA',
            'follow_up_action' => 'Send quote',
        ])->assertStatus(201);

        // Manager creates a visit for another rep
        DB::table('crm_customer_visits')->insert([
            'staff_user_id' => $other->id,
            'visited_at' => '2026-11-14 10:00:00',
            'result' => 'visited',
            'shop_type' => 'new',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Sales rep sees ONLY their own 1 visit (even if trying ?staff_id=...)
        $repJson = $this->getJson('/api/crm/reports/field-visits?staff_id='.$other->id)->assertOk()->json();
        $this->assertSame(1, $repJson['summary']['total_visits']);

        // Manager sees both 2 visits
        $this->actingAs($mgr, 'staff');
        $mgrJson = $this->getJson('/api/crm/reports/field-visits')->assertOk()->json();
        $this->assertSame(2, $mgrJson['summary']['total_visits']);

        // CSV neutralizes formula injection & adds UTF-8 BOM
        $csvRes = $this->get('/api/crm/reports/field-visits?format=csv')->assertOk();
        $csvRes->assertHeader('Content-Type', 'text/csv; charset=UTF-8');
        $body = $csvRes->getContent();
        $this->assertStringStartsWith("\xEF\xBB\xBF", $body);
        $this->assertStringContainsString("'=CMD(", $body);
        $this->assertStringContainsString("'+FORMULA", $body);

        // Print HTML escapes script tags
        $htmlRes = $this->get('/api/crm/reports/field-visits?format=print')->assertOk();
        $html = $htmlRes->getContent();
        $this->assertStringNotContainsString('<script>alert(1)</script>', $html);
        $this->assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $html);

        $reconciliationCsv = $this->get('/api/crm/reports/reconciliation?format=csv')->assertOk();
        $reconciliationCsv->assertHeader('Content-Type', 'text/csv; charset=UTF-8');
        $this->assertStringStartsWith("\xEF\xBB\xBF", $reconciliationCsv->getContent());
        $reconciliationPrint = $this->get('/api/crm/reports/reconciliation?format=print')->assertOk();
        $reconciliationPrint->assertHeader('Content-Type', 'text/html; charset=UTF-8');
    }

    public function test_local_bridge_sync_monitor_syncs_all_kinds_and_masks_passwords_in_logs(): void
    {
        $admin = $this->createUser('admin');
        $this->actingAs($admin, 'staff');

        Http::fake([
            'http://host.docker.internal:18900/health' => Http::response([
                'ok' => true,
                'driver' => '.NET SqlClient',
                'sql_configured' => true,
                'message' => 'Bridge ready',
            ], 200),
            'http://host.docker.internal:18900/preview/products*' => Http::response([
                'ok' => true,
                'source' => 'BPLUS',
                'records' => [['erp_product_code' => 'P01', 'product_name' => 'Drink', 'category_code' => 'FG']],
            ], 200),
            'http://host.docker.internal:18900/preview/invoices*' => Http::response([
                'ok' => true,
                'source' => 'BPLUS',
                'records' => [['invoice_no' => 'SO-1', 'dt_properties' => 207, 'doc_category' => 'reservation']],
            ], 200),
            'http://host.docker.internal:18900/export/salespersons*' => Http::response([
                'ok' => true,
                'source' => 'BPLUS',
                'records' => [['salesperson_code' => 'SL01', 'salesperson_name' => 'สมชาย', 'line_name' => 'สายเหนือ']],
            ], 200),
            'http://host.docker.internal:18900/export/members*' => Http::response([
                'ok' => true,
                'source' => 'BPLUS',
                'records' => [['member_code' => 'MB01', 'full_name' => 'คุณกานต์', 'erp_point_balance' => 150]],
            ], 200),
            'http://host.docker.internal:18900/test-connection' => Http::response([
                'ok' => false,
                'error' => 'Login failed for Server=192.168.88.201;Password=TopSecretPass!;User Id=demo',
            ], 500),
        ]);

        $this->postJson('/api/crm/sync-monitor/test-products-10')->assertOk()->assertJsonPath('count', 1);
        $this->postJson('/api/crm/sync-monitor/test-invoices-10')->assertOk()->assertJsonPath('count', 1);
        $this->postJson('/api/crm/sync-monitor/sync/salespersons')->assertOk()->assertJson(['ok' => true, 'inserted' => 1]);
        $this->postJson('/api/crm/sync-monitor/sync/members')->assertOk()->assertJson(['ok' => true, 'inserted' => 1]);

        // Re-sync is idempotent update
        $this->postJson('/api/crm/sync-monitor/sync/members')->assertOk()->assertJson(['ok' => true, 'inserted' => 0, 'updated' => 1]);

        $invoiceSnapshot = [
            'ok' => true,
            'source' => 'BPLUS',
            'next_cursor' => 900,
            'has_more' => false,
            'records' => [[
                'di_key' => 900,
                'branch_code' => '001',
                'doc_type' => 'IV',
                'dt_properties' => 307,
                'invoice_no' => 'IV-CURSOR-1',
                'erp_customer_code' => 'AR-CURSOR-1',
                'sold_at' => '2026-11-12 10:00:00',
                'before_vat_amount' => 100,
                'vat_amount' => 7,
                'gross_amount' => 107,
                'status' => 'posted',
                'items_complete' => true,
                'items' => [[
                    'line_no' => 1,
                    'erp_product_code' => 'P-CURSOR-1',
                    'product_name' => 'Cursor product',
                    'qty' => 1,
                ]],
            ]],
        ];
        $updatedInvoiceSnapshot = $invoiceSnapshot;
        $updatedInvoiceSnapshot['records'][0]['items'][0]['product_name'] = 'Cursor product updated';
        Http::fake([
            'http://host.docker.internal:18900/export/invoices*' => Http::sequence()
                ->push($invoiceSnapshot, 200)
                ->push($updatedInvoiceSnapshot, 200),
        ]);

        $this->postJson('/api/crm/sync-monitor/sync/invoices')
            ->assertOk()
            ->assertJson(['ok' => true, 'inserted' => 1, 'next_cursor' => 900, 'has_more' => false]);
        $this->assertDatabaseHas('erp_sync_cursors', [
            'source_system' => 'BPLUS',
            'kind' => 'invoices',
            'cursor_value' => 900,
        ]);
        $this->postJson('/api/crm/sync-monitor/sync/invoices')
            ->assertOk()
            ->assertJson(['ok' => true, 'inserted' => 0, 'updated' => 1, 'next_cursor' => 900]);
        $this->assertDatabaseHas('erp_sales_items', [
            'invoice_no' => 'IV-CURSOR-1',
            'product_name' => 'Cursor product updated',
        ]);

        // Failed SQL test masks password in response & logs
        $errRes = $this->postJson('/api/crm/sync-monitor/test-connection')->assertStatus(422)->json();
        $this->assertStringNotContainsString('TopSecretPass!', $errRes['error']);
        $this->assertStringContainsString('Password=***', $errRes['error']);

        $status = $this->getJson('/api/crm/sync-monitor/status')->assertOk()->json();
        $this->assertSame(1, $status['counts']['salespersons']);
        $this->assertSame(1, $status['counts']['members']);
        $this->assertTrue($status['bridge']['reachable']);
    }

    public function test_customer_sync_hides_deactivated_existing_customers_and_skips_new_inactive_ones(): void
    {
        $this->actingAs($this->createUser('admin'), 'staff');
        DB::table('erp_customers')->insert([
            'source_system' => 'BPLUS', 'erp_customer_code' => 'AR-OLD',
            'customer_name' => 'Existing shop', 'ar_enable' => 'Y',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        Http::fake(['http://host.docker.internal:18900/export/customers*' => Http::response([
            'ok' => true, 'source' => 'BPLUS', 'records' => [
                ['erp_customer_code' => 'AR-OLD', 'customer_name' => 'Existing shop', 'ar_enable' => 'N'],
                ['erp_customer_code' => 'AR-NEW-OFF', 'customer_name' => 'Inactive shop', 'ar_enable' => 'N'],
                ['erp_customer_code' => 'AR-NEW-ON', 'customer_name' => 'Active shop', 'ar_enable' => 'Y'],
            ],
        ], 200)]);
        $this->postJson('/api/crm/sync-monitor/sync/customers')->assertOk()
            ->assertJson(['fetched' => 3, 'updated' => 1, 'inserted' => 1, 'skipped' => 1]);
        $this->assertDatabaseHas('erp_customers', ['erp_customer_code' => 'AR-OLD', 'ar_enable' => 'N']);
        $this->assertDatabaseMissing('erp_customers', ['erp_customer_code' => 'AR-NEW-OFF']);
        $this->getJson('/api/crm/customers')->assertOk()->assertJsonCount(1)
            ->assertJsonPath('0.erp_customer_code', 'AR-NEW-ON');
    }

    public function test_customer_sync_keeps_combined_phone_values_and_redacts_sql_bindings(): void
    {
        $admin = $this->createUser('admin');
        $this->actingAs($admin, 'staff');
        $phone = implode(' / ', array_fill(0, 6, '081-234-5678'));

        Http::fake([
            'http://host.docker.internal:18900/export/customers*' => Http::response([
                'ok' => true,
                'source' => 'BPLUS',
                'records' => [[
                    'erp_customer_code' => 'AR-MULTI-PHONE',
                    'customer_name' => 'Multiple Phone Shop',
                    'phone' => $phone,
                ]],
            ], 200),
        ]);

        $this->postJson('/api/crm/sync-monitor/sync/customers')
            ->assertOk()
            ->assertJson(['ok' => true, 'fetched' => 1, 'inserted' => 1, 'row_errors' => 0]);

        $this->assertDatabaseHas('erp_customers', [
            'source_system' => 'BPLUS',
            'erp_customer_code' => 'AR-MULTI-PHONE',
            'phone' => $phone,
        ]);

        $controller = app(ErpLocalSyncController::class);
        $method = new \ReflectionMethod($controller, 'sanitizeMessage');
        $safe = $method->invoke($controller, "SQLSTATE[22001]: value too long (Connection: pgsql, SQL: insert into customers values ('private customer value'))");
        $this->assertStringContainsString('statement redacted', $safe);
        $this->assertStringNotContainsString('private customer value', $safe);

        $recordKey = new \ReflectionMethod($controller, 'safeRecordKey');
        $this->assertSame('AR-MULTI-PHONE', $recordKey->invoke($controller, [
            'erp_customer_code' => 'AR-MULTI-PHONE',
            'customer_name' => 'Private Customer Name',
        ]));
    }

    public function test_lead_pipeline_erp_sales_totals_follow_b3_rules(): void
    {
        $rep = $this->createUser('sales', 'SL01');
        $mgr = $this->createUser('manager');

        $custId = DB::table('erp_customers')->insertGetId([
            'source_system' => 'BPLUS',
            'erp_customer_code' => 'AR-LEAD1',
            'customer_name' => 'Lead Converted Shop',
            'salesperson_code' => 'SL01',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Seed 1 sale (1000 pre-VAT with 150 PK excluded item), 1 reservation (5000), 1 cancelled (800)
        DB::table('erp_sales_invoices')->insert([
            [
                'source_system' => 'BPLUS', 'branch_code' => '001', 'doc_type' => 'IV', 'dt_properties' => 307,
                'doc_category' => 'sale', 'invoice_no' => 'IV-L1', 'erp_customer_code' => 'AR-LEAD1',
                'sold_at' => '2026-11-10', 'before_vat_amount' => 1000, 'vat_amount' => 70, 'gross_amount' => 1070,
                'net_amount' => 1000, 'status' => 'posted', 'created_at' => now(), 'updated_at' => now(),
            ],
            [
                'source_system' => 'BPLUS', 'branch_code' => '001', 'doc_type' => 'SO', 'dt_properties' => 207,
                'doc_category' => 'reservation', 'invoice_no' => 'SO-L1', 'erp_customer_code' => 'AR-LEAD1',
                'sold_at' => '2026-11-11', 'before_vat_amount' => 5000, 'vat_amount' => 350, 'gross_amount' => 5350,
                'net_amount' => 5000, 'status' => 'reserved', 'created_at' => now(), 'updated_at' => now(),
            ],
        ]);
        DB::table('erp_sales_items')->insert([
            'source_system' => 'BPLUS', 'branch_code' => '001', 'doc_type' => 'IV', 'invoice_no' => 'IV-L1',
            'line_no' => 1, 'erp_product_code' => 'PK-01', 'product_name' => 'Packaging', 'iccat_code' => 'PK',
            'is_excluded_kpi' => true, 'qty' => 1, 'unit_price' => 150, 'before_vat_amount' => 150,
            'vat_amount' => 10.50, 'gross_amount' => 160.50, 'net_amount' => 150,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->actingAs($rep, 'staff');
        $leadId = $this->postJson('/api/crm/leads', [
            'shop_name' => 'ร้านใหม่ทดสอบ',
            'phone' => '0822222222',
            'district' => 'บางรัก',
        ])->assertStatus(201)->json('id');
        $pipelineStageBeforeErpLink = DB::table('crm_leads')->where('id', $leadId)->value('pipeline_stage');

        $this->actingAs($mgr, 'staff');
        $this->postJson("/api/crm/leads/{$leadId}/review", [
            'approval_status' => 'approved',
            'converted_erp_customer_id' => $custId,
        ])->assertStatus(422);
        $this->postJson("/api/crm/customer-master/leads/{$leadId}/link-erp", [
            'erp_customer_id' => $custId,
            'note' => 'Verified B3 sales test conversion',
        ])->assertOk();

        $leads = $this->getJson('/api/crm/leads?q=ร้านใหม่')->assertOk()->json();
        $this->assertCount(1, $leads);
        $this->assertSame($pipelineStageBeforeErpLink, $leads[0]['pipeline_stage']);
        $this->assertEquals(850.0, (float) $leads[0]['erp_total_sales_net']);

        $detail = $this->getJson("/api/crm/leads/{$leadId}")->assertOk()->json();
        $this->assertEquals(850.0, $detail['sales_summary']['primary_sales_before_vat']);
        $this->assertEquals(5000.0, $detail['sales_summary']['reservation_before_vat']);
    }
}
