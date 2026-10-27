<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Webhooks: "when this happens, call this address". Each send is kept for a
 * while (and tried again when the other end doesn't answer).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('webhooks', function (Blueprint $table) {
            $table->id();
            $table->string('name', 80);
            $table->text('url');
            $table->string('format', 16)->default('json');
            $table->json('events');
            $table->json('collections')->nullable();
            $table->text('secret');
            $table->boolean('active')->default(true);
            $table->unsignedSmallInteger('last_status')->nullable();
            $table->timestamp('last_sent_at')->nullable();
            $table->unsignedInteger('failures')->default(0);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('webhook_deliveries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('webhook_id')->constrained()->cascadeOnDelete();
            $table->string('event', 40);
            $table->string('subject', 80)->nullable();
            $table->json('payload');
            $table->string('status', 12)->default('pending');
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->unsignedSmallInteger('response_code')->nullable();
            $table->string('response_note', 300)->nullable();
            $table->timestamp('due_at');
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();
            $table->index(['status', 'due_at']);
            $table->index(['webhook_id', 'event', 'subject', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('webhook_deliveries');
        Schema::dropIfExists('webhooks');
    }
};
