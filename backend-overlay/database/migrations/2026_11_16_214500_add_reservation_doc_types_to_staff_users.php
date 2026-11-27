<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('staff_users', function (Blueprint $table): void {
            if (! Schema::hasColumn('staff_users', 'reservation_doc_types')) {
                $table->string('reservation_doc_types', 255)->nullable()->after('salesperson_code');
            }
        });
    }

    public function down(): void
    {
        Schema::table('staff_users', function (Blueprint $table): void {
            if (Schema::hasColumn('staff_users', 'reservation_doc_types')) {
                $table->dropColumn('reservation_doc_types');
            }
        });
    }
};
