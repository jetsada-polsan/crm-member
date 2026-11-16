<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('erp_customers', function (Blueprint $table): void {
            $table->string('phone', 128)->nullable()->change();
        });
    }

    public function down(): void
    {
        if (DB::table('erp_customers')->whereRaw('char_length(phone) > 40')->exists()) {
            throw new RuntimeException('Cannot shrink erp_customers.phone while mirrored values exceed 40 characters.');
        }

        Schema::table('erp_customers', function (Blueprint $table): void {
            $table->string('phone', 40)->nullable()->change();
        });
    }
};
