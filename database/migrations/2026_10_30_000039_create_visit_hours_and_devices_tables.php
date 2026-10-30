<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * When people read (the hour, in the site's own time) and on what (a phone,
 * a computer or a tablet). Plain counts per day: nothing about who.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('visit_hours', function (Blueprint $table) {
            $table->date('day');
            $table->unsignedTinyInteger('hour');
            $table->unsignedInteger('views')->default(0);
            $table->primary(['day', 'hour']);
        });

        Schema::create('device_stats', function (Blueprint $table) {
            $table->date('day');
            $table->string('device', 12);
            $table->unsignedInteger('views')->default(0);
            $table->primary(['day', 'device']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('device_stats');
        Schema::dropIfExists('visit_hours');
    }
};
