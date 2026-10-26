<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Passkeys: signing in with Face ID, Touch ID, Windows Hello or a security key.
 * Only the public half of each key is kept; the private half never leaves the device.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('passkeys', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name', 80);
            $table->string('credential_id', 512)->unique();
            $table->text('public_key');
            $table->integer('alg');
            $table->unsignedBigInteger('sign_count')->default(0);
            $table->json('transports')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('passkeys');
    }
};
