<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Readers can ask to hear about replies to their note (notify), and each
 * reply remembers that the emails about it have gone (alerted_at).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('comments', function (Blueprint $table) {
            $table->boolean('notify')->default(false);
            $table->timestamp('alerted_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('comments', function (Blueprint $table) {
            $table->dropColumn(['notify', 'alerted_at']);
        });
    }
};
