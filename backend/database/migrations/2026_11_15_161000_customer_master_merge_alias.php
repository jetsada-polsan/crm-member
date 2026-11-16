<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (! Schema::hasColumn('crm_customer_masters', 'merged_into_customer_master_id')) {
            Schema::table('crm_customer_masters', function (Blueprint $table): void {
                $table->foreignId('merged_into_customer_master_id')->nullable()
                    ->constrained('crm_customer_masters')->nullOnDelete();
                $table->index('merged_into_customer_master_id', 'crm_customer_masters_merge_target_idx');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('crm_customer_masters', 'merged_into_customer_master_id')) {
            Schema::table('crm_customer_masters', function (Blueprint $table): void {
                $table->dropIndex('crm_customer_masters_merge_target_idx');
                $table->dropConstrainedForeignId('merged_into_customer_master_id');
            });
        }
    }
};
