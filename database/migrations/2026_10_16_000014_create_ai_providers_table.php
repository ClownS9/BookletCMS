<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The AI services Stacy can think with. Keys are encrypted with APP_KEY and
 * live only in the database, never in content files.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_providers', function (Blueprint $table) {
            $table->id();
            $table->string('service')->unique();   // anthropic, openai, gemini, groq, openrouter, ollama, custom
            $table->boolean('enabled')->default(false);
            $table->boolean('is_default')->default(false);
            $table->text('api_key')->nullable();    // encrypted
            $table->string('base_url')->nullable(); // for Ollama, OpenRouter, custom
            $table->string('default_model')->nullable();
            $table->json('models')->nullable();     // fetched list
            $table->timestamp('models_fetched_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_providers');
    }
};
