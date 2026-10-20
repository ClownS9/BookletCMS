<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Inserts: the loose pages tucked into the book (galleries, events…).
 * The code lives in inserts/{name}/; these tables keep whether each one is
 * switched on, its settings, and the records it keeps.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inserts', function (Blueprint $table) {
            $table->string('key', 60)->primary();
            $table->boolean('enabled')->default(false);
            $table->json('settings')->nullable();
            $table->string('version', 30)->nullable();
            $table->timestamp('torn_out_at')->nullable(); // it broke while loading: kept off until "Try again"
            $table->text('error')->nullable();            // the last thing that went wrong
            $table->timestamp('error_at')->nullable();
            $table->timestamps();
        });

        Schema::create('insert_records', function (Blueprint $table) {
            $table->id();
            $table->string('insert', 60);
            $table->string('kind', 60);
            $table->string('slug', 190)->nullable();
            $table->json('data');
            $table->integer('sort')->default(0);
            $table->timestamps();
            $table->index(['insert', 'kind', 'sort']);
            $table->unique(['insert', 'kind', 'slug']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('insert_records');
        Schema::dropIfExists('inserts');
    }
};
