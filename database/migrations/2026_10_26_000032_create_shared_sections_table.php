<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Sections used on more than one page (opening hours, a call to action…):
 * changed once, they change everywhere they're used when published.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shared_sections', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('type');
            $table->json('draft');                 // ['fields' => …, 'look' => …]
            $table->json('published')->nullable(); // what readers see
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shared_sections');
    }
};
