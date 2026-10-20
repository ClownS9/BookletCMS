<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Revisions — the "Editions" screen. `key` points at what was revised,
 * e.g. "collections/posts/en/{entry-id}"; `attributes` is the full snapshot.
 * (Statamic: storage/statamic/revisions/{key}/{timestamp}.yaml)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('revisions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('key')->index();
            $table->string('action');
            $table->string('user')->nullable();
            $table->string('message')->nullable();
            $table->json('attributes');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('revisions');
    }
};
