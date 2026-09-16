<?php

namespace App\Services;

use App\Enums\BuildStatus;
use App\Models\Artifact;
use App\Models\Build;
use App\Models\Workspace;
use Illuminate\Support\Facades\File;
use InvalidArgumentException;
use RuntimeException;

class ReleaseAssetCollector
{
    public function __construct(
        private readonly ArtifactStore $artifacts,
        private readonly RepoPath $paths,
    ) {}

    /**
     * @param  list<int>|null  $artifactIds  null = latest IPK + firmware; [] is rejected
     * @return list<string>
     */
    public function collect(
        Workspace $workspace,
        ?array $artifactIds,
        string $tag,
        string $owner,
        string $repo,
        string $notes,
    ): array {
        $selected = $this->resolveArtifacts($workspace, $artifactIds);
        if ($selected === []) {
            throw new InvalidArgumentException('Select at least one IPK or firmware file from Build files.');
        }

        $paths = [];
        $names = [];
        $ipk = null;
        $firmware = null;
        foreach ($selected as $artifact) {
            $path = $this->materialize($artifact->build, $artifact);
            if ($path === null) {
                throw new InvalidArgumentException('Build file '.$artifact->name.' is missing on disk.');
            }
            $base = basename($path);
            if (isset($names[$base])) {
                throw new InvalidArgumentException('Two selected files are named '.$base.'. Pick one of each name.');
            }
            $names[$base] = true;
            $paths[] = $path;
            if ($this->isIpk($artifact->name) && $ipk === null) {
                $ipk = $path;
            }
            if ($this->isFirmware($artifact->name) && $firmware === null) {
                $firmware = $path;
            }
        }

        $manifest = $this->writeManifest(
            $this->paths->repo($workspace),
            $tag,
            $owner,
            $repo,
            $notes,
            $ipk,
            $firmware,
            $workspace,
        );
        if ($manifest !== null) {
            array_unshift($paths, $manifest);
        }

        return $paths;
    }

    /**
     * @param  list<int>|null  $artifactIds
     * @return list<Artifact>
     */
    public function resolveArtifacts(Workspace $workspace, ?array $artifactIds): array
    {
        if ($artifactIds === []) {
            return [];
        }

        if ($artifactIds === null) {
            $auto = [];
            $ipk = $this->latestArtifact($workspace, fn (string $name) => $this->isIpk($name));
            $firmware = $this->latestArtifact($workspace, fn (string $name) => strcasecmp($name, 'openwrt-ramips-mt7628-mt7628-squashfs-sysupgrade.bin') === 0)
                ?? $this->latestArtifact($workspace, fn (string $name) => $this->isFirmware($name));
            if ($ipk) {
                $auto[] = $ipk;
            }
            if ($firmware) {
                $auto[] = $firmware;
            }

            return $auto;
        }

        $ids = array_values(array_unique(array_map('intval', $artifactIds)));
        $found = Artifact::query()
            ->whereIn('id', $ids)
            ->with('build')
            ->get()
            ->keyBy('id');

        $ordered = [];
        foreach ($ids as $id) {
            $artifact = $found->get($id);
            if (! $artifact || $artifact->build?->workspace_id !== $workspace->id) {
                throw new InvalidArgumentException('Build file '.$id.' is not in this workspace.');
            }
            if ($artifact->build->status !== BuildStatus::Success) {
                throw new InvalidArgumentException('Build file '.$artifact->name.' is not from a successful build.');
            }
            if (! $this->isIpk($artifact->name) && ! $this->isFirmware($artifact->name)) {
                throw new InvalidArgumentException($artifact->name.' is not an IPK or firmware image.');
            }
            $ordered[] = $artifact;
        }

        return $ordered;
    }

    public function isIpk(string $name): bool
    {
        return str_ends_with(strtolower($name), '.ipk');
    }

    public function isFirmware(string $name): bool
    {
        $name = strtolower($name);
        if (str_contains($name, 'kernel.bin') || str_contains($name, 'vmlinux')) {
            return false;
        }

        return (bool) preg_match('/(sysupgrade\.bin|\.img\.gz|\.img|\.itb)$/i', $name)
            || (bool) preg_match('/\.bin$/i', $name);
    }

