<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Navigations (site menus) and trees (the nesting for navigations and for
 * structured collections like Pages).
 * (Statamic: content/navigation/{handle}.yaml, content/trees/{type}/{handle}.yaml)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('navigations', function (Blueprint $table) {
            $table->id();
            $table->string('handle')->unique();
            $table->string('title');
            $table->json('settings')->nullable();
            $table->timestamps();
        });

        Schema::create('trees', function (Blueprint $table) {
            $table->id();
            $table->string('handle');
            $table->string('type'); // "navigation" or "collection"
            $table->string('locale');
            $table->json('tree');
            $table->json('settings')->nullable();
            $table->timestamps();

            $table->unique(['handle', 'type', 'locale']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('trees');
        Schema::dropIfExists('navigations');
    }
};
