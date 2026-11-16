<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
 public function up():void {
  Schema::create('crm_leads',function(Blueprint $t){
   $t->id();$t->string('shop_name');$t->string('phone',40)->nullable();$t->string('line_contact',120)->nullable();
   $t->string('district',120)->nullable();$t->text('address')->nullable();$t->string('status',32)->default('new');
   $t->foreignId('owner_staff_id')->constrained('staff_users')->restrictOnDelete();$t->foreignId('converted_erp_customer_id')->nullable()->constrained('erp_customers')->nullOnDelete();$t->timestampsTz();
  });
  Schema::create('crm_customer_locations',function(Blueprint $t){
   $t->id();$t->foreignId('erp_customer_id')->nullable()->unique()->constrained('erp_customers')->cascadeOnDelete();
   $t->foreignId('crm_lead_id')->nullable()->unique()->constrained('crm_leads')->cascadeOnDelete();
   $t->decimal('latitude',10,7);$t->decimal('longitude',10,7);$t->unsignedInteger('accuracy_m')->nullable();
   $t->text('address')->nullable();$t->string('source',24)->default('gps');$t->foreignId('pinned_by')->constrained('staff_users')->restrictOnDelete();$t->timestampTz('pinned_at');$t->timestampsTz();
  });
  Schema::create('crm_visit_plans',function(Blueprint $t){
   $t->id();$t->string('route_name',160);$t->date('visit_date');$t->string('district',120)->nullable();$t->foreignId('assigned_to')->constrained('staff_users')->restrictOnDelete();
   $t->foreignId('created_by')->constrained('staff_users')->restrictOnDelete();$t->string('status',30)->default('planned');$t->text('note')->nullable();$t->timestampsTz();
  });
  Schema::create('crm_visit_plan_stops',function(Blueprint $t){
   $t->id();$t->foreignId('plan_id')->constrained('crm_visit_plans')->cascadeOnDelete();$t->unsignedInteger('stop_order');
   $t->foreignId('erp_customer_id')->nullable()->constrained('erp_customers')->restrictOnDelete();$t->foreignId('crm_lead_id')->nullable()->constrained('crm_leads')->restrictOnDelete();
   $t->string('status',30)->default('pending');$t->timestampsTz();$t->unique(['plan_id','stop_order']);
  });
  Schema::table('crm_customer_visits',function(Blueprint $t){
   // Legacy ERP visit relation was mandatory; allow a newly discovered shop instead.
   $t->foreignId('erp_customer_id')->nullable()->change();$t->foreignId('crm_lead_id')->nullable()->after('erp_customer_id')->constrained('crm_leads')->restrictOnDelete();
   $t->foreignId('plan_stop_id')->nullable()->constrained('crm_visit_plan_stops')->nullOnDelete();
   $t->timestampTz('checkin_at')->nullable();$t->timestampTz('checkout_at')->nullable();
   $t->decimal('checkin_latitude',10,7)->nullable();$t->decimal('checkin_longitude',10,7)->nullable();$t->unsignedInteger('checkin_accuracy_m')->nullable();
   $t->decimal('checkout_latitude',10,7)->nullable();$t->decimal('checkout_longitude',10,7)->nullable();
   $t->string('shop_type',16)->default('existing');$t->string('shop_phone',40)->nullable();$t->string('supplier_name',255)->nullable();
   $t->text('product_types')->nullable();$t->text('customer_issues')->nullable();$t->text('customer_feedback')->nullable();
   $t->text('follow_up_action')->nullable();$t->date('follow_up_date')->nullable();
  });
  Schema::create('crm_visit_photos',function(Blueprint $t){
   $t->id();$t->foreignId('visit_id')->constrained('crm_customer_visits')->cascadeOnDelete();
   $t->string('storage_path');$t->string('category',32)->default('storefront');$t->string('original_name')->nullable();
   $t->foreignId('uploaded_by')->constrained('staff_users')->restrictOnDelete();$t->timestampsTz();
  });
  Schema::create('crm_follow_up_tasks',function(Blueprint $t){
   $t->id();$t->foreignId('visit_id')->nullable()->constrained('crm_customer_visits')->nullOnDelete();
   $t->foreignId('assigned_to')->constrained('staff_users')->restrictOnDelete();$t->string('title');$t->text('description')->nullable();
   $t->date('due_date')->nullable();$t->string('status',20)->default('open');$t->timestampsTz();
  });
  Schema::create('crm_sales_targets',function(Blueprint $t){
   $t->id();$t->foreignId('staff_user_id')->constrained('staff_users')->restrictOnDelete();$t->date('period_start');$t->date('period_end');
   $t->decimal('target_amount',16,2)->default(0);$t->unsignedInteger('target_visits')->default(0);$t->timestampsTz();
  });
 }
 public function down():void {
  foreach(['crm_sales_targets','crm_follow_up_tasks','crm_visit_photos','crm_visit_plan_stops','crm_visit_plans','crm_customer_locations'] as $table) Schema::dropIfExists($table);
  Schema::table('crm_customer_visits',function(Blueprint $t){
   $t->dropConstrainedForeignId('crm_lead_id');$t->dropConstrainedForeignId('plan_stop_id');
   $t->dropColumn(['checkin_at','checkout_at','checkin_latitude','checkin_longitude','checkin_accuracy_m','checkout_latitude','checkout_longitude','shop_type','shop_phone','supplier_name','product_types','customer_issues','customer_feedback','follow_up_action','follow_up_date']);
   $t->foreignId('erp_customer_id')->nullable(false)->change();
  });
  Schema::dropIfExists('crm_leads');
 }
};
