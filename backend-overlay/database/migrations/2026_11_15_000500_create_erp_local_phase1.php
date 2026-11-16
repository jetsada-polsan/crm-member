<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('erp_customers', function (Blueprint $table) {
            $table->string('salesperson_name', 120)->nullable()->after('salesperson_code');
            $table->string('province', 120)->nullable()->after('phone');
            $table->string('address', 500)->nullable()->after('province');
            $table->string('ar_enable', 8)->nullable()->after('address');
        });

        Schema::create('erp_sales_items', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('source_system', 32);
            $table->string('branch_code', 32);
            $table->string('doc_type', 32);
            $table->string('invoice_no', 80);
            $table->unsignedInteger('line_no');
            $table->string('erp_product_code', 64);
            $table->string('product_name', 255);
            $table->decimal('qty', 14, 3)->default(0);
            $table->string('unit_name', 60)->nullable();
            $table->decimal('unit_price', 14, 2)->default(0);
            $table->decimal('net_amount', 16, 2)->default(0);
            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('updated_at')->useCurrent();

            $table->unique(
                ['source_system', 'branch_code', 'doc_type', 'invoice_no', 'line_no'],
                'erp_sales_item_business_unique'
            );
            $table->index(['source_system', 'erp_product_code']);
        });

        Schema::create('sync_runs', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('kind', 40);
            $table->string('status', 20); // running, success, failed
            $table->unsignedInteger('fetched_count')->default(0);
            $table->unsignedInteger('inserted_count')->default(0);
            $table->unsignedInteger('updated_count')->default(0);
            $table->unsignedBigInteger('triggered_by')->nullable();
            $table->text('message')->nullable();
            $table->timestampTz('started_at')->useCurrent();
            $table->timestampTz('finished_at')->nullable();
            $table->timestampsTz();
            $table->index(['kind', 'started_at']);
        });

        Schema::create('sync_errors', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->foreignId('sync_run_id')->nullable()->constrained('sync_runs')->nullOnDelete();
            $table->string('kind', 40);
            $table->string('record_key', 160)->nullable();
            $table->text('error_message');
            $table->timestampTz('created_at')->useCurrent();
            $table->index(['kind', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sync_errors');
        Schema::dropIfExists('sync_runs');
        Schema::dropIfExists('erp_sales_items');
        Schema::table('erp_customers', function (Blueprint $table) {
            $table->dropColumn(['salesperson_name', 'province', 'address', 'ar_enable']);
        });
    }
};
