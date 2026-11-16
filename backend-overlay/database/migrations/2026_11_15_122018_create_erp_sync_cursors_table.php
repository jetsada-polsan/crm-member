<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('erp_sync_cursors', function (Blueprint $table) {
            $table->id();
            $table->string('source_system', 32);
            $table->string('kind', 32);
            $table->unsignedBigInteger('cursor_value')->default(0);
            $table->timestamps();
            $table->unique(['source_system', 'kind']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('erp_sync_cursors');
    }
};
