<?php

namespace App\Services;

use App\Models\Workspace;
use InvalidArgumentException;

class RepoPath
{
    public function __construct(private readonly WorkspaceFilesystem $filesystem) {}

    public function repo(Workspace $workspace): string
    {
        return $this->filesystem->pathFor($workspace->id).'/repo';
    }

    public function resolve(Workspace $workspace, string $relative): string
    {
        $relative = str_replace('\\', '/', $relative);
        $relative = ltrim($relative, '/');

        if ($relative === '' || str_contains($relative, "\0") || str_contains($relative, '..')) {
            throw new InvalidArgumentException('Invalid path.');
        }

        if (str_starts_with($relative, '.git/') || $relative === '.git') {
            throw new InvalidArgumentException('The .git directory is not writable through the file API.');
        }

        $repo = $this->repo($workspace);
        $full = $repo.'/'.$relative;
        $repoReal = realpath($repo) ?: $repo;
        $parent = dirname($full);
        $parentReal = is_dir($parent) ? (realpath($parent) ?: $parent) : $parent;

        if (! str_starts_with($parentReal, $repoReal) && ! str_starts_with($parent, $repo)) {
            throw new InvalidArgumentException('Path escapes the workspace repository.');
        }

        if (is_file($full) || is_dir($full)) {
            $real = realpath($full);
            if ($real !== false && ! str_starts_with($real, $repoReal)) {
                throw new InvalidArgumentException('Path escapes the workspace repository.');
            }
        }

        return $full;
    }
}
