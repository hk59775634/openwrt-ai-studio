<?php

namespace App\Services;

use App\Enums\ProjectType;
use App\Enums\WorkspaceStatus;
use App\Models\AuditLog;
use App\Models\Project;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\DB;

class WorkspaceManager
{
    public function __construct(
        private readonly WorkspaceFilesystem $filesystem,
        private readonly QuotaService $quotas,
        private readonly DeviceCatalog $devices,
    ) {}

    /**
     * @param  array{name: string, type: string, openwrt_revision?: string, target?: string, subtarget?: string, profile?: string, git_url?: string, git_branch?: string, git_token?: string}  $payload
     */
    public function create(User $user, array $payload): Workspace
    {
        $this->quotas->assertCanCreateWorkspace($user);

        return DB::transaction(function () use ($user, $payload) {
            $type = ProjectType::from($payload['type']);
            $preset = $this->devices->resolve(
                $payload['target'] ?? null,
                $payload['subtarget'] ?? null,
                $payload['profile'] ?? null,
            );
            $project = Project::query()->create([
                'user_id' => $user->id,
                'name' => $payload['name'],
                'type' => $type,
            ]);

            $revision = $payload['openwrt_revision'] ?? config('studio.default_openwrt_revision');
            if (! empty($preset['source'])) {
                $revision = $preset['revision'] ?? $preset['source'];
            }

            $workspace = Workspace::query()->create([
                'user_id' => $user->id,
                'project_id' => $project->id,
                'name' => $payload['name'],
                'type' => $type,
                'openwrt_revision' => $revision,
                'status' => WorkspaceStatus::Initializing,
                'path' => $this->filesystem->root().'/_pending',
                'target' => $preset['target'],
                'subtarget' => $preset['subtarget'],
                'profile' => $preset['profile'],
            ]);
            $workspace->update(['path' => $this->filesystem->pathFor($workspace->id)]);

            try {
                $gitUrl = trim((string) ($payload['git_url'] ?? ''));
                if ($gitUrl !== '') {
                    $this->filesystem->initializeFromGit(
                        $workspace,
                        $gitUrl,
                        $payload['git_branch'] ?? null,
                        $payload['git_token'] ?? null,
                    );
                } else {
                    $this->filesystem->initialize($workspace);
                }
            } catch (\Throwable $e) {
                $this->filesystem->delete($workspace);
                throw $e;
            }
            $workspace->update(['status' => WorkspaceStatus::Ready]);
            $workspace->refresh();

            $this->audit($user, 'workspace.create', $workspace->id, [
                'type' => $type->value,
                'git_url' => $workspace->git_remote_url,
            ]);

            return $workspace->load('project');
        });
    }

    public function clone(User $user, Workspace $source, string $name): Workspace
    {
        $this->quotas->assertCanCreateWorkspace($user);

        return DB::transaction(function () use ($user, $source, $name) {
            $project = Project::query()->create([
                'user_id' => $user->id,
                'name' => $name,
                'type' => $source->type,
            ]);

            $workspace = Workspace::query()->create([
                'user_id' => $user->id,
                'project_id' => $project->id,
                'name' => $name,
                'type' => $source->type,
                'openwrt_revision' => $source->openwrt_revision,
                'status' => WorkspaceStatus::Initializing,
                'path' => $this->filesystem->root().'/_pending',
                'target' => $source->target,
                'subtarget' => $source->subtarget,
                'profile' => $source->profile,
                'git_remote_url' => $source->git_remote_url,
                'git_remote_branch' => $source->git_remote_branch,
                'git_token' => $source->git_token,
            ]);
            $workspace->update(['path' => $this->filesystem->pathFor($workspace->id)]);

            $this->filesystem->cloneFrom($source, $workspace);
            $workspace->update(['status' => WorkspaceStatus::Ready]);
            $workspace->refresh();

            $this->audit($user, 'workspace.clone', $workspace->id, [
                'source' => $source->id,
            ]);

            return $workspace->load('project');
        });
    }

    public function delete(User $user, Workspace $workspace): void
    {
        DB::transaction(function () use ($user, $workspace) {
            $id = $workspace->id;
            $this->filesystem->delete($workspace);
            $workspace->update(['status' => WorkspaceStatus::Archived]);
            $workspace->delete();
            $workspace->project?->delete();

            $this->audit($user, 'workspace.delete', $id);
        });
    }

    /**
     * @param  array<string, mixed>|null  $metadata
     */
    private function audit(User $user, string $action, string $resource, ?array $metadata = null): void
    {
        AuditLog::query()->create([
            'user_id' => $user->id,
            'action' => $action,
            'resource' => $resource,
            'metadata' => $metadata,
        ]);
    }
}
