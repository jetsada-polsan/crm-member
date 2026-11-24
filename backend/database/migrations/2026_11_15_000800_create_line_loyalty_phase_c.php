<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('member_line_accounts', function (Blueprint $table): void {
            $table->string('line_display_name', 120)->nullable()->after('line_user_id');
            $table->string('binding_status', 32)->default('verified')->after('line_display_name');
            $table->timestampTz('revoked_at')->nullable()->after('verified_at');
            $table->timestampTz('last_webhook_at')->nullable()->after('revoked_at');
        });

        Schema::create('line_link_tokens', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->foreignId('member_account_id')->constrained('member_accounts')->restrictOnDelete();
            $table->char('token_hash', 64)->unique();
            $table->string('line_user_id', 128)->nullable();
            $table->timestampTz('expires_at');
            $table->timestampTz('consumed_at')->nullable();
            $table->timestampTz('created_at')->useCurrent();
            $table->index(['member_account_id', 'expires_at']);
        });

        Schema::create('line_webhook_events', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->string('webhook_event_id', 128)->nullable()->unique();
            $table->string('event_type', 64);
            $table->string('line_user_id', 128)->nullable()->index();
            $table->boolean('signature_valid')->default(false);
            $table->json('payload');
            $table->timestampTz('processed_at')->nullable();
            $table->timestampTz('created_at')->useCurrent();
        });

        Schema::create('member_verification_requests', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->foreignId('member_account_id')->constrained('member_accounts')->restrictOnDelete();
            $table->string('requested_customer_code', 64);
            $table->string('requested_member_code', 64)->nullable();
            $table->string('requested_phone', 40)->nullable();
            $table->text('evidence_note')->nullable();
            $table->string('status', 32)->default('pending');
            $table->foreignId('reviewed_by')->nullable()->constrained('staff_users')->nullOnDelete();
            $table->timestampTz('reviewed_at')->nullable();
            $table->text('review_note')->nullable();
            $table->timestampsTz();
            $table->index(['member_account_id', 'status']);
        });

        Schema::create('loyalty_rules', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->string('rule_code', 64)->unique();
            $table->string('name', 160);
            $table->text('description')->nullable();
            $table->decimal('baht_per_point', 12, 2)->default(100.00);
            $table->unsignedInteger('points_per_unit')->default(1);
            $table->decimal('min_invoice_amount', 14, 2)->default(0.00);
            $table->json('exclude_categories')->nullable();
            $table->boolean('exclude_reservation')->default(true);
            $table->boolean('exclude_cancelled')->default(true);
            $table->boolean('reverse_on_return')->default(true);
            $table->boolean('is_active')->default(false);
            $table->foreignId('configured_by')->nullable()->constrained('staff_users')->nullOnDelete();
            $table->timestampsTz();
        });

        Schema::table('loyalty_points_ledger', function (Blueprint $table): void {
            $table->string('entry_type', 32)->default('manual')->after('reason');
            $table->string('reference_type', 64)->nullable()->after('entry_type');
            $table->string('reference_id', 128)->nullable()->after('reference_type');
            $table->unsignedBigInteger('reverses_ledger_id')->nullable()->after('reference_id');
            $table->unsignedBigInteger('rule_id')->nullable()->after('reverses_ledger_id');
            $table->unsignedBigInteger('created_by_staff_id')->nullable()->after('rule_id');
            $table->index('reverses_ledger_id');
            $table->index(['reference_type', 'reference_id']);
        });

        Schema::create('loyalty_rewards', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->string('reward_code', 64)->unique();
            $table->string('title', 160);
            $table->text('description')->nullable();
            $table->unsignedInteger('points_cost');
            $table->integer('stock_qty')->nullable();
            $table->boolean('is_active')->default(false);
            $table->timestampTz('valid_from')->nullable();
            $table->timestampTz('valid_until')->nullable();
            $table->timestampsTz();
        });

        Schema::create('loyalty_redemptions', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->foreignId('member_account_id')->constrained('member_accounts')->restrictOnDelete();
            $table->foreignId('reward_id')->constrained('loyalty_rewards')->restrictOnDelete();
            $table->unsignedBigInteger('ledger_entry_id')->nullable()->index();
            $table->unsignedInteger('points_spent');
            $table->string('status', 32)->default('pending');
            $table->string('voucher_code', 64)->unique();
            $table->foreignId('reviewed_by')->nullable()->constrained('staff_users')->nullOnDelete();
            $table->timestampTz('fulfilled_at')->nullable();
            $table->timestampTz('cancelled_at')->nullable();
            $table->text('note')->nullable();
            $table->timestampsTz();
            $table->index(['member_account_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('loyalty_redemptions');
        Schema::dropIfExists('loyalty_rewards');
        Schema::table('loyalty_points_ledger', function (Blueprint $table): void {
            $table->dropColumn([
                'entry_type',
                'reference_type',
                'reference_id',
                'reverses_ledger_id',
                'rule_id',
                'created_by_staff_id',
            ]);
        });
        Schema::dropIfExists('loyalty_rules');
        Schema::dropIfExists('member_verification_requests');
        Schema::dropIfExists('line_webhook_events');
        Schema::dropIfExists('line_link_tokens');
        Schema::table('member_line_accounts', function (Blueprint $table): void {
            $table->dropColumn(['line_display_name', 'binding_status', 'revoked_at', 'last_webhook_at']);
        });
    }
};