    /**
     * @param  callable(string): bool  $match
     */
    public function latestArtifact(Workspace $workspace, callable $match): ?Artifact
    {
        $builds = Build::query()
            ->where('workspace_id', $workspace->id)
            ->where('status', BuildStatus::Success)
            ->with(['artifacts' => fn ($query) => $query->orderByDesc('id')])
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get();

        foreach ($builds as $build) {
            foreach ($build->artifacts as $artifact) {
                if ($match($artifact->name)) {
                    return $artifact;
                }
            }
        }

        return null;
    }

    /**
     * @param  callable(string): bool  $match
     */
    public function latestFile(Workspace $workspace, callable $match): ?string
    {
        $artifact = $this->latestArtifact($workspace, $match);

        return $artifact ? $this->materialize($artifact->build, $artifact) : null;
    }

    private function materialize(Build $build, Artifact $artifact): ?string
    {
        $local = $this->artifacts->localPath($build, $artifact);
        if ($local) {
            return $local;
        }
        try {
            $dir = sys_get_temp_dir().'/studio-release-'.$build->id;
            File::ensureDirectoryExists($dir, 0750);
            $path = $dir.'/'.$artifact->name;
            file_put_contents($path, $this->artifacts->stream($build, $artifact));

            return is_file($path) ? $path : null;
        } catch (RuntimeException) {
            return null;
        }
    }

    private function writeManifest(
        string $repo,
        string $tag,
        string $owner,
        string $repoName,
        string $notes,
        ?string $ipk,
        ?string $firmware,
        Workspace $workspace,
    ): ?string {
        if ($ipk === null && $firmware === null) {
            return null;
        }

        $version = ltrim($tag, 'v');
        if ($ipk && preg_match('/_([^_]+)_(?:all|[a-z0-9_]+)\.ipk$/i', basename($ipk), $match)) {
            $version = $match[1];
        }
        $slug = strtolower($owner.'/'.$repoName);
        $payload = [
            'tag' => $tag,
            'version' => $version,
            'notes' => $notes,
        ];

        if ($ipk !== null) {
            $entry = $this->fileEntry($ipk, $slug, $tag);
            $payload['packages'] = [$entry];
            $payload['vps000'] = [
                'version' => $version,
                'filename' => $entry['filename'],
                'url' => $entry['url'],
                'sha256' => $entry['sha256'],
                'md5' => $entry['md5'],
                'size' => $entry['size'],
            ];
        }

        if ($firmware !== null) {
            $entry = $this->fileEntry($firmware, $slug, $tag);
            $imageVersion = $version;
            $makefile = $repo.'/Makefile';
            if (is_file($makefile) && preg_match('/^PKG_IMAGE_VERSION:=(.+)$/m', (string) file_get_contents($makefile), $match)) {
                $imageVersion = trim($match[1]);
            }
            $board = $workspace->subtarget ?: $workspace->profile ?: 'generic';
            $payload['firmware'] = [
                'version' => $imageVersion,
                'board' => $board,
                'filename' => $entry['filename'],
                'url' => $entry['url'],
                'sha256' => $entry['sha256'],
                'md5' => $entry['md5'],
                'size' => $entry['size'],
            ];
        }

        $out = $repo.'/release';
        File::ensureDirectoryExists($out, 0750);
        $path = $out.'/manifest.json';
        file_put_contents($path, json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n");

        return $path;
    }

    /**
     * @return array{filename: string, url: string, sha256: string, md5: string, size: int}
     */
    private function fileEntry(string $path, string $slug, string $tag): array
    {
        $name = basename($path);

        return [
            'filename' => $name,
            'url' => 'https://github.com/'.$slug.'/releases/download/'.$tag.'/'.$name,
            'sha256' => (string) hash_file('sha256', $path),
            'md5' => (string) hash_file('md5', $path),
            'size' => (int) filesize($path),
        ];
    }
}
