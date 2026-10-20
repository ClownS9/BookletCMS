<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Booklet's own app data, which Statamic doesn't have. These always use the
 * database (like users and sessions), whatever the content drivers are set to.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('comments', function (Blueprint $table) {
            $table->id();
            $table->uuid('entry_id')->index();
            $table->foreignId('parent_id')->nullable()->constrained('comments')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete(); // set when an author replies
            $table->string('author_name');
            $table->string('author_email')->nullable();
            $table->text('body');
            $table->string('status')->default('waiting')->index(); // waiting | approved | spam
            $table->timestamps();
        });

        Schema::create('activity_log', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('actor')->nullable(); // e.g. "stacy" when the assistant acted
            $table->string('action');            // edited, published, uploaded…
            $table->string('kind')->index();     // edits | publishing | uploads | comments | people | settings
            $table->string('subject_type');      // entry, term, asset, user, settings…
            $table->string('subject_key')->nullable();
            $table->string('subject_label')->nullable();
            $table->json('properties')->nullable();
            $table->timestamp('created_at')->useCurrent()->index();
        });

        Schema::create('stacy_conversations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('title')->nullable();
            $table->timestamps();
        });

        Schema::create('stacy_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('conversation_id')->constrained('stacy_conversations')->cascadeOnDelete();
            $table->string('role'); // user | assistant
            $table->text('content');
            $table->json('meta')->nullable(); // links shown, pending confirmations…
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stacy_messages');
        Schema::dropIfExists('stacy_conversations');
        Schema::dropIfExists('activity_log');
        Schema::dropIfExists('comments');
    }
};
