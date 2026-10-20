<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Readers who asked for new posts by email (they confirm first).
        Schema::create('subscribers', function (Blueprint $table) {
            $table->id();
            $table->string('email', 190)->unique();
            $table->string('name', 120)->nullable();
            $table->string('token', 64)->unique();
            $table->timestamp('confirmed_at')->nullable()->index();
            $table->timestamp('unsubscribed_at')->nullable();
            $table->string('source', 20)->default('site');
            $table->unsignedInteger('emails_sent')->default(0);
            $table->timestamp('last_sent_at')->nullable();
            $table->timestamps();
        });

        // Which posts have gone out by email, so none goes twice.
        Schema::create('post_emails', function (Blueprint $table) {
            $table->id();
            $table->string('entry_id', 64)->unique();
            $table->string('title')->nullable();
            $table->unsignedInteger('recipients')->default(0);
            $table->string('status', 20)->default('sent'); // sent · skipped · sending
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('post_emails');
        Schema::dropIfExists('subscribers');
    }
};
