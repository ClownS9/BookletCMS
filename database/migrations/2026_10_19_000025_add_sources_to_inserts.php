<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Inserts added from the shelf remember where they came from, so a newer
 * version can be found (checked daily) and brought in with one click.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inserts', function (Blueprint $table) {
            $table->json('source')->nullable();               // {type: github|url|upload, repo|url|file}
            $table->string('latest_version', 30)->nullable(); // what the daily check found
            $table->timestamp('latest_checked_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('inserts', function (Blueprint $table) {
            $table->dropColumn(['source', 'latest_version', 'latest_checked_at']);
        });
    }
};
