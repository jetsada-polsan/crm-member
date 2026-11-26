<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('member_accounts', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->string('name', 120);
            $t->string('email')->unique();
            $t->string('password');
            $t->string('phone', 40)->nullable();
            $t->string('status', 32)->default('active');
            $t->rememberToken();
            $t->timestampsTz();
        });
        Schema::create('member_erp_links', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->foreignId('member_account_id')->constrained('member_accounts')->restrictOnDelete();
            $t->foreignId('erp_customer_id')->unique()->constrained('erp_customers')->restrictOnDelete();
            $t->timestampTz('verified_at')->nullable();
            $t->timestampsTz();
            $t->index('member_account_id');
        });
        Schema::create('loyalty_points_ledger', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->foreignId('member_account_id')->constrained('member_accounts')->restrictOnDelete();
            $t->string('event_key', 160)->unique();
            $t->integer('points_delta');
            $t->string('reason', 120);
            $t->text('note')->nullable();
            $t->timestampTz('created_at')->useCurrent();
            $t->index(['member_account_id', 'created_at']);
        });
        Schema::create('member_line_accounts', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->foreignId('member_account_id')->constrained('member_accounts')->restrictOnDelete();
            $t->string('line_user_id', 128)->unique();
            $t->timestampTz('verified_at');
            $t->timestampsTz();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('member_line_accounts');
        Schema::dropIfExists('loyalty_points_ledger');
        Schema::dropIfExists('member_erp_links');
        Schema::dropIfExists('member_accounts');
    }
};
