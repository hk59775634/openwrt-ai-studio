<?php

namespace App\Services;

use App\Models\Workspace;
use Illuminate\Support\Facades\Process;
use InvalidArgumentException;
use RuntimeException;

class SandboxRuntime
{
    /** @var list<string> */
    private array $binaries = ['ls', 'pwd', 'cat', 'head', 'wc', 'git'];

    /** @var list<string> */
    private array $gitVerbs = ['status', 'log', 'diff', 'branch', 'show', 'rev-parse'];

    public function __construct(private readonly RepoPath $paths) {}

    public function driver(): string
    {
        return (string) config('studio.sandbox_driver', 'local');
    }

    /**
     * @return array{ok: bool, driver: string, detail?: string}
     */
    public function health(): array
    {
        if ($this->driver() !== 'docker') {
            return ['ok' => true, 'driver' => $this->driver()];
        }

        $result = Process::timeout(8)->run(['docker', 'info', '--format', '{{.ServerVersion}}']);
        if ($result->failed()) {
            return [
                'ok' => false,
                'driver' => 'docker',
                'detail' => trim($result->errorOutput() ?: $result->output()) ?: 'docker info failed',
            ];
        }

        return ['ok' => true, 'driver' => 'docker'];
    }

    /**
     * @param  list<string>  $argv
     * @return array{exit_code: int, output: string, driver: string}
     */
    public function exec(Workspace $workspace, array $argv): array
    {
        $argv = $this->assertAllowed($argv);
        $repo = $this->paths->repo($workspace);

        if ($this->driver() === 'docker') {
            return $this->dockerExec($workspace, $argv);
        }

        if (($argv[0] ?? '') === 'pwd') {
            return ['exit_code' => 0, 'output' => "/workspace\n", 'driver' => 'local'];
        }

        $result = Process::path($repo)
            ->timeout((int) config('studio.sandbox_timeout', 20))
            ->run($argv);

        $output = $this->virtualize($result->output().$result->errorOutput(), $repo);

        return [
            'exit_code' => $result->exitCode() ?? 1,
            'output' => $output,
            'driver' => 'local',
        ];
    }

    /**
     * @param  list<string>  $argv
     * @return list<string>
     */
    private function assertAllowed(array $argv): array
    {
        if ($argv === []) {
            throw new InvalidArgumentException('Command is empty.');
        }

        $bin = $argv[0];
        if (! in_array($bin, $this->binaries, true)) {
            throw new InvalidArgumentException('Command is not allowed in the sandbox.');
        }

        foreach ($argv as $part) {
            if (! is_string($part) || $part === '' || str_contains($part, "\0")) {
                throw new InvalidArgumentException('Invalid command argument.');
            }
            if (preg_match('/[;&|`$()<>\\\\]/', $part)) {
                throw new InvalidArgumentException('Metacharacters are not allowed in the sandbox.');
            }
        }

        if ($bin === 'git') {
            $verb = $argv[1] ?? '';
            if (! in_array($verb, $this->gitVerbs, true)) {
                throw new InvalidArgumentException('git '.$verb.' is not allowed in the sandbox.');
            }
        }

        if (in_array($bin, ['cat', 'head', 'wc'], true) && isset($argv[1]) && str_contains($argv[1], '..')) {
            throw new InvalidArgumentException('Invalid path.');
        }

        return $argv;
    }

    /**
     * @param  list<string>  $argv
     * @return array{exit_code: int, output: string, driver: string}
     */
    private function dockerExec(Workspace $workspace, array $argv): array
    {
        $hostRepo = rtrim((string) config('studio.sandbox_host_workspace_root'), '/').'/'.$workspace->id.'/repo';
        if (! str_starts_with($hostRepo, '/')) {
            throw new RuntimeException('SANDBOX_HOST_WORKSPACE_ROOT must be an absolute host path. Run ./scripts/bootstrap.sh.');
        }
        $name = 'studio-sbx-'.preg_replace('/[^a-z0-9]/', '', strtolower($workspace->id)).'-'.bin2hex(random_bytes(3));

        $command = [
            'docker', 'run', '--rm',
            '--name', $name,
            '--network', 'none',
            '--memory', (string) config('studio.sandbox_memory', '512m'),
            '--cpus', (string) config('studio.sandbox_cpus', '1'),
            '--pids-limit', '64',
            '--user', '1000:1000',
            '--read-only',
            '--tmpfs', '/tmp:rw,size=32m,mode=1777',
            '-v', $hostRepo.':/workspace:rw',
            '-w', '/workspace',
            (string) config('studio.sandbox_image'),
            ...$argv,
        ];

        $result = Process::timeout((int) config('studio.sandbox_timeout', 20) + 15)->run($command);
        if ($result->failed() && str_contains($result->errorOutput(), 'Unable to find image')) {
            throw new RuntimeException('Sandbox image is not built. Run: docker compose build agent');
        }

        $output = $this->virtualize($result->output().$result->errorOutput(), $hostRepo);

        return [
            'exit_code' => $result->exitCode() ?? 1,
            'output' => $output,
            'driver' => 'docker',
        ];
    }

    private function virtualize(string $output, string $real): string
    {
        return str_replace([$real, rtrim($real, '/repo')], ['/workspace', '/workspace'], $output);
    }
}
