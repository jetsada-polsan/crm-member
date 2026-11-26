<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('crm_leads', function (Blueprint $table): void {
            if (! Schema::hasColumn('crm_leads', 'contact_name')) {
                $table->string('contact_name', 120)->nullable()->after('phone');
            }
            if (! Schema::hasColumn('crm_leads', 'province')) {
                $table->string('province', 120)->nullable()->after('line_contact');
            }
            if (! Schema::hasColumn('crm_leads', 'supplier_source')) {
                $table->string('supplier_source', 255)->nullable()->after('address');
            }
            if (! Schema::hasColumn('crm_leads', 'product_categories')) {
                $table->text('product_categories')->nullable()->after('supplier_source');
            }
            if (! Schema::hasColumn('crm_leads', 'assigned_salesperson_code')) {
                $table->string('assigned_salesperson_code', 64)->nullable()->after('owner_staff_id');
                $table->index('assigned_salesperson_code');
            }
        });

        if (! Schema::hasTable('crm_customer_masters')) {
            Schema::create('crm_customer_masters', function (Blueprint $table): void {
                $table->bigIncrements('id');
                $table->string('internal_customer_code', 40)->unique();
                $table->foreignId('crm_lead_id')->nullable()->unique()->constrained('crm_leads')->nullOnDelete();
                $table->foreignId('erp_customer_id')->nullable()->unique()->constrained('erp_customers')->nullOnDelete();
                $table->string('erp_source_system', 32)->nullable();
                $table->string('erp_customer_code', 64)->nullable()->index();
                $table->string('link_status', 32)->default('unlinked')->index();
                $table->timestampTz('verified_at')->nullable();
                $table->foreignId('verified_by')->nullable()->constrained('staff_users')->nullOnDelete();
                $table->string('display_name');
                $table->string('phone', 40)->nullable()->index();
                $table->string('shop_type', 64)->nullable();
                $table->string('contact_name', 120)->nullable();
                $table->string('province', 120)->nullable();
                $table->string('district', 120)->nullable();
                $table->string('assigned_salesperson_code', 64)->nullable()->index();
                $table->string('created_source', 32)->default('crm_lead');
                $table->foreignId('created_by')->nullable()->constrained('staff_users')->nullOnDelete();
                $table->foreignId('updated_by')->nullable()->constrained('staff_users')->nullOnDelete();
                $table->timestampsTz();
            });
        }

        Schema::table('crm_customer_visits', function (Blueprint $table): void {
            if (! Schema::hasColumn('crm_customer_visits', 'customer_master_id')) {
                $table->unsignedBigInteger('customer_master_id')->nullable()->after('crm_lead_id')->index();
            }
            if (! Schema::hasColumn('crm_customer_visits', 'client_draft_id')) {
                $table->string('client_draft_id', 80)->nullable()->unique()->after('plan_stop_id');
            }
            if (! Schema::hasColumn('crm_customer_visits', 'device_recorded_at')) {
                $table->timestampTz('device_recorded_at')->nullable()->after('visited_at');
            }
            if (! Schema::hasColumn('crm_customer_visits', 'server_recorded_at')) {
                $table->timestampTz('server_recorded_at')->nullable()->after('device_recorded_at');
            }
            if (! Schema::hasColumn('crm_customer_visits', 'gps_permission_status')) {
                $table->string('gps_permission_status', 24)->nullable()->after('checkin_accuracy_m');
            }
        });

        Schema::table('crm_visit_photos', function (Blueprint $table): void {
            if (! Schema::hasColumn('crm_visit_photos', 'consent_confirmed')) {
                $table->boolean('consent_confirmed')->default(false)->after('original_name');
            }
            if (! Schema::hasColumn('crm_visit_photos', 'captured_at')) {
                $table->timestampTz('captured_at')->nullable()->after('consent_confirmed');
            }
            if (! Schema::hasColumn('crm_visit_photos', 'latitude')) {
                $table->decimal('latitude', 10, 7)->nullable()->after('captured_at');
            }
            if (! Schema::hasColumn('crm_visit_photos', 'longitude')) {
                $table->decimal('longitude', 10, 7)->nullable()->after('latitude');
            }
            if (! Schema::hasColumn('crm_visit_photos', 'accuracy_m')) {
                $table->integer('accuracy_m')->nullable()->after('longitude');
            }
            if (! Schema::hasColumn('crm_visit_photos', 'mime_type')) {
                $table->string('mime_type', 64)->nullable()->after('accuracy_m');
            }
            if (! Schema::hasColumn('crm_visit_photos', 'file_size_bytes')) {
                $table->unsignedBigInteger('file_size_bytes')->nullable()->after('mime_type');
            }
            if (! Schema::hasColumn('crm_visit_photos', 'image_width')) {
                $table->unsignedInteger('image_width')->nullable()->after('file_size_bytes');
            }
            if (! Schema::hasColumn('crm_visit_photos', 'image_height')) {
                $table->unsignedInteger('image_height')->nullable()->after('image_width');
            }
            if (! Schema::hasColumn('crm_visit_photos', 'compressed_on_client')) {
                $table->boolean('compressed_on_client')->default(false)->after('image_height');
            }
        });

        Schema::table('crm_lead_photos', function (Blueprint $table): void {
            if (! Schema::hasColumn('crm_lead_photos', 'consent_confirmed')) {
                $table->boolean('consent_confirmed')->default(false)->after('original_name');
            }
            if (! Schema::hasColumn('crm_lead_photos', 'captured_at')) {
                $table->timestampTz('captured_at')->nullable()->after('consent_confirmed');
            }
            if (! Schema::hasColumn('crm_lead_photos', 'latitude')) {
                $table->decimal('latitude', 10, 7)->nullable()->after('captured_at');
            }
            if (! Schema::hasColumn('crm_lead_photos', 'longitude')) {
                $table->decimal('longitude', 10, 7)->nullable()->after('latitude');
            }
            if (! Schema::hasColumn('crm_lead_photos', 'accuracy_m')) {
                $table->integer('accuracy_m')->nullable()->after('longitude');
            }
            if (! Schema::hasColumn('crm_lead_photos', 'mime_type')) {
                $table->string('mime_type', 64)->nullable()->after('accuracy_m');
            }
            if (! Schema::hasColumn('crm_lead_photos', 'file_size_bytes')) {
                $table->unsignedBigInteger('file_size_bytes')->nullable()->after('mime_type');
            }
            if (! Schema::hasColumn('crm_lead_photos', 'compressed_on_client')) {
                $table->boolean('compressed_on_client')->default(false)->after('file_size_bytes');
            }
        });

        // Backfill Customer Master records for any pre-existing CRM leads & ERP customers
        $now = now();
        DB::table('crm_leads')->orderBy('id')->chunkById(100, function ($leads) use ($now): void {
            foreach ($leads as $lead) {
                $erp = null;
                if ($lead->converted_erp_customer_id) {
                    $erp = DB::table('erp_customers')->where('id', $lead->converted_erp_customer_id)->first();
                }
                DB::table('crm_customer_masters')->updateOrInsert(
                    ['crm_lead_id' => $lead->id],
                    [
                        'internal_customer_code' => sprintf('POP-LEAD-%06d', $lead->id),
                        'erp_customer_id' => $erp?->id,
                        'erp_source_system' => $erp?->source_system,
                        'erp_customer_code' => $erp?->erp_customer_code,
                        'link_status' => $erp ? 'linked' : 'unlinked',
                        'verified_at' => $erp ? ($lead->approved_at ?? $now) : null,
                        'verified_by' => $erp ? $lead->approved_by : null,
                        'display_name' => $lead->shop_name,
                        'phone' => $lead->phone,
                        'shop_type' => $lead->shop_type,
                        'contact_name' => $lead->contact_name ?? null,
                        'province' => $lead->province ?? null,
                        'district' => $lead->district,
                        'assigned_salesperson_code' => $lead->assigned_salesperson_code ?? null,
                        'created_source' => 'crm_lead',
                        'created_by' => $lead->created_by ?? $lead->owner_staff_id,
                        'updated_by' => $lead->updated_by ?? $lead->owner_staff_id,
                        'created_at' => $lead->created_at ?? $now,
                        'updated_at' => $now,
                    ]
                );
            }
        });

        DB::table('erp_customers')->orderBy('id')->chunkById(100, function ($customers) use ($now): void {
            foreach ($customers as $c) {
                $exists = DB::table('crm_customer_masters')->where('erp_customer_id', $c->id)->exists();
                if (! $exists) {
                    DB::table('crm_customer_masters')->insert([
                        'internal_customer_code' => sprintf('POP-ERP-%06d', $c->id),
                        'erp_customer_id' => $c->id,
                        'erp_source_system' => $c->source_system,
                        'erp_customer_code' => $c->erp_customer_code,
                        'link_status' => 'linked',
                        'verified_at' => $c->created_at ?? $now,
                        'display_name' => $c->customer_name,
                        'phone' => $c->phone,
                        'province' => $c->province,
                        'assigned_salesperson_code' => $c->salesperson_code,
                        'created_source' => 'erp_mirror',
                        'created_at' => $c->created_at ?? $now,
                        'updated_at' => $now,
                    ]);
                }
            }
        });
    }

    public function down(): void
    {
        Schema::table('crm_lead_photos', function (Blueprint $table): void {
            $cols = array_filter([
                'consent_confirmed', 'captured_at', 'latitude', 'longitude',
                'accuracy_m', 'mime_type', 'file_size_bytes', 'compressed_on_client',
            ], fn ($c) => Schema::hasColumn('crm_lead_photos', $c));
            if ($cols !== []) {
                $table->dropColumn(array_values($cols));
            }
        });

        Schema::table('crm_visit_photos', function (Blueprint $table): void {
            $cols = array_filter([
                'consent_confirmed', 'captured_at', 'latitude', 'longitude',
                'accuracy_m', 'mime_type', 'file_size_bytes', 'image_width', 'image_height', 'compressed_on_client',
            ], fn ($c) => Schema::hasColumn('crm_visit_photos', $c));
            if ($cols !== []) {
                $table->dropColumn(array_values($cols));
            }
        });

        Schema::table('crm_customer_visits', function (Blueprint $table): void {
            if (Schema::hasColumn('crm_customer_visits', 'client_draft_id')) {
                $table->dropUnique(['client_draft_id']);
            }
            $cols = array_filter([
                'customer_master_id', 'client_draft_id', 'device_recorded_at', 'server_recorded_at', 'gps_permission_status',
            ], fn ($c) => Schema::hasColumn('crm_customer_visits', $c));
            if ($cols !== []) {
                $table->dropColumn(array_values($cols));
            }
        });

        Schema::dropIfExists('crm_customer_masters');

        Schema::table('crm_leads', function (Blueprint $table): void {
            $cols = array_filter([
                'contact_name', 'province', 'supplier_source', 'product_categories', 'assigned_salesperson_code',
            ], fn ($c) => Schema::hasColumn('crm_leads', $c));
            if ($cols !== []) {
                $table->dropColumn(array_values($cols));
            }
        });
    }
};
