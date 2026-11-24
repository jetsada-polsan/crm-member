<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('erp_product_prices', function (Blueprint $table): void {
            if (! Schema::hasColumn('erp_product_prices', 'unit_qty')) {
                $table->decimal('unit_qty', 16, 4)->nullable()->after('unit');
            }
            if (! Schema::hasColumn('erp_product_prices', 'category_name')) {
                $table->string('category_name', 120)->nullable()->after('product_name');
            }
        });
    }

    public function down(): void
    {
        Schema::table('erp_product_prices', function (Blueprint $table): void {
            $cols = [];
            if (Schema::hasColumn('erp_product_prices', 'unit_qty')) {
                $cols[] = 'unit_qty';
            }
            if (Schema::hasColumn('erp_product_prices', 'category_name')) {
                $cols[] = 'category_name';
            }
            if ($cols !== []) {
                $table->dropColumn($cols);
            }
        });
    }
};
