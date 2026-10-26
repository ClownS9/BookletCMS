<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Every time a built page (or the blog page) is published, what went on the
 * site is kept: to look at again, and to put back.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('page_build_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('page_build_id')->constrained('page_builds')->cascadeOnDelete();
            $table->json('sections');
            $table->timestamp('published_at');
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->boolean('scheduled')->default(false);
            $table->index(['page_build_id', 'published_at']);
        });

        // What's on the site now is the first one kept.
        foreach (DB::table('page_builds')->whereNotNull('published')->get(['id', 'published', 'published_at', 'updated_at']) as $build) {
            DB::table('page_build_versions')->insert([
                'page_build_id' => $build->id, 'sections' => $build->published,
                'published_at' => $build->published_at ?? $build->updated_at ?? now(), 'user_id' => null, 'scheduled' => false,
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('page_build_versions');
    }
};
