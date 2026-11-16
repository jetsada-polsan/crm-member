<?php

namespace Tests\Feature;

use App\Models\StaffUser;
use App\Modules\ERPIntegration\SalesRules;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

class SalesCalculationRulesTest extends TestCase
{
    use RefreshDatabase;

    public function test_false_string_from_bridge_does_not_classify_products_and_lines_as_services(): void
    {
        $now = now();
        \App\Modules\ERPIntegration\ErpMirrorUpserter::upsertProduct('BPLUS', [
            'erp_product_code' => 'FOOD-1', 'product_name' => 'Food',
            'category_code' => 'FG', 'is_excluded_kpi' => 'False',
        ], $now);
        \App\Modules\ERPIntegration\ErpMirrorUpserter::upsertInvoiceWithItems('BPLUS', [
            'branch_code' => '001', 'doc_type' => 'IV', 'invoice_no' => 'TEST-1',
            'erp_customer_code' => 'AR1', 'sold_at' => '2026-01-01 12:00:00',
            'dt_properties' => 307, 'status' => 'posted', 'before_vat_amount' => 100,
            'items' => [[
                'line_no' => 1, 'erp_product_code' => 'FOOD-1', 'product_name' => 'Food',
                'qty' => 1, 'before_vat_amount' => 100, 'is_excluded_kpi' => 'False',
            ]],
        ], $now);
        $this->assertDatabaseHas('erp_products', ['erp_product_code' => 'FOOD-1', 'is_excluded_kpi' => false]);
        $this->assertDatabaseHas('erp_sales_items', ['invoice_no' => 'TEST-1', 'is_excluded_kpi' => false]);
    }

    public function test_unknown_document_properties_fail_closed_in_sales_and_points(): void
    {
        $missing = SalesRules::classifyDocument(null, 'IV', 'posted', 'sale');
        $unknown = SalesRules::classifyDocument(999, 'IV', 'posted', 'sale');
        $unknownStatus = SalesRules::classifyDocument(307, 'IV', 2, 'sale');
        $this->assertSame(['dt_properties' => null, 'doc_category' => 'other', 'status' => 'other'], $missing);
        $this->assertSame(['dt_properties' => 999, 'doc_category' => 'other', 'status' => 'other'], $unknown);
        $this->assertSame('other', $unknownStatus['status']);

        $activeRule = (object) [
            'is_active' => true, 'exclude_cancelled' => true, 'exclude_reservation' => true,
            'reverse_on_return' => true, 'baht_per_point' => 100, 'points_per_unit' => 1, 'min_invoice_amount' => 0,
        ];
        $this->assertSame(0, \App\Modules\Loyalty\LoyaltyController::calculatePointsForAmount(1000, $activeRule, 'other', 'other'));
        $this->assertSame(0, \App\Modules\Loyalty\LoyaltyController::calculatePointsForAmount(1000, $activeRule, 'sale', 'other'));
    }

