<?php

namespace App\Services;

use App\Enums\BuildStatus;
use App\Models\Build;
use App\Models\User;
use App\Models\Workspace;
use RuntimeException;

class QuotaService
{
    public function __construct(private readonly SettingsService $settings) {}

    public function assertCanCreateWorkspace(User $user): void
    {
        $max = (int) $this->settings->get('quota_workspaces', config('studio.quota_workspaces', 20));
        $count = $user->workspaces()->active()->count();
        if ($count >= $max) {
            throw new RuntimeException('Workspace quota reached ('.$max.').');
        }
    }

    public function assertCanQueueBuild(User $user, ?Workspace $workspace = null): void
    {
        $perHour = (int) $this->settings->get('quota_builds_per_hour', config('studio.quota_builds_per_hour', 20));
        $recent = Build::query()
            ->where('user_id', $user->id)
            ->where('created_at', '>=', now()->subHour())
            ->count();
        if ($recent >= $perHour) {
            throw new RuntimeException('Build quota reached ('.$perHour.' per hour).');
        }

        if ($workspace) {
            $this->assertWorkspaceSize($workspace);
        }
    }

    public function assertWorkspaceSize(Workspace $workspace): void
    {
        $max = (int) config('studio.quota_workspace_bytes', 2147483648);
        $root = app(WorkspaceFilesystem::class)->pathFor($workspace->id);
        if (! is_dir($root)) {
            return;
        }

        $size = 0;
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($iterator as $file) {
            if ($file->isFile()) {
                $size += (int) $file->getSize();
            }
            if ($size > $max) {
                throw new RuntimeException('Workspace exceeds the '.number_format($max / 1024 / 1024).' MB size quota.');
            }
        }
    }

    /**
     * @return array{workspaces: int, workspace_limit: int, builds_last_hour: int, build_limit: int, running: int}
     */
    public function snapshot(User $user): array
    {
        return [
            'workspaces' => $user->workspaces()->active()->count(),
            'workspace_limit' => (int) $this->settings->get('quota_workspaces', config('studio.quota_workspaces', 20)),
            'builds_last_hour' => Build::query()->where('user_id', $user->id)->where('created_at', '>=', now()->subHour())->count(),
            'build_limit' => (int) $this->settings->get('quota_builds_per_hour', config('studio.quota_builds_per_hour', 20)),
            'running' => Build::query()->where('status', BuildStatus::Running->value)->count(),
        ];
    }
}
