<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Visit stats without cookies or personal data: views and read-throughs per
 * page per day, where readers came from, and a daily scrambled fingerprint
 * (never an address) to count readers roughly, thrown away after two days.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('page_stats', function (Blueprint $table) {
            $table->id();
            $table->date('day');
            $table->string('path', 255);
            $table->string('entry_id', 64)->nullable()->index();
            $table->unsignedInteger('views')->default(0);
            $table->unsignedInteger('reads')->default(0);
            $table->unique(['day', 'path']);
        });

        Schema::create('referrer_stats', function (Blueprint $table) {
            $table->id();
            $table->date('day');
            $table->string('source', 120);
            $table->unsignedInteger('views')->default(0);
            $table->unique(['day', 'source']);
        });

        Schema::create('visitor_days', function (Blueprint $table) {
            $table->date('day');
            $table->char('hash', 16);
            $table->primary(['day', 'hash']);
        });

        Schema::table('site_visits', function (Blueprint $table) {
            $table->unsignedInteger('visitors')->default(0);
        });
    }

    public function down(): void
    {
        Schema::table('site_visits', fn (Blueprint $table) => $table->dropColumn('visitors'));
        Schema::dropIfExists('visitor_days');
        Schema::dropIfExists('referrer_stats');
        Schema::dropIfExists('page_stats');
    }
};
