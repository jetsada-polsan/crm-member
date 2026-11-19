<?php

namespace Tests\Feature;

use App\Models\StaffUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class CustomerPurchaseHistoryTest extends TestCase
{
    use RefreshDatabase;

    private int $nextInvoiceNo = 1;

    public function test_purchase_history_requires_staff_login(): void
    {
        $customerId = $this->createCustomer('AR-OWN', 'SL01');

        $this->getJson("/api/crm/customers/{$customerId}/invoices")->assertUnauthorized();
        $this->getJson("/api/crm/customers/{$customerId}/purchase-insights")->assertUnauthorized();
    }

    public function test_sales_rep_cannot_read_another_reps_customer_bills(): void
    {
        $otherCustomerId = $this->createCustomer('AR-OTHER', 'SL99');
        $invoiceId = $this->createInvoice('AR-OTHER', '2026-10-01 10:00:00', [['FG-1', 'สินค้า A', 2]]);

        $this->actingAs($this->createStaff('sales', 'SL01'), 'staff');

        $this->getJson("/api/crm/customers/{$otherCustomerId}/invoices")->assertNotFound();
        $this->getJson("/api/crm/customers/{$otherCustomerId}/invoices/{$invoiceId}")->assertNotFound();
        $this->getJson("/api/crm/customers/{$otherCustomerId}/purchase-insights")->assertNotFound();
    }

    public function test_invoice_detail_rejects_a_bill_that_belongs_to_another_customer(): void
    {
        $ownCustomerId = $this->createCustomer('AR-OWN', 'SL01');
        $this->createCustomer('AR-OTHER', 'SL99');
        $foreignInvoiceId = $this->createInvoice('AR-OTHER', '2026-10-01 10:00:00', [['FG-1', 'สินค้า A', 2]]);

        $this->actingAs($this->createStaff('sales', 'SL01'), 'staff');

        $this->getJson("/api/crm/customers/{$ownCustomerId}/invoices/{$foreignInvoiceId}")->assertNotFound();
    }

    public function test_shared_reservation_type_does_not_grant_access_to_another_salespersons_customer(): void
    {
        $own = $this->createCustomer('AR-OWN', 'SL01');
        $other = $this->createCustomer('AR-OTHER', 'SL02');
        $ownReservation = $this->createInvoice('AR-OWN', '2026-11-01 10:00:00', [], category: 'reservation', status: 'reserved', dtProperties: 207);
        $otherReservation = $this->createInvoice('AR-OTHER', '2026-11-01 10:00:00', [], category: 'reservation', status: 'reserved', dtProperties: 207);
        DB::table('erp_sales_invoices')->where('id', $ownReservation)->update(['salesperson_code' => 'SL01']);
        DB::table('erp_sales_invoices')->where('id', $otherReservation)->update(['salesperson_code' => 'SL02']);
        $staff = $this->createStaff('sales', 'SL01');
        $staff->update(['reservation_doc_types' => '["IV"]']);
        $this->actingAs($staff, 'staff');

        $this->getJson("/api/crm/customers/{$own}/invoices")->assertOk();
        $this->getJson("/api/crm/customers/{$other}/invoices")->assertNotFound();
    }

    public function test_bill_list_shows_actual_sales_newest_first_with_line_counts_and_paging(): void
    {
        $customerId = $this->createCustomer('AR-OWN', 'SL01');
        $this->createInvoice('AR-OWN', '2026-09-01 10:00:00', [['FG-1', 'สินค้า A', 1]]);
        $newest = $this->createInvoice('AR-OWN', '2026-10-20 10:00:00', [['FG-1', 'สินค้า A', 1], ['FG-2', 'สินค้า B', 3]]);
        $this->createInvoice('AR-OWN', '2026-10-21 10:00:00', [['FG-1', 'สินค้า A', 9]], category: 'reservation', status: 'reserved', dtProperties: 207);
        $this->createInvoice('AR-OWN', '2026-10-22 10:00:00', [['FG-1', 'สินค้า A', 9]], status: 'cancelled');

        $this->actingAs($this->createStaff('sales', 'SL01'), 'staff');

        $response = $this->getJson("/api/crm/customers/{$customerId}/invoices?per_page=5")->assertOk();

        $response->assertJsonPath('meta.total', 2);
        $response->assertJsonPath('data.0.id', $newest);
        $response->assertJsonPath('data.0.item_count', 2);

        $this->getJson("/api/crm/customers/{$customerId}/invoices?per_page=5&page=2")
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_bill_list_month_filter_counts_back_from_the_latest_synced_bill(): void
    {
        $customerId = $this->createCustomer('AR-OWN', 'SL01');
        // Latest synced bill is 20 Jul; "3 months" counts back from it, not from today (16 Nov).
        $this->createInvoice('AR-OWN', '2026-07-20 10:00:00', [['FG-1', 'สินค้า A', 1]]);
        $this->createInvoice('AR-OWN', '2026-02-01 10:00:00', [['FG-1', 'สินค้า A', 1]]);

        $this->travelTo('2026-11-16 09:00:00');
        $this->actingAs($this->createStaff('admin'), 'staff');

        $this->getJson("/api/crm/customers/{$customerId}/invoices?months=3")
            ->assertOk()
            ->assertJsonPath('meta.total', 1);
    }

    public function test_bill_list_rejects_an_unsupported_month_range(): void
    {
        $customerId = $this->createCustomer('AR-OWN', 'SL01');
        $this->actingAs($this->createStaff('admin'), 'staff');

        $this->getJson("/api/crm/customers/{$customerId}/invoices?months=5")
            ->assertUnprocessable()
            ->assertJsonValidationErrors('months');
    }

    public function test_bill_list_filters_inclusive_dates_and_totals_only_that_period(): void
    {
        $customerId = $this->createCustomer('AR-OWN', 'SL01');
        $this->createInvoice('AR-OWN', '2026-10-31 23:59:59', [['FG-1', 'สินค้า A', 1]]);
        $this->createInvoice('AR-OWN', '2026-11-01 00:00:00', [['FG-1', 'สินค้า A', 1]]);
        $this->createInvoice('AR-OWN', '2026-11-16 23:59:59', [['FG-1', 'สินค้า A', 1], ['FG-2', 'สินค้า B', 2]]);
        $this->createInvoice('AR-OWN', '2026-11-17 00:00:00', [['FG-1', 'สินค้า A', 1]]);
        $this->actingAs($this->createStaff('sales', 'SL01'), 'staff');

        $this->getJson("/api/crm/customers/{$customerId}/invoices?from=2026-11-01&to=2026-11-16")
            ->assertOk()->assertJsonPath('meta.total', 2)->assertJsonPath('summary.sale_count', 2)
            ->assertJsonPath('summary.net_before_vat', 300);
        $this->getJson("/api/crm/customers/{$customerId}/invoices?from=2026-11-17&to=2026-11-01")
            ->assertUnprocessable()->assertJsonValidationErrors('to');
        $this->getJson("/api/crm/customers/{$customerId}/invoices?from=2026-11-01")
            ->assertUnprocessable()->assertJsonValidationErrors('to');
        $this->getJson('/api/crm/members')->assertForbidden();
        $this->getJson('/api/crm/member-links')->assertForbidden();
    }

    public function test_invoice_detail_returns_every_line_in_order(): void
    {
        $customerId = $this->createCustomer('AR-OWN', 'SL01');
        $lines = [];
        for ($i = 1; $i <= 250; $i++) {
            $lines[] = ["FG-{$i}", "สินค้า {$i}", 1];
        }
        $invoiceId = $this->createInvoice('AR-OWN', '2026-10-01 10:00:00', $lines);

        $this->actingAs($this->createStaff('sales', 'SL01'), 'staff');

        $this->getJson("/api/crm/customers/{$customerId}/invoices/{$invoiceId}")
            ->assertOk()
            ->assertJsonCount(250, 'items')
            ->assertJsonPath('items.0.line_no', 1)
            ->assertJsonPath('items.249.product_name', 'สินค้า 250');
    }

    public function test_insights_rank_regular_products_and_flag_ones_that_stopped(): void
    {
        $customerId = $this->createCustomer('AR-OWN', 'SL01');
        // Bought every month until July, then stopped => lapsed regular product.
        foreach (['2026-05-05', '2026-06-05', '2026-07-05'] as $date) {
            $this->createInvoice('AR-OWN', "{$date} 10:00:00", [['FG-OLD', 'ซอสเก่า', 2], ['SRV-PK', 'ค่าแพ็ค', 1, true]]);
        }
        // Bought recently and often => top product, not lapsed.
        foreach (['2026-08-10', '2026-09-10', '2026-10-10', '2026-10-25'] as $date) {
            $this->createInvoice('AR-OWN', "{$date} 10:00:00", [['FG-NEW', 'ซอสใหม่', 5]]);
        }

        $this->travelTo('2026-11-16 09:00:00');
        $this->actingAs($this->createStaff('sales', 'SL01'), 'staff');

        $response = $this->getJson("/api/crm/customers/{$customerId}/purchase-insights")->assertOk();

        $response->assertJsonPath('days_since_last_purchase', 22);
        $response->assertJsonPath('top_products.0.erp_product_code', 'FG-NEW');
        $response->assertJsonPath('top_products.0.bill_count', 4);
        $response->assertJsonPath('lapsed_products.0.erp_product_code', 'FG-OLD');
        $response->assertJsonCount(1, 'lapsed_products');
        $this->assertNotContains('SRV-PK', array_column($response->json('top_products'), 'erp_product_code'));
        // Windows count back from the latest synced bill (25 Oct): Aug–Oct vs May–Jul.
        $response->assertJsonPath('recent_90_days.bills', 4);
        $response->assertJsonPath('previous_90_days.bills', 3);
    }

    private function createStaff(string $role, ?string $salespersonCode = null): StaffUser
    {
        $id = DB::table('staff_users')->insertGetId([
            'name' => "Staff {$role}",
            'email' => "{$role}".uniqid().'@popstar.local',
            'password' => Hash::make('Secret123!'),
            'role' => $role,
            'salesperson_code' => $salespersonCode,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return StaffUser::query()->findOrFail($id);
    }

    private function createCustomer(string $code, string $salespersonCode): int
    {
        return DB::table('erp_customers')->insertGetId([
            'source_system' => 'BPLUS',
            'erp_customer_code' => $code,
            'customer_name' => "Shop {$code}",
            'salesperson_code' => $salespersonCode,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * @param  array<int, array{0: string, 1: string, 2: int|float, 3?: bool}>  $lines  [product code, name, qty, excluded from KPI]
     */
    private function createInvoice(string $customerCode, string $soldAt, array $lines, string $category = 'sale', string $status = 'posted', int $dtProperties = 307): int
    {
        $invoiceNo = 'INV-'.$this->nextInvoiceNo++;
        $id = DB::table('erp_sales_invoices')->insertGetId([
            'source_system' => 'BPLUS',
            'branch_code' => '001',
            'doc_type' => 'IV',
            'invoice_no' => $invoiceNo,
            'erp_customer_code' => $customerCode,
            'sold_at' => $soldAt,
            'doc_category' => $category,
            'status' => $status,
            'dt_properties' => $dtProperties,
            'before_vat_amount' => 100 * count($lines),
            'net_amount' => 100 * count($lines),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        foreach ($lines as $index => $line) {
            DB::table('erp_sales_items')->insert([
                'source_system' => 'BPLUS',
                'branch_code' => '001',
                'doc_type' => 'IV',
                'invoice_no' => $invoiceNo,
                'line_no' => $index + 1,
                'erp_product_code' => $line[0],
                'product_name' => $line[1],
                'qty' => $line[2],
                'unit_name' => 'ขวด',
                'net_amount' => 100,
                'before_vat_amount' => 100,
                'is_excluded_kpi' => $line[3] ?? false,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        return $id;
    }
}
