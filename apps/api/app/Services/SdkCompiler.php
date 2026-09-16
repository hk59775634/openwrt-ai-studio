<?php

namespace App\Services;

use App\Models\Build;
use App\Models\Workspace;
use Illuminate\Support\Facades\File;
use RuntimeException;

class SdkCompiler
{
    public function __construct(
        private readonly WorkspaceFilesystem $filesystem,
        private readonly GitService $git,
        private readonly PackageResolver $packages,
        private readonly IpkBuilder $ipk,
        private readonly ArtifactStore $artifacts,
        private readonly BuildLogger $logger,
        private readonly DeviceCatalog $devices,
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
        File::ensureDirectoryExists($root.'/logs', 0750);

        $this->log($build, 'Preparing frozen git snapshot '.$build->git_commit);
        $this->git->exportTree($workspace, $snapshot, $build->git_commit);
        $package = $this->packages->resolve($workspace, $snapshot);
        $preset = $this->devices->resolve($workspace->target, $workspace->subtarget, $workspace->profile);
        $build->update([
            'package_name' => $package['name'],
            'architecture' => $preset['arch'],
        ]);

        $this->log($build, 'Package '.$package['name'].' '.$package['version']);
        $this->log($build, 'OpenWrt Build System '.$build->openwrt_revision.' package '.$package['name'].' / '.$preset['label']);
        $this->log($build, $build->command ?? '');

        $driver = (string) config('studio.build_driver', 'stub');
        if ($driver === 'docker') {
            $this->compileInDocker($build, $workspace, $snapshot, $out, $package);
        } else {
            $this->compileStub($build, $package, $out);
        }

        $collected = [];
        $files = is_dir($out)
            ? (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($out, \FilesystemIterator::SKIP_DOTS)))
            : [];
        foreach ($files as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'ipk') {
                continue;
            }
            $artifact = $this->artifacts->put($build, $file->getPathname());
            $collected[] = [
                'id' => $artifact->id,
                'name' => $artifact->name,
                'sha256' => $artifact->sha256,
                'size' => $artifact->size,
            ];
            $this->log($build, 'Artifact '.$artifact->name.' sha256='.$artifact->sha256);
        }

        if ($collected === []) {
            throw new RuntimeException('Build System finished without producing an IPK.');
        }

        $build->update(['artifact_manifest' => $collected]);
    }

    /**
     * @param  array{name: string, version: string, path: string}  $package
     */
    private function compileStub(Build $build, array $package, string $out): void
    {
        $this->log($build, 'make defconfig');
        $this->log($build, 'make package/'.$package['name'].'/download V=s');
        $this->log($build, 'make package/'.$package['name'].'/prepare V=s');
        $this->log($build, 'make package/'.$package['name'].'/compile V=s -j'.$build->cpu_limit);
        $this->log($build, 'make package/index');

        $files = $this->packageFiles($package['path']);
        $ipk = $out.'/'.$package['name'].'_'.$package['version'].'-1_'.$build->architecture.'.ipk';
        $this->ipk->build($ipk, $package['name'], $package['version'].'-1', $build->architecture, $files);
        $this->log($build, 'Wrote '.$ipk);
    }

    /**
     * @param  array{name: string, version: string, path: string}  $package
     */
    private function compileInDocker(Build $build, Workspace $workspace, string $snapshot, string $out, array $package): void
    {
        $hostRoot = rtrim((string) config('studio.sandbox_host_workspace_root'), '/').'/'.$workspace->id;
        $hostSrc = $hostRoot.'/build/'.$build->id.'/src';
        $hostOut = $hostRoot.'/artifacts/'.$build->id;
        $preset = $this->devices->resolve($workspace->target, $workspace->subtarget, $workspace->profile);
        $image = $this->toolchain->imageFor($preset);
        $this->toolchain->ensureContainerCacheDirectories();
        $script = $this->toolchain->scriptPath('docker/buildroot/official.sh');

        $this->log($build, 'docker run '.$image.' package='.$package['name'].' '.$preset['target'].'/'.$preset['subtarget']);
        $result = $this->toolchain->run($image, $script, $this->toolchain->buildArgs(
            $build,
            $preset,
            'package',
            $package['name'],
            $package['version'],
        ), $build, $hostSrc, $hostOut, $this->toolchain->cacheVolumes());

        $output = trim($result->output()."\n".$result->errorOutput());
        foreach (preg_split("/\r\n|\n|\r/", $output) as $line) {
            if ($line !== '') {
                $this->log($build, $line);
            }
        }

        if ($result->failed()) {
            throw new RuntimeException('OpenWrt Build System container failed with exit '.$result->exitCode());
        }
    }

    /**
     * @return array<string, string>
     */
    private function packageFiles(string $packageDir): array
    {
        $files = [];
        if (! is_dir($packageDir)) {
            return $files;
        }
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($packageDir, \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($iterator as $file) {
            if (! $file->isFile() || $file->getFilename() === 'Makefile') {
                continue;
            }
            $relative = ltrim(str_replace($packageDir, '', $file->getPathname()), '/');
            $files['usr/lib/lua/luci/'.str_replace('luasrc/', '', $relative)] = (string) file_get_contents($file->getPathname());
        }
        if ($files === []) {
            $files['usr/lib/lua/luci/'.$packageDir] = "# {$packageDir}\n";
        }

        return $files;
    }

    public function log(Build $build, string $line, string $stream = 'stdout'): void
    {
        $this->logger->line($build, $line, $stream);
    }
}