    private function createAdmin(): StaffUser
    {
        $id = DB::table('staff_users')->insertGetId([
            'name' => 'Audit Admin',
            'email' => 'admin@popstar.local',
            'password' => Hash::make('Secret123!'),
            'role' => 'admin',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return StaffUser::query()->findOrFail($id);
    }

    public function test_bplus_b3_sales_rules_exclude_reservations_cancelled_and_service_categories_while_using_before_vat_and_credit_notes(): void
    {
        config()->set('popstar.sync_api_token', str_repeat('t', 40));
        $headers = ['Authorization' => 'Bearer '.str_repeat('t', 40)];

        // 1) Customers & products batch sync
        $this->postJson('/api/sync/v1/customers', [
            'source' => 'BPLUS',
            'batch_id' => (string) Str::uuid(),
            'records' => [[
                'erp_customer_code' => 'AR001',
                'customer_name' => 'ร้านเจริญพานิชย์',
                'salesperson_code' => 'SL01',
                'salesperson_name' => 'สมชาย',
                'province' => 'กรุงเทพมหานคร',
                'route_code' => 'R01',
                'route_name' => 'สายกลาง',
                'credit_limit' => 150000,
                'credit_term_days' => 30,
                'tax_id' => '0105550000011',
            ]],
        ], $headers)->assertOk();

        $this->postJson('/api/sync/v1/products', [
            'source' => 'BPLUS',
            'batch_id' => (string) Str::uuid(),
            'records' => [
                ['erp_product_code' => 'FG-100', 'product_name' => 'สินค้าหลัก A', 'unit' => 'ลัง', 'category_code' => 'FG', 'category_name' => 'สินค้าสำเร็จรูป'],
                ['erp_product_code' => 'SRV-PK', 'product_name' => 'ค่าแพ็คเกจ', 'unit' => 'งาน', 'category_code' => 'PK', 'category_name' => 'Packaging'],
                ['erp_product_code' => 'SRV-CC', 'product_name' => 'ค่าบริการ CC', 'unit' => 'งาน', 'category_code' => 'CC', 'category_name' => 'Service CC'],
            ],
        ], $headers)->assertOk();

        // 2) Invoices covering all B3 cases:
        //    - INV-307 (actual credit sale): Product 1,000 + PK 200 before VAT => before_vat 1200, vat 84, gross 1284
        //    - INV-302 (actual cash sale): Product 500 + CC 100 before VAT => before_vat 600, vat 42, gross 642
        //    - CN-308  (credit note return, positive input from Bplus normalized negative): Product 200 before VAT, vat 14, gross 214
        //    - SO-207  (reservation): 9000 before VAT (MUST NEVER count into actual sales!)
        //    - INV-CXL (cancelled sale): 5000 before VAT (MUST NEVER count into actual sales!)
        $invoiceBatchId = (string) Str::uuid();
        $payload = [
            'source' => 'BPLUS',
            'batch_id' => $invoiceBatchId,
            'records' => [
                [
                    'branch_code' => '001',
                    'doc_type' => 'IV',
                    'dt_properties' => 307,
                    'invoice_no' => 'INV-307',
                    'erp_customer_code' => 'AR001',
                    'salesperson_code' => 'SL01',
                    'sold_at' => '2026-11-05 10:00:00',
                    'before_vat_amount' => 1200.00,
                    'vat_amount' => 84.00,
                    'gross_amount' => 1284.00,
                    'status' => 'posted',
                    'items' => [
                        ['line_no' => 1, 'erp_product_code' => 'FG-100', 'product_name' => 'สินค้าหลัก A', 'qty' => 10, 'before_vat_amount' => 1000, 'vat_amount' => 70, 'gross_amount' => 1070],
                        ['line_no' => 2, 'erp_product_code' => 'SRV-PK', 'product_name' => 'ค่าแพ็คเกจ', 'iccat_code' => 'PK', 'qty' => 1, 'before_vat_amount' => 200, 'vat_amount' => 14, 'gross_amount' => 214],
                    ],
                ],
                [
                    'branch_code' => '001',
                    'doc_type' => 'CA',
                    'dt_properties' => 302,
                    'invoice_no' => 'INV-302',
                    'erp_customer_code' => 'AR001',
                    'salesperson_code' => 'SL01',
                    'sold_at' => '2026-11-06 11:00:00',
                    'before_vat_amount' => 600.00,
                    'vat_amount' => 42.00,
                    'gross_amount' => 642.00,
                    'status' => 'posted',
                    'items' => [
                        ['line_no' => 1, 'erp_product_code' => 'FG-100', 'product_name' => 'สินค้าหลัก A', 'qty' => 5, 'before_vat_amount' => 500, 'vat_amount' => 35, 'gross_amount' => 535],
                        ['line_no' => 2, 'erp_product_code' => 'SRV-CC', 'product_name' => 'ค่าบริการ CC', 'qty' => 1, 'before_vat_amount' => 100, 'vat_amount' => 7, 'gross_amount' => 107],
                    ],
                ],
                [
                    'branch_code' => '001',
                    'doc_type' => 'CN',
                    'dt_properties' => 308,
                    'invoice_no' => 'CN-308',
                    'erp_customer_code' => 'AR001',
                    'salesperson_code' => 'SL01',
                    'sold_at' => '2026-11-07 14:00:00',
                    'before_vat_amount' => 200.00,
                    'vat_amount' => 14.00,
                    'gross_amount' => 214.00,
                    'status' => 'returned',
                    'items' => [
                        ['line_no' => 1, 'erp_product_code' => 'FG-100', 'product_name' => 'สินค้าหลัก A', 'qty' => 2, 'before_vat_amount' => 200, 'vat_amount' => 14, 'gross_amount' => 214, 'refer_doc_ref' => 'INV-307'],
                    ],
                ],
                [
                    'branch_code' => '001',
                    'doc_type' => 'SO',
                    'dt_properties' => 207,
                    'invoice_no' => 'SO-207',
                    'erp_customer_code' => 'AR001',
                    'salesperson_code' => 'SL01',
                    'sold_at' => '2026-11-08 09:00:00',
                    'before_vat_amount' => 9000.00,
                    'vat_amount' => 630.00,
                    'gross_amount' => 9630.00,
                    'status' => 'reserved',
                ],
                [
                    'branch_code' => '001',
                    'doc_type' => 'IV',
                    'dt_properties' => 307,
                    'invoice_no' => 'INV-CXL',
                    'erp_customer_code' => 'AR001',
                    'salesperson_code' => 'SL01',
                    'sold_at' => '2026-11-09 16:00:00',
                    'before_vat_amount' => 5000.00,
                    'vat_amount' => 350.00,
                    'gross_amount' => 5350.00,
                    'status' => 'cancelled',
                ],
            ],
        ];

        $this->postJson('/api/sync/v1/invoices', $payload, $headers)
            ->assertOk()
            ->assertJson(['already_processed' => false, 'received' => 5]);

        // Idempotent replay with same batch_id returns already_processed=true
        $this->postJson('/api/sync/v1/invoices', $payload, $headers)
            ->assertOk()
            ->assertJson(['already_processed' => true]);

        // Reusing same batch_id for modified payload rejects with 409
        $mutated = $payload;
        $mutated['records'][0]['before_vat_amount'] = 999.00;
        $this->postJson('/api/sync/v1/invoices', $mutated, $headers)
            ->assertStatus(409);

        // Verify direct database classification & signed 308 reversal
        $this->assertDatabaseHas('erp_sales_invoices', [
            'invoice_no' => 'SO-207',
            'doc_category' => 'reservation',
            'status' => 'reserved',
        ]);
        $this->assertDatabaseHas('erp_sales_invoices', [
            'invoice_no' => 'CN-308',
            'doc_category' => 'return',
            'status' => 'returned',
            'before_vat_amount' => '-200.00',
            'vat_amount' => '-14.00',
            'gross_amount' => '-214.00',
        ]);

        // Math check across all rules:
        // Actual invoices = INV-307 (1200) + INV-302 (600) + CN-308 (-200) = 1600 Before VAT
        // VAT = 84 + 42 - 14 = 112
        // Gross = 1284 + 642 - 214 = 1712
        // Excluded services = SRV-PK (200) + SRV-CC (100) = 300
        // Primary Product Sales KPI (Before VAT, excl CC/CO/AS/PK) = 1600 - 300 = 1300
        // Reservations = 9000 (count 1)
        // Cancelled = 1 (INV-CXL excluded completely)
        $admin = $this->createAdmin();
        $this->actingAs($admin, 'staff');

        $dash = $this->getJson('/api/crm/dashboard')->assertOk()->json();
        $this->assertSame('1600', (string) $dash['posted_sales_net']);
        $this->assertSame(2, $dash['posted_invoices']);
        $this->assertEquals(1300.0, $dash['sales_kpis']['primary_sales_before_vat']);
        $this->assertEquals(1600.0, $dash['sales_kpis']['actual_all_before_vat']);
        $this->assertEquals(300.0, $dash['sales_kpis']['excluded_service_before_vat']);
        $this->assertEquals(112.0, $dash['sales_kpis']['vat_total']);
        $this->assertEquals(1712.0, $dash['sales_kpis']['gross_total']);
        $this->assertEquals(-200.0, $dash['sales_kpis']['return_before_vat']);
        $this->assertEquals(1800.0, $dash['sales_kpis']['gross_purchase_before_vat']);
        $this->assertEquals(200.0, $dash['sales_kpis']['return_amount_before_vat']);
        $this->assertEquals(1600.0, $dash['sales_kpis']['net_purchase_before_vat']);
        $this->assertEquals(1600.0, $dash['sales_kpis']['net_sales_before_vat']);
        $this->assertEquals(112.0, $dash['sales_kpis']['vat_amount']);
        $this->assertEquals(1712.0, $dash['sales_kpis']['gross_amount']);
        $this->assertSame(2, $dash['sales_kpis']['sale_invoice_count']);
        $this->assertSame(2, $dash['sales_kpis']['posted_sales_count']);
        $this->assertEquals(200.0, $dash['sales_kpis']['return_308_before_vat']);
        $this->assertStringStartsWith('2026-11-06', (string) $dash['sales_kpis']['last_purchase_at']);
        $this->assertEquals(9000.0, $dash['sales_kpis']['reservation_before_vat']);
        $this->assertSame(1, $dash['sales_kpis']['cancelled_count']);
        $this->assertCount(1, $dash['monthly_sales_chart']);
        $this->assertEquals(1300.0, $dash['monthly_sales_chart'][0]['primary_sales_before_vat']);
        $this->assertEquals(1600.0, $dash['monthly_sales_chart'][0]['net_sales_before_vat']);
        $this->assertSame(2, $dash['monthly_sales_chart'][0]['sale_invoice_count']);
        $this->assertSame(1, $dash['monthly_sales_chart'][0]['return_count']);

        // Customer purchase totals include all signed actual documents; product KPI remains separate.
        $custList = $this->getJson('/api/crm/customers?q=AR001')->assertOk()->json();
        $this->assertCount(1, $custList);
        $this->assertEquals(1600.0, (float) $custList[0]['total_sales_net']);
        $this->assertEquals(1300.0, (float) $custList[0]['product_sales_kpi_before_vat']);
        $this->assertEquals(1800.0, (float) $custList[0]['gross_purchase_before_vat']);
        $this->assertEquals(200.0, (float) $custList[0]['return_amount_before_vat']);
        $this->assertSame(2, (int) $custList[0]['sale_invoice_count']);
        $this->assertSame(1, (int) $custList[0]['return_count']);
        $this->assertEquals(1712.0, (float) $custList[0]['total_sales_gross']);
        $this->assertEquals('R01', $custList[0]['route_code']);

        // Customer detail
        $custId = $custList[0]['id'];
        $detail = $this->getJson("/api/crm/customers/{$custId}")->assertOk()->json();
        $this->assertEquals(1600.0, (float) $detail['total_posted_net']);
        $this->assertCount(3, $detail['invoices']);
        $this->assertNotContains('reservation', array_column($detail['invoices'], 'doc_category'));
        $this->assertNotContains('cancelled', array_column($detail['invoices'], 'status'));
        $this->assertEquals(9000.0, $detail['sales_summary']['reservation_before_vat']);

        $productId = DB::table('erp_products')->where('source_system', 'BPLUS')->where('erp_product_code', 'FG-100')->value('id');
        DB::table('erp_product_size_segments')->insert([
            'erp_product_id' => $productId, 'size_code' => 'SIZE-300', 'size_label' => 'ขนาด 300 กรัม',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $matrix = $this->getJson('/api/crm/reports/customer-sales-matrix?from=2026-11-05&to=2026-11-30&route_code=R01&size_code=SIZE-300')->assertOk()->json();
        $this->assertCount(1, $matrix['data']);
        $this->assertSame('AR001', $matrix['data'][0]['erp_customer_code']);
        $this->assertSame('R01', $matrix['data'][0]['route_code']);
        $this->assertEquals(1500.0, (float) $matrix['data'][0]['gross_purchase_before_vat']);
        $this->assertEquals(200.0, (float) $matrix['data'][0]['return_amount_before_vat']);
        $this->assertEquals(1300.0, (float) $matrix['data'][0]['net_purchase_before_vat']);
        $this->assertEquals(500.0, (float) $matrix['data'][0]['day_20261106']);
        $this->assertSame('pending_live_bplus_comparison', $matrix['reconciliation_status']);
        $this->assertEquals(-200.0, (float) $matrix['data'][0]['day_20261107']);

        $monthly = $this->getJson('/api/crm/reports/customer-sales-matrix?from=2026-01-01&to=2026-11-30&route_code=R01&period=month')->assertOk()->json();
        $this->assertSame('month', $monthly['period']);
        $this->assertEquals(1600.0, (float) $monthly['data'][0]['day_202611']);
        $this->getJson('/api/crm/reports/customer-sales-matrix?from=2025-12-01&to=2025-12-31')->assertStatus(422);

        // Reconciliation report
        $recon = $this->getJson('/api/crm/reports/reconciliation?from=2026-11-01&to=2026-11-30')->assertOk()->json();
        $this->assertEquals(1300.0, $recon['summary']['primary_sales_before_vat']);
        $this->assertCount(2, $recon['excluded_categories']); // CC and PK
    }

    public function test_sales_rules_category_classifier_covers_all_excluded_codes(): void
    {
        $this->assertTrue(SalesRules::isExcludedCategory('CC'));
        $this->assertTrue(SalesRules::isExcludedCategory(' co '));
        $this->assertTrue(SalesRules::isExcludedCategory('AS'));
        $this->assertTrue(SalesRules::isExcludedCategory('pk'));
        $this->assertFalse(SalesRules::isExcludedCategory('FG'));
        $this->assertFalse(SalesRules::isExcludedCategory(null));
    }

    public function test_complete_invoice_snapshot_removes_lines_missing_from_erp(): void
    {
        $token = str_repeat('s', 40);
        config()->set('popstar.sync_api_token', $token);
        $headers = ['Authorization' => 'Bearer '.$token];
        $invoice = [
            'branch_code' => '001',
            'doc_type' => 'IV',
            'dt_properties' => 307,
            'invoice_no' => 'INV-LINES',
            'erp_customer_code' => 'AR-LINES',
            'sold_at' => '2026-11-12 10:00:00',
            'before_vat_amount' => 100,
            'status' => 'posted',
            'items_complete' => true,
            'items' => [
                ['line_no' => 1, 'erp_product_code' => 'A', 'product_name' => 'Product A', 'qty' => 1],
                ['line_no' => 2, 'erp_product_code' => 'B', 'product_name' => 'Product B', 'qty' => 1],
            ],
        ];

        $this->postJson('/api/sync/v1/invoices', [
            'source' => 'BPLUS',
            'batch_id' => (string) Str::uuid(),
            'records' => [$invoice],
        ], $headers)->assertOk();

        $invoice['items'] = [$invoice['items'][0]];
        unset($invoice['items_complete']);
        $this->postJson('/api/sync/v1/invoices', [
            'source' => 'BPLUS',
            'batch_id' => (string) Str::uuid(),
            'records' => [$invoice],
        ], $headers)->assertOk();
        $this->assertDatabaseHas('erp_sales_items', ['invoice_no' => 'INV-LINES', 'line_no' => 2]);

        $invoice['items_complete'] = true;
        $this->postJson('/api/sync/v1/invoices', [
            'source' => 'BPLUS',
            'batch_id' => (string) Str::uuid(),
            'records' => [$invoice],
        ], $headers)->assertOk();

        $this->assertDatabaseHas('erp_sales_items', ['invoice_no' => 'INV-LINES', 'line_no' => 1]);
        $this->assertDatabaseMissing('erp_sales_items', ['invoice_no' => 'INV-LINES', 'line_no' => 2]);

        unset($invoice['items']);
        $this->postJson('/api/sync/v1/invoices', [
            'source' => 'BPLUS',
            'batch_id' => (string) Str::uuid(),
            'records' => [$invoice],
        ], $headers)->assertUnprocessable();
    }
}
