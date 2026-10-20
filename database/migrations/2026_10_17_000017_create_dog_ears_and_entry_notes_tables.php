<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A folded page corner: one person's bookmark to a page of the admin.
        Schema::create('dog_ears', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('url', 500);
            $table->string('title', 200);
            $table->string('chapter', 120)->nullable();
            $table->timestamps();
            $table->unique(['user_id', 'url']);
        });

        // Notes stuck in the margin of an entry, for the team only.
        Schema::create('entry_notes', function (Blueprint $table) {
            $table->id();
            $table->string('entry_id', 64)->index();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->text('body');
            $table->string('tone', 16)->default('sun');
            $table->timestamp('done_at')->nullable();
            $table->foreignId('done_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('entry_notes');
        Schema::dropIfExists('dog_ears');
    }
};
