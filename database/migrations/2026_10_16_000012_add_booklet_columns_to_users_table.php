<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Users stay in Laravel's own table (as in Statamic's database users), with the
 * extra fields Booklet's People and Account screens need.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('role')->default('writer')->after('password'); // admin | editor | writer
            $table->string('byline')->nullable()->after('role');
            $table->text('bio')->nullable()->after('byline');
            $table->string('avatar')->nullable()->after('bio');
            $table->json('preferences')->nullable()->after('avatar'); // night reading, notifications…
            $table->timestamp('last_seen_at')->nullable()->index()->after('preferences');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['last_seen_at']);
            $table->dropColumn(['role', 'byline', 'bio', 'avatar', 'preferences', 'last_seen_at']);
        });
    }
};
