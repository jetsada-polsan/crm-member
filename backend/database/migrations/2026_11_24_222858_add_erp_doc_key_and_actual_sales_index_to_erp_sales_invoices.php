<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Keep the Bplus DOCINFO.DI_KEY on each mirrored document so recently edited bills can be
 * re-read from the bridge, and add a covering partial index for actual-sales date ranges.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('erp_sales_invoices', function (Blueprint $table): void {
            $table->unsignedBigInteger('erp_doc_key')->nullable()->after('invoice_no');
            $table->index(['source_system', 'erp_doc_key'], 'erp_inv_doc_key_idx');
        });

        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement("CREATE INDEX IF NOT EXISTS erp_inv_actual_sold_idx ON erp_sales_invoices (source_system, sold_at) INCLUDE (erp_customer_code, salesperson_code, doc_category, before_vat_amount, net_amount, vat_amount, gross_amount) WHERE doc_category IN ('sale', 'return') AND status IN ('posted', 'returned') AND dt_properties IN (302, 307, 308, 337)");
            DB::statement('ANALYZE erp_sales_invoices');
        }
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('DROP INDEX IF EXISTS erp_inv_actual_sold_idx');
        }

        Schema::table('erp_sales_invoices', function (Blueprint $table): void {
            $table->dropIndex('erp_inv_doc_key_idx');
            $table->dropColumn('erp_doc_key');
        });
    }
};
