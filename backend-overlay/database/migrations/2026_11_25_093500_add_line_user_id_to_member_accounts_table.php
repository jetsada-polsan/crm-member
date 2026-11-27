<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('member_accounts') && ! Schema::hasColumn('member_accounts', 'line_user_id')) {
            Schema::table('member_accounts', function (Blueprint $table): void {
                $table->string('line_user_id', 128)->nullable()->unique('member_accounts_line_user_id_unique')->after('phone');
            });

            if (Schema::hasTable('member_line_accounts')) {
                $bindings = DB::table('member_line_accounts')
                    ->where('binding_status', 'verified')
                    ->whereNull('revoked_at')
                    ->whereNotNull('line_user_id')
                    ->orderBy('id')
                    ->get(['member_account_id', 'line_user_id']);

                $usedLineIds = [];
                foreach ($bindings as $binding) {
                    $lineId = trim((string) $binding->line_user_id);
                    if ($lineId === '' || isset($usedLineIds[$lineId])) {
                        continue;
                    }
                    $usedLineIds[$lineId] = true;
                    DB::table('member_accounts')
                        ->where('id', $binding->member_account_id)
                        ->whereNull('line_user_id')
                        ->update(['line_user_id' => $lineId]);
                }
            }
        }

        if (Schema::hasTable('loyalty_points_ledger') && ! Schema::hasColumn('loyalty_points_ledger', 'expires_at')) {
            Schema::table('loyalty_points_ledger', function (Blueprint $table): void {
                $table->timestampTz('expires_at')->nullable()->after('note');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('loyalty_points_ledger') && Schema::hasColumn('loyalty_points_ledger', 'expires_at')) {
            Schema::table('loyalty_points_ledger', function (Blueprint $table): void {
                $table->dropColumn('expires_at');
            });
        }

        if (Schema::hasTable('member_accounts') && Schema::hasColumn('member_accounts', 'line_user_id')) {
            Schema::table('member_accounts', function (Blueprint $table): void {
                $table->dropUnique('member_accounts_line_user_id_unique');
                $table->dropColumn('line_user_id');
            });
        }
    }
};
