<?php

namespace App\Services;

use App\Models\AiOperation;
use App\Models\AiSession;
use App\Models\User;
use App\Models\Workspace;
use InvalidArgumentException;
use Throwable;

class AgentToolExecutor
{
    public function __construct(
        private readonly FileService $files,
        private readonly GitService $git,
        private readonly SandboxRuntime $sandbox,
        private readonly RepoPath $paths,
    ) {}

    public function previewTree(Workspace $workspace): string
    {
        try {
            return $this->listFiles($workspace, []);
        } catch (Throwable $e) {
            return '(tree unavailable: '.$e->getMessage().')';
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function definitions(): array
    {
        return [
            $this->fn('list_files', 'List files in the workspace repository.', [
                'path' => ['type' => 'string', 'description' => 'Optional subdirectory relative to the repo root.'],
            ]),
            $this->fn('read_file', 'Read a UTF-8 source file from the workspace.', [
                'path' => ['type' => 'string', 'description' => 'File path relative to the repo root.'],
            ], ['path']),
            $this->fn('write_file', 'Create or replace a UTF-8 source file. Do not write secrets or leave the repo.', [
                'path' => ['type' => 'string'],
                'content' => ['type' => 'string'],
            ], ['path', 'content']),
            $this->fn('git_diff', 'Show the current uncommitted diff.', [
                'path' => ['type' => 'string', 'description' => 'Optional file path.'],
            ]),
            $this->fn('sandbox_exec', 'Run an allowlisted command in the isolated Docker/workspace sandbox.', [
                'argv' => [
                    'type' => 'array',
                    'items' => ['type' => 'string'],
                    'description' => 'Argv only. Allowed: ls, pwd, cat, head, wc, git status|log|diff|branch|show.',
                ],
            ], ['argv']),
        ];
    }

    /**
     * @param  array<string, mixed>  $arguments
     */
    public function execute(AiSession $session, Workspace $workspace, User $user, string $tool, array $arguments): string
    {
        $status = 'ok';
        $output = '';

        try {
            $output = match ($tool) {
                'list_files', 'workspace.list_files' => $this->listFiles($workspace, $arguments),
                'read_file', 'workspace.read_file' => $this->readFile($workspace, $arguments),
                'write_file', 'workspace.write_file' => $this->writeFile($workspace, $arguments),
                'git_diff', 'git.diff' => $this->gitDiff($workspace, $arguments),
                'sandbox_exec' => $this->sandboxExec($workspace, $arguments),
                default => throw new InvalidArgumentException('Unknown tool: '.$tool),
            };
        } catch (Throwable $e) {
            $status = 'error';
            $output = $e->getMessage();
        }

        AiOperation::query()->create([
            'ai_session_id' => $session->id,
            'tool' => $tool,
            'input' => $this->truncateArray($arguments),
            'output' => $this->truncate($output),
            'status' => $status,
        ]);

        return $status === 'ok' ? $output : 'ERROR: '.$output;
    }

    /**
     * @param  array<string, mixed>  $arguments
     */
    private function listFiles(Workspace $workspace, array $arguments): string
    {
        $root = $this->paths->repo($workspace);
        $relative = isset($arguments['path']) && is_string($arguments['path']) && $arguments['path'] !== ''
            ? $arguments['path']
            : '.';
        $dir = $relative === '.' ? $root : $this->paths->resolve($workspace, $relative);
        if (! is_dir($dir)) {
            throw new InvalidArgumentException('Not a directory.');
        }

        $paths = [];
        $this->walk($root, $dir, $paths, 400);

        return implode("\n", $paths);
    }

    /**
     * @param  list<string>  $paths
     */
    private function walk(string $root, string $dir, array &$paths, int $limit): void
    {
        foreach (scandir($dir) ?: [] as $name) {
            if ($name === '.' || $name === '..' || $name === '.git') {
                continue;
            }
            if (count($paths) >= $limit) {
                $paths[] = '...truncated...';

                return;
            }
            $full = $dir.'/'.$name;
            $relative = ltrim(str_replace($root, '', $full), '/');
            if (is_dir($full)) {
                $paths[] = $relative.'/';
                $this->walk($root, $full, $paths, $limit);
            } else {
                $paths[] = $relative;
            }
        }
    }

    /**
     * @param  array<string, mixed>  $arguments
     */
    private function readFile(Workspace $workspace, array $arguments): string
    {
        $path = $this->stringArg($arguments, 'path');
        $file = $this->files->read($workspace, $path);

        return $file['content'];
    }

    /**
     * @param  array<string, mixed>  $arguments
     */
    private function writeFile(Workspace $workspace, array $arguments): string
    {
        $path = $this->stringArg($arguments, 'path');
        $content = $arguments['content'] ?? '';
        if (! is_string($content)) {
            throw new InvalidArgumentException('content must be a string.');
        }
        $this->files->write($workspace, $path, $content);

        return 'Wrote '.$path.' ('.strlen($content).' bytes).';
    }

    /**
     * @param  array<string, mixed>  $arguments
     */
    private function gitDiff(Workspace $workspace, array $arguments): string
    {
        $path = isset($arguments['path']) && is_string($arguments['path']) && $arguments['path'] !== ''
            ? $arguments['path']
            : null;
        $diff = $this->git->diff($workspace, $path);

        return $diff !== '' ? $diff : 'Working tree clean.';
    }

    /**
     * @param  array<string, mixed>  $arguments
     */
    private function sandboxExec(Workspace $workspace, array $arguments): string
    {
        $argv = $arguments['argv'] ?? null;
        if (! is_array($argv) || $argv === []) {
            throw new InvalidArgumentException('argv must be a non-empty array.');
        }
        $argv = array_values(array_map(function ($part) {
            if (! is_string($part)) {
                throw new InvalidArgumentException('argv items must be strings.');
            }

            return $part;
        }, $argv));

        $result = $this->sandbox->exec($workspace, $argv);

        return 'exit '.$result['exit_code']."\n".$result['output'];
    }

    /**
     * @param  array<string, mixed>  $arguments
     */
    private function stringArg(array $arguments, string $key): string
    {
        $value = $arguments[$key] ?? null;
        if (! is_string($value) || $value === '') {
            throw new InvalidArgumentException($key.' is required.');
        }

        return $value;
    }

    /**
     * @param  array<string, array<string, mixed>>  $properties
     * @param  list<string>  $required
     * @return array<string, mixed>
     */
    private function fn(string $name, string $description, array $properties, array $required = []): array
    {
        return [
            'type' => 'function',
            'function' => [
                'name' => $name,
                'description' => $description,
                'parameters' => [
                    'type' => 'object',
                    'properties' => $properties,
                    'required' => $required,
                ],
            ],
        ];
    }

    private function truncate(string $value): string
    {
        return strlen($value) > 8000 ? substr($value, 0, 8000).'…' : $value;
    }

    /**
     * @param  array<string, mixed>  $value
     * @return array<string, mixed>
     */
    private function truncateArray(array $value): array
    {
        foreach ($value as $key => $item) {
            if (is_string($item) && strlen($item) > 2000) {
                $value[$key] = substr($item, 0, 2000).'…';
            }
        }

        return $value;
    }
}
