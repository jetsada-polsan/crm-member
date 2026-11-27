<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Shared default password that auto-provisioned accounts used to receive.
     * It is committed in git history, so every account still using it is forced to change it.
     */
    private const LEAKED_DEFAULT_PASSWORD = 'Popstar2026!';

    public function up(): void
    {
        Schema::table('staff_users', function (Blueprint $table): void {
            if (! Schema::hasColumn('staff_users', 'must_change_password')) {
                $table->boolean('must_change_password')->default(false)->after('password');
            }
        });

        DB::table('staff_users')
            ->select('id', 'password')
            ->orderBy('id')
            ->get()
            ->filter(fn (object $staff): bool => is_string($staff->password)
                && $staff->password !== ''
                && Hash::check(self::LEAKED_DEFAULT_PASSWORD, $staff->password))
            ->each(fn (object $staff) => DB::table('staff_users')
                ->where('id', $staff->id)
                ->update(['must_change_password' => true]));
    }

    public function down(): void
    {
        Schema::table('staff_users', function (Blueprint $table): void {
            if (Schema::hasColumn('staff_users', 'must_change_password')) {
                $table->dropColumn('must_change_password');
            }
        });
    }
};
