<?php

namespace App\Services;

use App\Enums\BuildStatus;
use App\Enums\BuildType;
use App\Enums\ProjectType;
use App\Enums\WorkspaceStatus;
use App\Jobs\CompileSdkJob;
use App\Models\AuditLog;
use App\Models\Build;
use App\Models\User;
use App\Models\Workspace;
use InvalidArgumentException;
use RuntimeException;

class BuildManager
{
    public function __construct(
        private readonly GitService $git,
        private readonly PackageResolver $packages,
        private readonly DeviceCatalog $devices,
        private readonly QuotaService $quotas,
        private readonly ArtifactStore $artifacts,
    ) {}

    public function queue(Workspace $workspace, User $user, ?string $requestedType = null): Build
    {
        $this->quotas->assertCanQueueBuild($user, $workspace);
        $type = $this->resolveType($workspace, $requestedType);
        $firmware = $type === BuildType::Firmware;
        $this->git->ensureRepository($workspace);
        $this->git->assertClean($workspace);
        $commit = $this->git->head($workspace);
        if ($commit === '') {
            throw new RuntimeException('Commit your changes before building.');
        }
        $preset = $this->devices->resolve($workspace->target, $workspace->subtarget, $workspace->profile);

        $packageName = $preset['profile'];
        $command = 'make defconfig && make -j$(nproc) V=s';
        $cpu = (int) config('studio.build_cpus', 2);
        $memory = (string) config('studio.build_memory', '1g');
        $timeout = (int) config('studio.build_timeout', 600);
        if (! $firmware) {
            $package = $this->packages->resolve($workspace);
            $packageName = $package['name'];
            $command = 'make package/'.$package['name'].'/{download,prepare,compile} V=s -j$(nproc) && make package/index';
            $cpu = (int) config('studio.build_firmware_cpus', 16);
            $memory = (string) config('studio.build_firmware_memory', '16g');
            $timeout = (int) config('studio.build_firmware_timeout', 7200);
        } else {
            $cpu = (int) config('studio.build_firmware_cpus', 16);
            $memory = (string) config('studio.build_firmware_memory', '16g');
            $timeout = (int) config('studio.build_firmware_timeout', 7200);
        }

        $build = Build::query()->create([
            'workspace_id' => $workspace->id,
            'user_id' => $user->id,
            'type' => $type,
            'status' => BuildStatus::Queued,
            'package_name' => $packageName,
            'git_commit' => $commit,
            'openwrt_revision' => $workspace->openwrt_revision,
            'architecture' => $preset['arch'],
            'target' => $preset['target'].'/'.$preset['subtarget'],
            'command' => $command,
            'cpu_limit' => $cpu,
            'memory_limit' => $memory,
            'timeout' => $timeout,
        ]);

        $workspace->update(['status' => WorkspaceStatus::BuildQueued]);

        AuditLog::query()->create([
            'user_id' => $user->id,
            'action' => 'build.queue',
            'resource' => $build->id,
            'metadata' => [
                'workspace_id' => $workspace->id,
                'type' => $type->value,
                'package' => $packageName,
                'commit' => $commit,
            ],
        ]);

        if (app()->environment('testing') || config('queue.default') === 'sync') {
            CompileSdkJob::dispatchSync($build->id);
        } else {
            CompileSdkJob::dispatch($build->id);
        }

        return $build->fresh(['artifacts', 'logs']);
    }

    public function cancel(Build $build, User $user): Build
    {
        if (! in_array($build->status, [BuildStatus::Queued, BuildStatus::Running], true)) {
            throw new RuntimeException('Only queued or running builds can be canceled.');
        }

        $build->update([
            'status' => BuildStatus::Canceled,
            'finished_at' => now(),
            'error' => 'Canceled by user.',
        ]);
        $build->workspace?->update(['status' => WorkspaceStatus::Failed]);

        AuditLog::query()->create([
            'user_id' => $user->id,
            'action' => 'build.cancel',
            'resource' => $build->id,
        ]);

        return $build->fresh(['artifacts', 'logs']);
    }

    public function delete(Build $build, User $user): void
    {
        if (in_array($build->status, [BuildStatus::Queued, BuildStatus::Running], true)) {
            throw new RuntimeException('Cancel this build before deleting it.');
        }

        $id = $build->id;
        $this->artifacts->forget($build);
        $build->delete();

        AuditLog::query()->create([
            'user_id' => $user->id,
            'action' => 'build.delete',
            'resource' => $id,
        ]);
    }

    private function resolveType(Workspace $workspace, ?string $requestedType): BuildType
    {
        if ($requestedType) {
            $type = BuildType::tryFrom($requestedType);
            if (! $type) {
                throw new InvalidArgumentException('Unknown build type.');
            }
            if ($type === BuildType::Firmware) {
                return $type;
            }
            if (! in_array($workspace->type, [ProjectType::App, ProjectType::Theme, ProjectType::Sdk], true)) {
                throw new InvalidArgumentException('This workspace cannot compile a package IPK.');
            }

            return $type;
        }

        return match ($workspace->type) {
            ProjectType::Firmware, ProjectType::Sdk => BuildType::Firmware,
            ProjectType::Theme => BuildType::Theme,
            default => BuildType::App,
        };
    }
}
