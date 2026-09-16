<?php

namespace App\Services;

use Illuminate\Support\Facades\Process;
use RuntimeException;

class IpkBuilder
{
    /**
     * @param  array<string, string>  $files  relative path => contents
     */
    public function build(string $destination, string $package, string $version, string $arch, array $files): void
    {
        $dir = sys_get_temp_dir().'/ipk-'.bin2hex(random_bytes(6));
        mkdir($dir.'/control', 0750, true);
        mkdir($dir.'/data', 0750, true);

        $control = "Package: {$package}\nVersion: {$version}\nArchitecture: {$arch}\n"
            ."Maintainer: OpenWrt AI Studio <studio@openwrt-ai.local>\n"
            ."Section: luci\nDescription: {$package} built by OpenWrt AI Studio\n";
        file_put_contents($dir.'/control/control', $control);
        file_put_contents($dir.'/debian-binary', "2.0\n");

        foreach ($files as $relative => $contents) {
            $relative = ltrim(str_replace('\\', '/', $relative), '/');
            if ($relative === '' || str_contains($relative, '..')) {
                continue;
            }
            $full = $dir.'/data/'.$relative;
            if (! is_dir(dirname($full))) {
                mkdir(dirname($full), 0750, true);
            }
            file_put_contents($full, $contents);
        }

        $this->tarGzip($dir.'/control', $dir.'/control.tar.gz');
        $this->tarGzip($dir.'/data', $dir.'/data.tar.gz');
        $this->writeAr($destination, [
            'debian-binary' => (string) file_get_contents($dir.'/debian-binary'),
            'control.tar.gz' => (string) file_get_contents($dir.'/control.tar.gz'),
            'data.tar.gz' => (string) file_get_contents($dir.'/data.tar.gz'),
        ]);

        $this->removeDir($dir);
    }

    private function tarGzip(string $sourceDir, string $destination): void
    {
        $result = Process::path($sourceDir)->timeout(20)->run(['tar', '-czf', $destination, '.']);
        if ($result->failed() || ! is_file($destination)) {
            throw new RuntimeException(trim($result->errorOutput() ?: $result->output()) ?: 'tar failed');
        }
    }

    /**
     * @param  array<string, string>  $members
     */
    private function writeAr(string $destination, array $members): void
    {
        $fh = fopen($destination, 'wb');
        if ($fh === false) {
            throw new RuntimeException('Cannot write IPK.');
        }
        fwrite($fh, "!<arch>\n");
        foreach ($members as $name => $body) {
            $size = strlen($body);
            $header = sprintf("%-16s%-12s%-6s%-6s%-8s%-10s`\n", $name, (string) time(), '0', '0', '100644', (string) $size);
            fwrite($fh, $header);
            fwrite($fh, $body);
            if ($size % 2 === 1) {
                fwrite($fh, "\n");
            }
        }
        fclose($fh);
    }

    private function removeDir(string $dir): void
    {
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($items as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($dir);
    }
}
