<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A simple daily visit count for the dashboard: one row per day, no
 * cookies and nothing about who visited.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('site_visits', function (Blueprint $table) {
            $table->date('day')->primary();
            $table->unsignedInteger('views')->default(0);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('site_visits');
    }
};
