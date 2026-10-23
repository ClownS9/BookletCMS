<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Keys for the read-only content API. Only a fingerprint of each key is kept.
        Schema::create('api_keys', function (Blueprint $table) {
            $table->id();
            $table->string('name', 80);
            $table->string('key_hash', 64)->unique();
            $table->string('last4', 4);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('last_used_at')->nullable();
            $table->unsignedBigInteger('uses')->default(0);
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('api_keys');
    }
};
