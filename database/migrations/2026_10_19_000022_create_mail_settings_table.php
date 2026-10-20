<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * How the book sends email (Settings › Email). One row. The password is
 * encrypted with APP_KEY and lives only here: never in content files or backups.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mail_settings', function (Blueprint $table) {
            $table->id();
            $table->boolean('enabled')->default(false);
            $table->string('service', 40)->default('other'); // gmail, outlook, … (fills in the server details)
            $table->string('host')->nullable();
            $table->unsignedInteger('port')->nullable();
            $table->string('security', 10)->default('tls');   // tls (STARTTLS), ssl, none
            $table->string('username')->nullable();
            $table->text('password')->nullable();            // encrypted
            $table->string('from_address')->nullable();
            $table->string('from_name')->nullable();
            $table->timestamp('tested_at')->nullable();      // the last test email that went through, with these settings
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mail_settings');
    }
};
