<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Taxonomies (Categories, Tags) and their terms.
 * (Statamic: content/taxonomies/{handle}.yaml, content/taxonomies/{handle}/{slug}.yaml)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('taxonomies', function (Blueprint $table) {
            $table->id();
            $table->string('handle')->unique();
            $table->string('title');
            $table->json('sites')->nullable();
            $table->json('settings')->nullable();
            $table->timestamps();
        });

        Schema::create('terms', function (Blueprint $table) {
            $table->id();
            $table->string('site');
            $table->string('slug');
            $table->string('uri')->nullable()->index();
            $table->string('taxonomy')->index();
            $table->json('data');
            $table->timestamps();

            $table->unique(['slug', 'taxonomy', 'site']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('terms');
        Schema::dropIfExists('taxonomies');
    }
};
