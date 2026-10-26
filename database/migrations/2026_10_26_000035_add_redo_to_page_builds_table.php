<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * What Undo took back, so Redo can bring it again (until the next change).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('page_builds', function (Blueprint $table) {
            $table->json('redo')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('page_builds', function (Blueprint $table) {
            $table->dropColumn('redo');
        });
    }
};
