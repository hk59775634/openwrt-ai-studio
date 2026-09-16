<?php

namespace App\Services;

use App\Models\Build;
use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use RuntimeException;

class OpenWrtToolchain
{
    public function buildrootImage(): string
    {
        return (string) config('studio.build_buildroot_image');
    }

    /**
     * @param  array<string, mixed>  $preset
     */
    public function imageFor(array $preset): string
    {
        $image = trim((string) ($preset['sdk'] ?? ''));

        return $image !== '' ? $image : $this->buildrootImage();
    }

    /**
     * @param  array<string, mixed>  $preset
     * @return list<string>
     */
    public function buildArgs(Build $build, array $preset, string $mode = 'firmware', ?string $package = null, ?string $version = null): array
    {
        $args = [
            '--mode', $mode,
            '--revision', $build->openwrt_revision,
            '--target', (string) $preset['target'],
            '--subtarget', (string) $preset['subtarget'],
            '--profile', (string) $preset['profile'],
            '--commit', (string) ($build->git_commit ?? 'unknown'),
            '--jobs', (string) max(1, (int) $build->cpu_limit),
        ];
        if ($package) {
            $args[] = '--package';
            $args[] = $package;
        }
        if ($version) {
            $args[] = '--version';
            $args[] = $version;
        }
        if ($preset['arch'] ?? null) {
            $args[] = '--arch';
            $args[] = (string) $preset['arch'];
        }
        $source = trim((string) ($preset['source'] ?? ''));
        if ($source !== '') {
            $args[] = '--source';
            $args[] = $source;
        }
        if (! empty($preset['vendor']) || $source !== '') {
            $args[] = '--vendor';
        }

        return $args;
    }

    public function scriptPath(string $relative): string
    {
        $root = rtrim((string) config('studio.studio_host_root'), '/');
        if ($root === '') {
            throw new RuntimeException('STUDIO_HOST_ROOT is not set. Run ./scripts/bootstrap.sh from the git checkout.');
        }

        return $root.'/'.ltrim($relative, '/');
    }

    /**
     * Docker bind-mount sources must be host paths (docker.sock is the host daemon).
     *
     * @return list<string>
     */
    public function cacheVolumes(): array
    {
        $cache = $this->hostDir('openwrt_host_cache_root', 'openwrt_cache_root');
        $dl = $this->hostDir('openwrt_host_dl_root', 'openwrt_dl_root');
        $ccache = $this->hostDir('openwrt_host_ccache_root', 'openwrt_ccache_root');

        return [
            $cache.':/opt/openwrt',
            $dl.':/opt/openwrt/dl',
            $ccache.':/ccache',
        ];
    }

    public function ensureContainerCacheDirectories(): void
    {
        $cache = rtrim((string) config('studio.openwrt_cache_root'), '/');
        File::ensureDirectoryExists($cache.'/src', 0755);
        File::ensureDirectoryExists($cache.'/trees', 0755);
        File::ensureDirectoryExists((string) config('studio.openwrt_dl_root'), 0755);
        File::ensureDirectoryExists((string) config('studio.openwrt_ccache_root'), 0755);
    }

    public function dockerUser(): string
    {
        return '1000:1000';
    }

    public function assertImage(string $image): void
    {
        $result = Process::timeout(20)->run(['docker', 'image', 'inspect', $image]);
        if ($result->failed()) {
            throw new RuntimeException('Docker image '.$image.' is not installed. Build openwrt-ai-buildroot first.');
        }
    }

    public function imageInstalled(string $image): bool
    {
        return Process::timeout(20)->run(['docker', 'image', 'inspect', $image])->successful();
    }

    /**
     * @param  list<string>  $args
     * @param  list<string>  $extraVolumes
     */
    public function run(string $image, string $hostScript, array $args, Build $build, string $hostSrc, string $hostOut, array $extraVolumes = []): ProcessResult
    {
        $this->assertImage($image);

        $name = 'studio-ow-'.preg_replace('/[^a-z0-9]/', '', strtolower($build->id));
        $command = [
            'docker', 'run', '--rm',
            '--name', $name,
            '--entrypoint', '/usr/local/bin/studio-openwrt',
            '--memory', $build->memory_limit,
            '--cpus', (string) $build->cpu_limit,
            '--pids-limit', '8192',
            '--user', $this->dockerUser(),
            '-e', 'CCACHE_DIR=/ccache',
            '-v', $hostScript.':/usr/local/bin/studio-openwrt:ro',
            '-v', $hostSrc.':/src:ro',
            '-v', $hostOut.':/out',
        ];
        foreach ($extraVolumes as $volume) {
            $command[] = '-v';
            $command[] = $volume;
        }
        $command[] = $image;
        array_push($command, ...$args);

        return Process::timeout($build->timeout + 30)->run($command);
    }

    private function hostDir(string $hostKey, string $containerKey): string
    {
        $host = rtrim((string) config('studio.'.$hostKey), '/');
        if ($host !== '') {
            return $host;
        }

        $fallback = rtrim((string) config('studio.'.$containerKey), '/');
        if ($fallback === '') {
            throw new RuntimeException($hostKey.' is not set. Run ./scripts/bootstrap.sh from the git checkout.');
        }

        return $fallback;
    }
}
