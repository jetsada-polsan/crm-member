<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (! Schema::hasTable('crm_platform_settings')) {
            Schema::create('crm_platform_settings', function (Blueprint $table): void {
                $table->string('key', 80)->primary();
                $table->text('value')->nullable();
                $table->boolean('is_secret')->default(false);
                $table->unsignedBigInteger('updated_by')->nullable();
                $table->timestampsTz();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('crm_platform_settings');
    }
};
