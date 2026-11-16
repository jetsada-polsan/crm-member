<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up():void {
        Schema::create('staff_users',function(Blueprint $t){
            $t->bigIncrements('id');$t->string('name',120);$t->string('email')->unique();
            $t->string('password');$t->string('role',32)->default('sales');
            $t->string('salesperson_code',64)->nullable();$t->boolean('is_active')->default(true);
            $t->rememberToken();$t->timestampsTz();
        });
        Schema::create('crm_customer_visits',function(Blueprint $t){
            $t->bigIncrements('id');
            $t->foreignId('erp_customer_id')->constrained('erp_customers')->restrictOnDelete();
            $t->foreignId('staff_user_id')->constrained('staff_users')->restrictOnDelete();
            $t->timestampTz('visited_at');$t->text('note')->nullable();$t->string('result',32);
            $t->timestampsTz();$t->index(['erp_customer_id','visited_at']);
        });
    }
    public function down():void {
        Schema::dropIfExists('crm_customer_visits');Schema::dropIfExists('staff_users');
    }
};
