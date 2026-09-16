<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_sessions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('agent_type', 32)->default('studio');
            $table->string('model');
            $table->string('status', 32)->default('idle');
            $table->string('sandbox_id')->nullable();
            $table->timestamps();

            $table->index(['workspace_id', 'created_at']);
        });

        Schema::create('ai_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('ai_session_id')->constrained('ai_sessions')->cascadeOnDelete();
            $table->string('role', 16);
            $table->longText('content')->nullable();
            $table->json('tool_calls')->nullable();
            $table->string('tool_call_id')->nullable();
            $table->timestamps();
        });

        Schema::create('ai_operations', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('ai_session_id')->constrained('ai_sessions')->cascadeOnDelete();
            $table->string('tool');
            $table->json('input')->nullable();
            $table->longText('output')->nullable();
            $table->string('status', 16)->default('ok');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_operations');
        Schema::dropIfExists('ai_messages');
        Schema::dropIfExists('ai_sessions');
    }
};
