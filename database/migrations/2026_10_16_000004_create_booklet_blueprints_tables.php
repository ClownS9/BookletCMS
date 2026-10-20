<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Blueprints and fieldsets: the field layouts that decide what lives in each
 * item's JSON `data` column. (Statamic: resources/blueprints, resources/fieldsets)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('blueprints', function (Blueprint $table) {
            $table->id();
            $table->string('namespace')->default('');
            $table->string('handle');
            $table->json('data');
            $table->timestamps();

            $table->unique(['namespace', 'handle']);
        });

        Schema::create('fieldsets', function (Blueprint $table) {
            $table->id();
            $table->string('handle')->unique();
            $table->json('data');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fieldsets');
        Schema::dropIfExists('blueprints');
    }
};
