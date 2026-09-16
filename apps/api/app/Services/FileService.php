<?php

namespace App\Services;

use App\Enums\WorkspaceStatus;
use App\Models\Workspace;
use Illuminate\Support\Facades\File;
use InvalidArgumentException;

class FileService
{
    public function __construct(
        private readonly RepoPath $paths,
        private readonly GitService $git,
    ) {}

    /**
     * @return list<array{name: string, path: string, type: string, children?: list<array<string, mixed>>}>
     */
    public function tree(Workspace $workspace): array
    {
        $this->git->ensureRepository($workspace);
        $repo = $this->paths->repo($workspace);

        return $this->scan($repo, $repo);
    }

    public function read(Workspace $workspace, string $path): array
    {
        $this->git->ensureRepository($workspace);
        $full = $this->paths->resolve($workspace, $path);
        if (! is_file($full)) {
            throw new InvalidArgumentException('File not found.');
        }
        if (filesize($full) > 1024 * 1024) {
            throw new InvalidArgumentException('File is larger than 1 MB.');
        }
        $content = File::get($full);
        if (! mb_check_encoding($content, 'UTF-8') && ! mb_check_encoding($content, 'ASCII')) {
            throw new InvalidArgumentException('Binary files cannot be opened in the editor.');
        }

        return [
            'path' => $path,
            'content' => $content,
            'size' => filesize($full),
        ];
    }

    public function write(Workspace $workspace, string $path, string $content): void
    {
        $this->git->ensureRepository($workspace);
        $full = $this->paths->resolve($workspace, $path);
        File::ensureDirectoryExists(dirname($full), 0750);
        File::put($full, $content);
        if ($workspace->status === WorkspaceStatus::Ready) {
            $workspace->update(['status' => WorkspaceStatus::Coding]);
        }
    }

    public function create(Workspace $workspace, string $path, string $type): void
    {
        $this->git->ensureRepository($workspace);
        $full = $this->paths->resolve($workspace, $path);
        if (file_exists($full)) {
            throw new InvalidArgumentException('Path already exists.');
        }
        if ($type === 'dir') {
            File::ensureDirectoryExists($full, 0750);
        } else {
            File::ensureDirectoryExists(dirname($full), 0750);
            File::put($full, '');
        }
    }

    /**
     * @return list<array{name: string, path: string, type: string, children?: mixed}>
     */
    private function scan(string $root, string $dir): array
    {
        $items = [];
        foreach (scandir($dir) ?: [] as $name) {
            if ($name === '.' || $name === '..' || $name === '.git') {
                continue;
            }
            $child = $dir.'/'.$name;
            $relative = ltrim(str_replace($root, '', $child), '/');
            if (is_dir($child)) {
                $items[] = [
                    'name' => $name,
                    'path' => $relative,
                    'type' => 'dir',
                    'children' => $this->scan($root, $child),
                ];
            } elseif (is_file($child)) {
                $items[] = [
                    'name' => $name,
                    'path' => $relative,
                    'type' => 'file',
                ];
            }
        }

        usort($items, fn ($a, $b) => [$a['type'] === 'file', $a['name']] <=> [$b['type'] === 'file', $b['name']]);

        return $items;
    }
}
