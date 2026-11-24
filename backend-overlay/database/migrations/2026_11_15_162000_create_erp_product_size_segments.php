<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('erp_product_size_segments', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->foreignId('erp_product_id')->unique()->constrained('erp_products')->cascadeOnDelete();
            $table->string('size_code', 40)->index();
            $table->string('size_label', 80);
            $table->foreignId('created_by')->nullable()->constrained('staff_users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('staff_users')->nullOnDelete();
            $table->timestampsTz();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('erp_product_size_segments');
    }
};
