<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Pages, posts and the blog page built of sections, on the page itself: for
 * each one, the draft being built, what's published, Undo and any schedule.
 * The page or post itself stays where it is (in its chapter); this is only
 * how it's drawn.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('page_builds', function (Blueprint $table) {
            $table->id();
            $table->string('target')->unique(); // an entry's id, or "blog"
            $table->json('draft')->nullable();
            $table->json('published')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->json('scheduled')->nullable(); // ['at' => …, 'sections' => […]]
            $table->timestamp('scheduled_at')->nullable()->index();
            $table->json('history')->nullable();
            $table->json('history_mark')->nullable();
            $table->boolean('fresh')->default(false); // made here, not from a page's own words
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('page_builds');
    }
};
