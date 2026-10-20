<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A proof copy: a private link to an entry (drafts too) for someone without an account.
        Schema::create('proofs', function (Blueprint $table) {
            $table->id();
            $table->string('entry_id', 64)->index();
            $table->string('token_hash', 64)->unique(); // sha256 of the link's secret: what's looked up
            $table->text('token');                       // the secret itself, encrypted, to copy the link again
            $table->string('label', 80)->nullable();     // who it's for
            $table->boolean('notes_allowed')->default(true);
            $table->timestamp('expires_at');
            $table->timestamp('revoked_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('opened_at')->nullable();
            $table->unsignedInteger('opens')->default(0);
            $table->timestamps();
        });

        // Notes left in the margin of a proof copy by the person reading it.
        Schema::create('proof_notes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('proof_id')->constrained('proofs')->cascadeOnDelete();
            $table->string('entry_id', 64)->index();
            $table->string('name', 60);
            $table->string('quote', 300)->nullable();
            $table->text('body');
            $table->timestamp('resolved_at')->nullable();
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('proof_notes');
        Schema::dropIfExists('proofs');
    }
};
