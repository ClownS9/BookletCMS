<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Which change a built page is at, and who made it: so two people
 * building the same page never undo each other's work without knowing.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('page_builds', function (Blueprint $table) {
            $table->unsignedInteger('rev')->default(0);
            $table->foreignId('edited_by')->nullable()->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('page_builds', function (Blueprint $table) {
            $table->dropConstrainedForeignId('edited_by');
            $table->dropColumn('rev');
        });
    }
};
