<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('CREATE INDEX IF NOT EXISTS erp_inv_actual_cust_idx ON erp_sales_invoices (source_system, erp_customer_code, doc_category, status)');
            DB::statement('CREATE INDEX IF NOT EXISTS erp_inv_res_doctype_idx ON erp_sales_invoices (dt_properties, status, doc_type)');
            DB::statement('CREATE INDEX IF NOT EXISTS erp_inv_sp_cat_status_idx ON erp_sales_invoices (salesperson_code, doc_category, status)');
            DB::statement('CREATE INDEX IF NOT EXISTS erp_items_excl_inv_idx ON erp_sales_items (source_system, branch_code, doc_type, invoice_no) WHERE is_excluded_kpi = true');
            DB::statement('ANALYZE erp_sales_invoices');
            DB::statement('ANALYZE erp_sales_items');
            DB::statement('ANALYZE erp_customers');
            DB::statement('ANALYZE erp_product_prices');
            return;
        }

        Schema::table('erp_sales_invoices', function (Blueprint $table): void {
            $table->index(['source_system', 'erp_customer_code', 'doc_category', 'status'], 'erp_inv_actual_cust_idx');
            $table->index(['dt_properties', 'status', 'doc_type'], 'erp_inv_res_doctype_idx');
        });
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('DROP INDEX IF EXISTS erp_inv_actual_cust_idx');
            DB::statement('DROP INDEX IF EXISTS erp_inv_res_doctype_idx');
            DB::statement('DROP INDEX IF EXISTS erp_inv_sp_cat_status_idx');
            DB::statement('DROP INDEX IF EXISTS erp_items_excl_inv_idx');
            return;
        }

        Schema::table('erp_sales_invoices', function (Blueprint $table): void {
            $table->dropIndex('erp_inv_actual_cust_idx');
            $table->dropIndex('erp_inv_res_doctype_idx');
        });
    }
};
