<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('crm_customer_masters', function (Blueprint $table): void {
            $table->string('phone', 160)->nullable()->change();
            $table->string('shop_type', 120)->nullable()->change();
            $table->string('contact_name', 160)->nullable()->change();
            $table->string('province', 160)->nullable()->change();
            $table->string('district', 160)->nullable()->change();
            $table->string('assigned_salesperson_code', 80)->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('crm_customer_masters', function (Blueprint $table): void {
            $table->string('phone', 40)->nullable()->change();
            $table->string('shop_type', 64)->nullable()->change();
            $table->string('contact_name', 120)->nullable()->change();
            $table->string('province', 120)->nullable()->change();
            $table->string('district', 120)->nullable()->change();
            $table->string('assigned_salesperson_code', 64)->nullable()->change();
        });
    }
};
