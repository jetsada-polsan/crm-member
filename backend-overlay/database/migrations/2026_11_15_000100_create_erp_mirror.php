<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('sync_batches',function(Blueprint $t){
            $t->bigIncrements('id'); $t->uuid('batch_id'); $t->string('source_system',32);
            $t->string('kind',32);$t->char('payload_sha256',64);$t->unsignedInteger('record_count');
            $t->timestampsTz(); $t->unique(['source_system','kind','batch_id']);
        });
        Schema::create('erp_customers',function(Blueprint $t){
            $t->bigIncrements('id');$t->string('source_system',32);$t->string('erp_customer_code',64);
            $t->string('customer_name');$t->string('phone',40)->nullable();
            $t->string('salesperson_code',64)->nullable();
            $t->timestampTz('created_at')->useCurrent();$t->timestampTz('updated_at')->useCurrent();
            $t->unique(['source_system','erp_customer_code']);$t->index('salesperson_code');
        });
        Schema::create('erp_products',function(Blueprint $t){
            $t->bigIncrements('id');$t->string('source_system',32);$t->string('erp_product_code',64);
            $t->string('product_name');$t->string('unit',30)->nullable();
            $t->timestampTz('created_at')->useCurrent();$t->timestampTz('updated_at')->useCurrent();
            $t->unique(['source_system','erp_product_code']);
        });
        Schema::create('erp_sales_invoices',function(Blueprint $t){
            $t->bigIncrements('id');$t->string('source_system',32);$t->string('branch_code',32);
            $t->string('doc_type',32);$t->string('invoice_no',80);$t->string('erp_customer_code',64);
            $t->timestampTz('sold_at');$t->decimal('net_amount',16,2);
            $t->string('status',20);$t->timestampTz('created_at')->useCurrent();$t->timestampTz('updated_at')->useCurrent();
            $t->unique(['source_system','branch_code','doc_type','invoice_no'],'erp_invoice_business_unique');
            $t->index(['source_system','erp_customer_code']);$t->index('sold_at');
        });
    }
    public function down():void
    {
        Schema::dropIfExists('erp_sales_invoices');
        Schema::dropIfExists('erp_products');
        Schema::dropIfExists('erp_customers');
        Schema::dropIfExists('sync_batches');
    }
};
