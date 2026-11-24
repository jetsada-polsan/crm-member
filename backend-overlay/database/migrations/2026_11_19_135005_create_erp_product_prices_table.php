<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('erp_product_prices', function (Blueprint $table) {
            $table->id();
            $table->string('source_system', 32);
            $table->string('price_book_code', 32);
            $table->string('erp_price_key', 64);
            $table->string('erp_product_code', 64)->index();
            $table->string('goods_code', 64);
            $table->string('product_name', 255);
            $table->string('unit', 60)->nullable();
            $table->decimal('price', 18, 4);
            $table->unique(['source_system', 'price_book_code', 'erp_price_key']);
        });
        Schema::create('erp_price_snapshots', function (Blueprint $table): void {
            $table->string('source_system', 32);
            $table->string('price_book_code', 32);
            $table->timestampTz('synced_at');
            $table->integer('row_count');
            $table->primary(['source_system', 'price_book_code']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('erp_price_snapshots');
        Schema::dropIfExists('erp_product_prices');
    }
};
