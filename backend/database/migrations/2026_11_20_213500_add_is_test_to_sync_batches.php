<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sync_batches', function (Blueprint $table) {
            $table->boolean('is_test')->default(false)->after('record_count');
        });

        // Every batch received so far came from the "ทดสอบ Push API" button or
        // single-row demo pushes; no office sync agent has delivered real data yet.
        DB::table('sync_batches')->where('record_count', '<=', 1)->update(['is_test' => true]);
    }

    public function down(): void
    {
        Schema::table('sync_batches', function (Blueprint $table) {
            $table->dropColumn('is_test');
        });
    }
};
