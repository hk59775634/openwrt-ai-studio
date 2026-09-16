<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('workspaces', function (Blueprint $table) {
            $table->string('target')->nullable();
            $table->string('subtarget')->nullable();
            $table->string('profile')->nullable();
        });

        Schema::create('config_snapshots', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('target')->nullable();
            $table->string('subtarget')->nullable();
            $table->string('profile')->nullable();
            $table->string('openwrt_revision', 64);
            $table->longText('content');
            $table->string('sha256', 64);
            $table->timestamps();

            $table->index(['workspace_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('config_snapshots');
        Schema::table('workspaces', function (Blueprint $table) {
            $table->dropColumn(['target', 'subtarget', 'profile']);
        });
    }
};
