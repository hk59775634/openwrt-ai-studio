<?php

namespace App\Services;

use App\Models\Workspace;
use InvalidArgumentException;

class PackageResolver
{
    public function __construct(private readonly RepoPath $paths) {}

    /**
     * @return array{name: string, version: string, path: string}
     */
    public function resolve(Workspace $workspace, ?string $snapshotRoot = null): array
    {
        $root = $snapshotRoot ?: $this->paths->repo($workspace);
        $found = [];

        $packageDir = $root.'/package';
        if (is_dir($packageDir)) {
            $found = array_merge($found, $this->scanMakefiles($packageDir));
        }

        $rootPackage = $this->packageFromMakefile($root.'/Makefile');
        if ($rootPackage !== null) {
            $found[] = $rootPackage;
        }

        if ($found === []) {
            throw new InvalidArgumentException(
                'No OpenWrt package Makefile with PKG_NAME was found. Expected package/<name>/Makefile, or a Makefile with PKG_NAME at the repository root.'
            );
        }

        $preferredPrefix = $workspace->type->value === 'theme' ? 'luci-theme-' : 'luci-app-';
        foreach ($found as $package) {
            if (str_starts_with($package['name'], $preferredPrefix)) {
                return $package;
            }
        }

        return $found[0];
    }

    /**
     * @return list<array{name: string, version: string, path: string}>
     */
    private function scanMakefiles(string $directory): array
    {
        $found = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($iterator as $file) {
            if ($file->getFilename() !== 'Makefile') {
                continue;
            }
            $package = $this->packageFromMakefile($file->getPathname());
            if ($package !== null) {
                $found[] = $package;
            }
        }

        return $found;
    }

    /**
     * @return array{name: string, version: string, path: string}|null
     */
    private function packageFromMakefile(string $makefile): ?array
    {
        if (! is_file($makefile)) {
            return null;
        }

        $contents = (string) file_get_contents($makefile);
        if (! preg_match('/^PKG_NAME:=(.+)$/m', $contents, $nameMatch)) {
            return null;
        }

        $name = trim($nameMatch[1]);
        if ($name === '' || ! preg_match('/^[A-Za-z0-9._+-]+$/', $name)) {
            return null;
        }

        $version = '0.1.0';
        if (preg_match('/^PKG_VERSION:=(.+)$/m', $contents, $versionMatch)) {
            $version = trim($versionMatch[1]);
        }

        return [
            'name' => $name,
            'version' => $version,
            'path' => dirname($makefile),
        ];
    }
}
