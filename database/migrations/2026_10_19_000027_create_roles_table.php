<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Custom roles ("library cards"): each acts like a writer or an editor,
 * then narrows what it opens, chapter by chapter.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('roles', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();          // what users.role holds, e.g. guest_writer
            $table->string('name');
            $table->string('about')->nullable();
            $table->string('tone')->default('sky');
            $table->string('base')->default('writer'); // writer | editor: how it acts everywhere else
            $table->json('chapters')->nullable();     // {posts: write, pages: none, …}
            $table->boolean('media')->default(true);   // may add and change files in Media
            $table->boolean('stacy')->default(true);   // may talk to Stacy
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('roles');
    }
};
