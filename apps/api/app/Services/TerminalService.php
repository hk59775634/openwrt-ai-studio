<?php

namespace App\Services;

use App\Models\Workspace;
use Illuminate\Support\Facades\Process;
use InvalidArgumentException;
use RuntimeException;

class TerminalService
{
    /** @var list<string> */
    private array $gitVerbs = ['status', 'log', 'diff', 'branch', 'show', 'rev-parse'];

    public function __construct(
        private readonly RepoPath $paths,
        private readonly GitService $git,
    ) {}

    /**
     * @return array{command: string, cwd: string, exit_code: int, output: string}
     */
    public function run(Workspace $workspace, string $command): array
    {
        $this->git->ensureRepository($workspace);
        $command = trim($command);
        if ($command === '') {
            throw new InvalidArgumentException('Command is empty.');
        }
        if (preg_match('/[;&|`$()<>\\\\]/', $command)) {
            throw new InvalidArgumentException('Metacharacters are not allowed in the M2 terminal.');
        }

        $parts = preg_split('/\s+/', $command) ?: [];
        $bin = $parts[0] ?? '';
        $output = match ($bin) {
            'pwd' => "/workspace\n",
            'ls' => $this->ls($workspace, array_slice($parts, 1)),
            'cat', 'head' => $this->readTool($workspace, $bin, array_slice($parts, 1)),
            'git' => $this->gitCommand($workspace, array_slice($parts, 1)),
            default => throw new InvalidArgumentException('Command is not on the allowlist. Allowed: git status|log|diff|branch|show, ls, pwd, cat, head.'),
        };

        return [
            'command' => $command,
            'cwd' => '/workspace',
            'exit_code' => 0,
            'output' => $output,
        ];
    }

    /**
     * @param  list<string>  $args
     */
    private function ls(Workspace $workspace, array $args): string
    {
        $target = $args[0] ?? '.';
        $full = $target === '.' ? $this->paths->repo($workspace) : $this->paths->resolve($workspace, $target);
        if (! is_dir($full)) {
            throw new InvalidArgumentException('Not a directory.');
        }

        $names = array_values(array_filter(scandir($full) ?: [], fn ($name) => $name !== '.' && $name !== '..' && $name !== '.git'));

        return implode("\n", $names).($names === [] ? '' : "\n");
    }

    /**
     * @param  list<string>  $args
     */
    private function readTool(Workspace $workspace, string $bin, array $args): string
    {
        $path = $args[0] ?? throw new InvalidArgumentException($bin.' requires a path.');
        $full = $this->paths->resolve($workspace, $path);
        if (! is_file($full)) {
            throw new InvalidArgumentException('File not found.');
        }
        $content = file_get_contents($full) ?: '';
        if ($bin === 'head') {
            return implode("\n", array_slice(explode("\n", $content), 0, 20))."\n";
        }

        return $content;
    }

    /**
     * @param  list<string>  $args
     */
    private function gitCommand(Workspace $workspace, array $args): string
    {
        $verb = $args[0] ?? '';
        if (! in_array($verb, $this->gitVerbs, true)) {
            throw new InvalidArgumentException('git '.$verb.' is not allowed. Use status, log, diff, branch, or show.');
        }

        $command = array_merge(['git'], $args);
        $result = Process::path($this->paths->repo($workspace))
            ->timeout(20)
            ->run($command);

        if ($result->failed()) {
            throw new RuntimeException(trim($result->errorOutput() ?: $result->output()) ?: 'git failed');
        }

        return $result->output();
    }
}
