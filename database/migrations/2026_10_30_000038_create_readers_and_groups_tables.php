<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Readers (people with an account on the public site, never the admin) and
 * groups (readers, and people from the book, gathered so pages can be opened
 * to them). Readers are kept apart from the book's people on purpose: a
 * reader's account can't reach the admin, whatever it's called.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('readers', function (Blueprint $table) {
            $table->id();
            $table->string('name', 120);
            $table->string('email', 190)->unique();
            $table->string('password');
            // unconfirmed (email not confirmed yet), waiting (for an admin to let them in), active, blocked
            $table->string('state', 12)->default('unconfirmed')->index();
            $table->timestamp('email_verified_at')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->json('answers')->nullable();
            $table->foreignId('invited_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('last_seen_at')->nullable();
            $table->rememberToken();
            $table->timestamps();
        });

        Schema::create('reader_password_resets', function (Blueprint $table) {
            $table->string('email', 190)->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('groups', function (Blueprint $table) {
            $table->id();
            $table->string('handle', 60)->unique();
            $table->string('name', 80);
            $table->text('about')->nullable();
            // open (anyone signed in may join), approval (they ask), invite (by link), closed (added by the book)
            $table->string('joining', 10)->default('closed');
            $table->string('invite_token', 48)->nullable()->unique();
            $table->timestamps();
        });

        Schema::create('group_members', function (Blueprint $table) {
            $table->id();
            $table->foreignId('group_id')->constrained('groups')->cascadeOnDelete();
            $table->string('member_type', 10); // reader or user
            $table->unsignedBigInteger('member_id');
            $table->string('state', 8)->default('in'); // in, or asked (waiting for approval)
            $table->timestamps();
            $table->unique(['group_id', 'member_type', 'member_id']);
            $table->index(['member_type', 'member_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('group_members');
        Schema::dropIfExists('groups');
        Schema::dropIfExists('reader_password_resets');
        Schema::dropIfExists('readers');
    }
};
