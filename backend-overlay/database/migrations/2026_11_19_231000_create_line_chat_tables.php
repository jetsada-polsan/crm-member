<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('line_chat_threads')) {
            Schema::create('line_chat_threads', function (Blueprint $table) {
                $table->id();
                $table->string('line_user_id', 120)->unique();
                $table->string('display_name', 180)->nullable();
                $table->string('picture_url', 500)->nullable();
                $table->unsignedBigInteger('erp_customer_id')->nullable()->index();
                $table->unsignedBigInteger('member_account_id')->nullable()->index();
                $table->unsignedBigInteger('assigned_staff_id')->nullable()->index();
                $table->string('status', 24)->default('open')->index(); // open | resolved
                $table->unsignedInteger('unread_count')->default(0);
                $table->string('last_message_preview', 300)->nullable();
                $table->timestamp('last_message_at')->nullable()->index();
                $table->text('notes')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('line_chat_messages')) {
            Schema::create('line_chat_messages', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('thread_id')->index();
                $table->string('line_message_id', 120)->nullable()->index();
                $table->string('direction', 16)->default('incoming'); // incoming | outgoing | system
                $table->unsignedBigInteger('sender_staff_id')->nullable()->index();
                $table->string('message_type', 32)->default('text'); // text | image | sticker | location | file
                $table->text('body')->nullable();
                $table->string('delivery_status', 24)->default('received'); // received | sent | simulated | failed
                $table->string('delivery_error', 500)->nullable();
                $table->text('raw_payload')->nullable();
                $table->timestamp('created_at')->nullable()->index();

                $table->foreign('thread_id')->references('id')->on('line_chat_threads')->onDelete('cascade');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('line_chat_messages');
        Schema::dropIfExists('line_chat_threads');
    }
};
