<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Reading stats for each post and page: where its readers came from, and
 * roughly how many readers it had each day. Same privacy as the site's own
 * counts: no cookies, no addresses, and a daily scrambled fingerprint that's
 * thrown away after two days.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('page_stats', function (Blueprint $table) {
            $table->unsignedInteger('readers')->default(0);
        });

        Schema::create('entry_sources', function (Blueprint $table) {
            $table->id();
            $table->date('day');
            $table->string('entry_id', 64);
            $table->string('source', 120);
            $table->unsignedInteger('views')->default(0);
            $table->unique(['day', 'entry_id', 'source']);
            $table->index(['entry_id', 'day']);
        });

        Schema::create('entry_reader_days', function (Blueprint $table) {
            $table->date('day');
            $table->string('entry_id', 64);
            $table->char('hash', 16);
            $table->primary(['day', 'entry_id', 'hash']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('entry_reader_days');
        Schema::dropIfExists('entry_sources');
        Schema::table('page_stats', fn (Blueprint $table) => $table->dropColumn('readers'));
    }
};
