<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('workspaces', function (Blueprint $table) {
            $table->string('git_remote_url', 500)->nullable()->after('profile');
            $table->string('git_remote_branch', 120)->nullable()->after('git_remote_url');
            $table->text('git_token')->nullable()->after('git_remote_branch');
        });

        Schema::create('settings', function (Blueprint $table) {
            $table->id();
            $table->string('key', 80)->unique();
            $table->text('value')->nullable();
            $table->boolean('is_secret')->default(false);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('settings');
        Schema::table('workspaces', function (Blueprint $table) {
            $table->dropColumn(['git_remote_url', 'git_remote_branch', 'git_token']);
        });
    }
};
