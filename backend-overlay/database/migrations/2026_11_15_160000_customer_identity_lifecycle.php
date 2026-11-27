<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Reuse the existing customer master and member-to-ERP link as the identity hub.
        Schema::table('crm_customer_masters', function (Blueprint $table): void {
            $table->string('phone', 160)->nullable()->change();
            if (! Schema::hasColumn('crm_customer_masters', 'address')) {
                $table->string('address', 500)->nullable();
            }
        });

        Schema::table('member_accounts', function (Blueprint $table): void {
            $table->string('email')->nullable()->change();
            if (! Schema::hasColumn('member_accounts', 'phone')) {
                $table->string('phone', 40)->nullable();
            }
        });

        Schema::table('member_erp_links', function (Blueprint $table): void {
            $table->foreignId('erp_customer_id')->nullable()->change();
            if (! Schema::hasColumn('member_erp_links', 'crm_customer_master_id')) {
                $table->foreignId('crm_customer_master_id')->nullable()
                    ->constrained('crm_customer_masters')->nullOnDelete();
                $table->unique('crm_customer_master_id', 'member_erp_links_customer_master_unique');
            }
        });

        foreach (DB::table('member_erp_links')->whereNotNull('erp_customer_id')->whereNull('crm_customer_master_id')->orderBy('id')->get() as $link) {
            $masterId = DB::table('crm_customer_masters')->where('erp_customer_id', $link->erp_customer_id)->value('id');
            if ($masterId) {
                DB::table('member_erp_links')->where('id', $link->id)->update(['crm_customer_master_id' => $masterId]);
            }
        }

        Schema::table('member_verification_requests', function (Blueprint $table): void {
            $table->string('requested_customer_code', 64)->nullable()->change();
            if (! Schema::hasColumn('member_verification_requests', 'requested_source_system')) {
                $table->string('requested_source_system', 32)->nullable();
            }
            if (! Schema::hasColumn('member_verification_requests', 'crm_customer_master_id')) {
                $table->foreignId('crm_customer_master_id')->nullable()
                    ->constrained('crm_customer_masters')->nullOnDelete();
            }
        });

        Schema::create('customer_match_candidates', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->foreignId('crm_lead_id')->constrained('crm_leads')->cascadeOnDelete();
            $table->foreignId('erp_customer_id')->constrained('erp_customers')->cascadeOnDelete();
            $table->decimal('score', 5, 2)->default(0);
            $table->jsonb('match_reasons')->nullable();
            $table->string('status', 24)->default('pending')->index();
            $table->foreignId('reviewed_by')->nullable()->constrained('staff_users')->nullOnDelete();
            $table->timestampTz('reviewed_at')->nullable();
            $table->text('review_note')->nullable();
            $table->timestampsTz();
            $table->unique(['crm_lead_id', 'erp_customer_id'], 'customer_match_candidates_pair_unique');
            $table->index(['status', 'score']);
        });

        // Phone sign-in is supported only when the number identifies one account.
        // Stop safely if legacy data contains duplicates instead of changing accounts.
        $duplicates = DB::table('member_accounts')
            ->select('phone')->whereNotNull('phone')->where('phone', '!=', '')
            ->groupBy('phone')->havingRaw('COUNT(*) > 1')->limit(1)->exists();
        if (! $duplicates) {
            Schema::table('member_accounts', function (Blueprint $table): void {
                $table->unique('phone', 'member_accounts_phone_unique');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_match_candidates');

        Schema::table('member_verification_requests', function (Blueprint $table): void {
            if (Schema::hasColumn('member_verification_requests', 'crm_customer_master_id')) {
                $table->dropConstrainedForeignId('crm_customer_master_id');
            }
            if (Schema::hasColumn('member_verification_requests', 'requested_source_system')) {
                $table->dropColumn('requested_source_system');
            }
            $table->string('requested_customer_code', 64)->nullable(false)->change();
        });

        Schema::table('member_erp_links', function (Blueprint $table): void {
            if (Schema::hasColumn('member_erp_links', 'crm_customer_master_id')) {
                $table->dropUnique('member_erp_links_customer_master_unique');
                $table->dropConstrainedForeignId('crm_customer_master_id');
            }
            $table->foreignId('erp_customer_id')->nullable(false)->change();
        });

        Schema::table('member_accounts', function (Blueprint $table): void {
            $duplicates = DB::table('member_accounts')->select('phone')->whereNotNull('phone')->where('phone', '!=', '')
                ->groupBy('phone')->havingRaw('COUNT(*) > 1')->limit(1)->exists();
            if (! $duplicates) {
                $table->dropUnique('member_accounts_phone_unique');
            }
            if (! DB::table('member_accounts')->whereNull('email')->exists()) {
                $table->string('email')->nullable(false)->change();
            }
        });

        Schema::table('crm_customer_masters', function (Blueprint $table): void {
            if (Schema::hasColumn('crm_customer_masters', 'address')) {
                $table->dropColumn('address');
            }
        });
    }
};
