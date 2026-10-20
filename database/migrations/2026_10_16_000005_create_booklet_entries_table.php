<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Entries: every post, page, recipe… Only the structural fields are columns;
 * everything the blueprint defines (title, body, cover…) lives in `data`.
 * (Statamic: content/collections/{collection}/{slug}.md)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('entries', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('site')->index();
            $table->uuid('origin_id')->nullable()->index();
            $table->boolean('published')->default(true);
            $table->string('slug')->nullable();
            $table->string('uri')->nullable()->index();
            $table->dateTime('date')->nullable();
            $table->integer('order')->nullable();
            $table->string('collection')->index();
            $table->string('blueprint')->nullable();
            $table->json('data');
            $table->timestamps();

            $table->index(['collection', 'site', 'slug']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('entries');
    }
};
