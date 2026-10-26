<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Pages saved as layouts, to start other pages from (beside the ones
 * Booklet comes with).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('page_layouts', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->json('sections');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('page_layouts');
    }
};
