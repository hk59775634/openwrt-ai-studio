<?php

namespace App\Services;

use App\Models\ConfigSnapshot;
use App\Models\User;
use App\Models\Workspace;
use InvalidArgumentException;

class ConfigManager
{
    public function __construct(
        private readonly DeviceCatalog $devices,
        private readonly FileService $files,
        private readonly WorkspaceFilesystem $filesystem,
    ) {}

    /**
     * @param  array{name: string, target?: string, subtarget?: string, profile?: string, content?: string}  $payload
     */
    public function save(Workspace $workspace, User $user, array $payload): ConfigSnapshot
    {
        $preset = $this->devices->resolve(
            $payload['target'] ?? $workspace->target,
            $payload['subtarget'] ?? $workspace->subtarget,
            $payload['profile'] ?? $workspace->profile,
        );
        $content = $payload['content'] ?? $this->currentContent($workspace, $preset);
        $sha = hash('sha256', $content);

        $workspace->update(array_filter([
            'target' => $preset['target'],
            'subtarget' => $preset['subtarget'],
            'profile' => $preset['profile'],
            'openwrt_revision' => $preset['revision'] ?? null,
        ]));
        $this->writeTargetMeta($workspace, $preset);

        return ConfigSnapshot::query()->create([
            'workspace_id' => $workspace->id,
            'user_id' => $user->id,
            'name' => $payload['name'],
            'target' => $preset['target'],
            'subtarget' => $preset['subtarget'],
            'profile' => $preset['profile'],
            'openwrt_revision' => $workspace->openwrt_revision,
            'content' => $content,
            'sha256' => $sha,
        ]);
    }

    public function apply(Workspace $workspace, ConfigSnapshot $snapshot): void
    {
        if ($snapshot->workspace_id !== $workspace->id) {
            throw new InvalidArgumentException('Snapshot does not belong to this workspace.');
        }

        $this->files->write($workspace, '.config', $snapshot->content);
        $workspace->update([
            'target' => $snapshot->target,
            'subtarget' => $snapshot->subtarget,
            'profile' => $snapshot->profile,
            'openwrt_revision' => $snapshot->openwrt_revision ?: $workspace->openwrt_revision,
        ]);
        $this->writeTargetMeta($workspace, $this->devices->resolve($snapshot->target, $snapshot->subtarget, $snapshot->profile));
    }

    public function delete(Workspace $workspace, ConfigSnapshot $snapshot): void
    {
        if ($snapshot->workspace_id !== $workspace->id) {
            throw new InvalidArgumentException('Snapshot does not belong to this workspace.');
        }

        $snapshot->delete();
    }

    /**
     * @return array{left: string, right: string, added: list<string>, removed: list<string>}
     */
    public function compare(ConfigSnapshot $left, ConfigSnapshot $right): array
    {
        $a = preg_split("/\r\n|\n|\r/", $left->content) ?: [];
        $b = preg_split("/\r\n|\n|\r/", $right->content) ?: [];

        return [
            'left' => $left->name,
            'right' => $right->name,
            'removed' => array_values(array_diff($a, $b)),
            'added' => array_values(array_diff($b, $a)),
        ];
    }

    /**
     * @param  array{target: string, subtarget: string, profile: string, arch: string, label: string}  $preset
     */
    public function writeGenerated(Workspace $workspace, array $preset): string
    {
        $revision = $preset['revision'] ?? $workspace->openwrt_revision;
        $content = $this->devices->configFor($preset, (string) $revision);
        $this->files->write($workspace, '.config', $content);
        $workspace->update([
            'target' => $preset['target'],
            'subtarget' => $preset['subtarget'],
            'profile' => $preset['profile'],
            'openwrt_revision' => $revision,
        ]);
        $this->writeTargetMeta($workspace, $preset);

        return $content;
    }

    /**
     * @param  array{target: string, subtarget: string, profile: string, arch: string, label: string}  $preset
     */
    private function currentContent(Workspace $workspace, array $preset): string
    {
        try {
            return $this->files->read($workspace, '.config')['content'];
        } catch (InvalidArgumentException) {
            return $this->devices->configFor($preset, $workspace->openwrt_revision);
        }
    }

    /**
     * @param  array{target: string, subtarget: string, profile: string, arch: string, label: string}  $preset
     */
    private function writeTargetMeta(Workspace $workspace, array $preset): void
    {
        $path = $this->filesystem->pathFor($workspace->id).'/.workspace/target.json';
        file_put_contents($path, json_encode([
            'target' => $preset['target'],
            'subtarget' => $preset['subtarget'],
            'profile' => $preset['profile'],
            'arch' => $preset['arch'],
            'label' => $preset['label'],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");
    }
}
