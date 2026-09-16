<?php

namespace App\Services;

use App\Models\Build;
use App\Models\Workspace;
use Illuminate\Support\Facades\File;
use RuntimeException;

class FirmwareCompiler
{
    public function __construct(
        private readonly WorkspaceFilesystem $filesystem,
        private readonly GitService $git,
        private readonly DeviceCatalog $devices,
        private readonly ArtifactStore $artifacts,
        private readonly BuildLogger $logger,
        private readonly OpenWrtToolchain $toolchain,
    ) {}

    public function compile(Build $build): void
    {
        $workspace = $build->workspace;
        $root = $this->filesystem->pathFor($workspace->id);
        $snapshot = $root.'/build/'.$build->id.'/src';
        $out = $root.'/artifacts/'.$build->id;
        File::ensureDirectoryExists($snapshot, 0750);
        File::ensureDirectoryExists($out, 0750);

        $preset = $this->devices->resolve($workspace->target, $workspace->subtarget, $workspace->profile);
        $build->update(['architecture' => $preset['arch']]);

        $this->logger->line($build, 'Preparing firmware snapshot '.$build->git_commit);
        $this->git->exportTree($workspace, $snapshot, $build->git_commit);
        $this->logger->line($build, 'OpenWrt Build System '.$build->openwrt_revision.' / '.$preset['label']);
        $this->logger->line($build, $build->command ?? '');

        $driver = (string) config('studio.build_driver', 'stub');
        if ($driver === 'docker') {
            $this->compileInDocker($build, $workspace, $preset);
        } else {
            $this->compileStub($build, $snapshot, $out, $preset);
        }

        $collected = [];
        $files = is_dir($out)
            ? (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($out, \FilesystemIterator::SKIP_DOTS)))
            : [];
        foreach ($files as $file) {
            if (! $file->isFile()) {
                continue;
            }
            $name = strtolower($file->getFilename());
            if (! preg_match('/\.(img\.gz|img|bin|itb|vmdk|vdi|vhdx|iso|tar\.gz)$/', $name)) {
                continue;
            }
            if (str_contains($name, 'kernel.bin')) {
                continue;
            }
            $artifact = $this->artifacts->put($build, $file->getPathname());
            $collected[] = [
                'id' => $artifact->id,
                'name' => $artifact->name,
                'sha256' => $artifact->sha256,
                'size' => $artifact->size,
            ];
            $this->logger->line($build, 'Artifact '.$artifact->name.' sha256='.$artifact->sha256.' size='.$artifact->size);
        }

        if ($collected === []) {
            throw new RuntimeException('Build System finished without producing a firmware image.');
        }

        $this->logger->line($build, '');
        $this->logger->line($build, '======== 固件打包成功 ========');
        $this->logger->line($build, 'FIRMWARE PACKAGING SUCCEEDED');
        foreach ($collected as $item) {
            $this->logger->line($build, '  '.$item['name'].'  ('.$this->formatBytes((int) $item['size']).')');
        }
        $this->logger->line($build, '可在本次构建记录中下载上述镜像。');
        $this->logger->line($build, '==============================');

        $build->update(['artifact_manifest' => $collected]);
    }

    private function formatBytes(int $bytes): string
    {
        if ($bytes < 1024) {
            return $bytes.' B';
        }
        if ($bytes < 1048576) {
            return number_format($bytes / 1024, 1).' KB';
        }

        return number_format($bytes / 1048576, 1).' MB';
    }

    /**
     * @param  array{target: string, subtarget: string, profile: string, arch: string, label: string}  $preset
     */
    private function compileStub(Build $build, string $snapshot, string $out, array $preset): void
    {
        $this->logger->line($build, 'make defconfig');
        $this->logger->line($build, 'make -j'.$build->cpu_limit.' V=s');
        $config = is_file($snapshot.'/.config')
            ? (string) file_get_contents($snapshot.'/.config')
            : $this->devices->configFor($preset, $build->openwrt_revision);

        $payload = "OpenWrt AI Studio firmware stub\n"
            ."revision=".$build->openwrt_revision."\n"
            ."target=".$preset['target']."/".$preset['subtarget']."\n"
            ."profile=".$preset['profile']."\n"
            ."commit=".$build->git_commit."\n\n"
            .$config;

        $name = 'openwrt-'.$build->openwrt_revision.'-'.$preset['target'].'-'.$preset['subtarget'].'-'.$preset['profile'].'-squashfs-combined.img.gz';
        $path = $out.'/'.$name;
        $gz = gzopen($path, 'wb9');
        if ($gz === false) {
            throw new RuntimeException('Unable to write firmware image.');
        }
        gzwrite($gz, $payload);
        gzclose($gz);
        $this->logger->line($build, 'Wrote '.$path);
    }

    /**
     * @param  array{target: string, subtarget: string, profile: string, arch: string, label: string, sdk?: string}  $preset
     */
    private function compileInDocker(Build $build, Workspace $workspace, array $preset): void
    {
        $hostRoot = rtrim((string) config('studio.sandbox_host_workspace_root'), '/').'/'.$workspace->id;
        $hostSrc = $hostRoot.'/build/'.$build->id.'/src';
        $hostOut = $hostRoot.'/artifacts/'.$build->id;
        $image = $this->toolchain->imageFor($preset);
        $this->toolchain->ensureContainerCacheDirectories();
        $script = $this->toolchain->scriptPath('docker/buildroot/official.sh');

        $this->logger->line($build, 'docker run '.$image.' make -j'.$build->cpu_limit.' '.$preset['target'].'/'.$preset['subtarget'].' '.$preset['profile']);
        $result = $this->toolchain->run($image, $script, $this->toolchain->buildArgs($build, $preset), $build, $hostSrc, $hostOut, $this->toolchain->cacheVolumes());

        $output = trim($result->output()."\n".$result->errorOutput());
        foreach (preg_split("/\r\n|\n|\r/", $output) as $line) {
            if ($line !== '') {
                $this->logger->line($build, $line);
            }
        }

        if ($result->failed()) {
            throw new RuntimeException('OpenWrt Build System container failed with exit '.$result->exitCode());
        }
    }
}
