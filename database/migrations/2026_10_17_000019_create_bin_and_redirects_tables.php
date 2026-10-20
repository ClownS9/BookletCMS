<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The bin: things torn out of the book, kept for 30 days so they can go back.
        Schema::create('bin_items', function (Blueprint $table) {
            $table->id();
            $table->string('kind', 16);                 // entry · asset · comment
            $table->string('item_key', 500);            // its id or path when it was deleted
            $table->string('title', 300);
            $table->string('meta', 200)->nullable();    // "Posts", "media", "on “Title”"
            $table->longText('payload');                // everything needed to put it back (JSON)
            $table->string('stored_file', 500)->nullable(); // a deleted file's copy, on the local disk
            $table->foreignId('deleted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('owner_id', 64)->nullable();  // the entry's author, so writers can restore their own
            $table->timestamp('expires_at')->index();
            $table->timestamps();
            $table->index(['kind', 'item_key']);
        });

        // Old addresses that forward to new ones (made when an entry's address changes).
        Schema::create('redirects', function (Blueprint $table) {
            $table->id();
            $table->string('from_path', 500)->unique();
            $table->string('to_path', 500);
            $table->unsignedSmallInteger('status')->default(301);
            $table->string('entry_id', 64)->nullable()->index(); // set when made automatically
            $table->boolean('automatic')->default(false);
            $table->unsignedInteger('hits')->default(0);
            $table->timestamp('last_hit_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('redirects');
        Schema::dropIfExists('bin_items');
    }
};
