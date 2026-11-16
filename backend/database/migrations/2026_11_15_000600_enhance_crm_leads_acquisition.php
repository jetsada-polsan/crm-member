<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('crm_leads', function (Blueprint $table) {
            $table->string('shop_type', 64)->nullable()->after('shop_name');
            $table->string('pipeline_stage', 32)->default('new_lead')->after('status');
            $table->string('approval_status', 24)->default('pending')->after('pipeline_stage');
            $table->foreignId('approved_by')->nullable()->after('approval_status')->constrained('staff_users')->nullOnDelete();
            $table->timestampTz('approved_at')->nullable()->after('approved_by');
            $table->text('approval_note')->nullable()->after('approved_at');
            $table->boolean('is_minimal_draft')->default(true)->after('approval_note');
            $table->date('next_follow_up_date')->nullable()->after('is_minimal_draft');
            $table->text('notes')->nullable()->after('next_follow_up_date');
            $table->foreignId('created_by')->nullable()->after('owner_staff_id')->constrained('staff_users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->after('created_by')->constrained('staff_users')->nullOnDelete();

            $table->index('phone');
            $table->index('shop_name');
            $table->index('pipeline_stage');
            $table->index('approval_status');
            $table->index('created_at');
        });

        // Backfill created_by / updated_by for any existing leads
        DB::statement('UPDATE crm_leads SET created_by = owner_staff_id, updated_by = owner_staff_id WHERE created_by IS NULL');

        Schema::create('crm_lead_photos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('crm_lead_id')->constrained('crm_leads')->cascadeOnDelete();
            $table->string('storage_path');
            $table->string('category', 32)->default('storefront');
            $table->string('original_name')->nullable();
            $table->foreignId('uploaded_by')->constrained('staff_users')->restrictOnDelete();
            $table->timestampsTz();
            $table->index('crm_lead_id');
        });

        Schema::create('crm_audit_logs', function (Blueprint $table) {
            $table->id();
            $table->string('entity_type', 40);
            $table->unsignedBigInteger('entity_id');
            $table->string('action', 40);
            $table->foreignId('actor_staff_id')->nullable()->constrained('staff_users')->nullOnDelete();
            $table->jsonb('changes')->nullable();
            $table->timestampTz('created_at')->useCurrent();
            $table->index(['entity_type', 'entity_id', 'created_at']);
        });

        Schema::table('crm_follow_up_tasks', function (Blueprint $table) {
            $table->foreignId('crm_lead_id')->nullable()->after('visit_id')->constrained('crm_leads')->nullOnDelete();
            $table->index('crm_lead_id');
        });
    }

    public function down(): void
    {
        Schema::table('crm_follow_up_tasks', function (Blueprint $table) {
            $table->dropConstrainedForeignId('crm_lead_id');
        });

        Schema::dropIfExists('crm_audit_logs');
        Schema::dropIfExists('crm_lead_photos');

        Schema::table('crm_leads', function (Blueprint $table) {
            $table->dropIndex(['phone']);
            $table->dropIndex(['shop_name']);
            $table->dropIndex(['pipeline_stage']);
            $table->dropIndex(['approval_status']);
            $table->dropIndex(['created_at']);
            $table->dropConstrainedForeignId('approved_by');
            $table->dropConstrainedForeignId('created_by');
            $table->dropConstrainedForeignId('updated_by');
            $table->dropColumn([
                'shop_type',
                'pipeline_stage',
                'approval_status',
                'approved_at',
                'approval_note',
                'is_minimal_draft',
                'next_follow_up_date',
                'notes',
            ]);
        });
    }
};
