<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('builds', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('type', 32);
            $table->string('status', 32)->default('queued');
            $table->string('package_name');
            $table->string('git_commit', 64);
            $table->string('openwrt_revision', 64);
            $table->string('architecture', 32)->default('x86_64');
            $table->string('target')->nullable();
            $table->string('command')->nullable();
            $table->unsignedInteger('cpu_limit')->default(2);
            $table->string('memory_limit', 16)->default('1g');
            $table->unsignedInteger('timeout')->default(600);
            $table->json('artifact_manifest')->nullable();
            $table->text('error')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['workspace_id', 'created_at']);
        });

        Schema::create('build_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('build_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('sequence');
            $table->string('stream', 16)->default('stdout');
            $table->text('content');
            $table->timestamps();

            $table->unique(['build_id', 'sequence']);
        });

        Schema::create('artifacts', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('build_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('object_key');
            $table->string('sha256', 64);
            $table->unsignedBigInteger('size');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('artifacts');
        Schema::dropIfExists('build_logs');
        Schema::dropIfExists('builds');
    }
};
