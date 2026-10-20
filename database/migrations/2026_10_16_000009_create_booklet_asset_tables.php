<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Asset containers (Media folders on a disk) and the metadata for each file.
 * The files themselves always live on a Laravel disk; only their meta swaps.
 * (Statamic: content/assets/{handle}.yaml, {disk}/.meta/{file}.yaml)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('asset_containers', function (Blueprint $table) {
            $table->id();
            $table->string('handle')->unique();
            $table->string('title');
            $table->string('disk');
            $table->json('settings')->nullable();
            $table->timestamps();
        });

        Schema::create('assets_meta', function (Blueprint $table) {
            $table->id();
            $table->string('container')->index();
            $table->string('folder')->index();
            $table->string('basename');
            $table->string('filename');
            $table->string('extension', 16);
            $table->string('path');
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->unique(['container', 'path']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('assets_meta');
        Schema::dropIfExists('asset_containers');
    }
};
