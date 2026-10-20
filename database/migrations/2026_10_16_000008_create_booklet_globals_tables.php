<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Global sets (site name, tagline, footer…) and their per-site values.
 * (Statamic: content/globals/{handle}.yaml, content/globals/{site}/{handle}.yaml)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('global_sets', function (Blueprint $table) {
            $table->id();
            $table->string('handle')->unique();
            $table->string('title');
            $table->json('settings')->nullable();
            $table->timestamps();
        });

        Schema::create('global_set_variables', function (Blueprint $table) {
            $table->id();
            $table->string('handle')->index();
            $table->string('locale');
            $table->string('origin')->nullable();
            $table->json('data');
            $table->timestamps();

            $table->unique(['handle', 'locale']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('global_set_variables');
        Schema::dropIfExists('global_sets');
    }
};
