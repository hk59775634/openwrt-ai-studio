<?php

namespace App\Console\Commands;

use App\Services\OpenWrtToolchain;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Process;

class PrepareOpenWrt extends Command
{
    protected $signature = 'studio:prepare-openwrt {--release=v24.10.4}';

    protected $description = 'Clone official OpenWrt source and update feeds for Build System compiles';

    public function handle(OpenWrtToolchain $toolchain): int
    {
        $tag = (string) $this->option('release');
        $cache = rtrim((string) config('studio.openwrt_cache_root'), '/');
        $hostCache = rtrim((string) config('studio.openwrt_host_cache_root'), '/') ?: $cache;
        $src = $cache.'/src/'.$tag;
        $toolchain->ensureContainerCacheDirectories();

        if (! is_file($src.'/Makefile')) {
            $this->info('Cloning OpenWrt '.$tag);
            $clone = Process::timeout(300)->run([
                'git', 'clone', '--depth', '1', '--branch', $tag,
                'https://github.com/openwrt/openwrt.git', $src,
            ]);
            if ($clone->failed()) {
                $this->error($clone->errorOutput() ?: $clone->output());

                return self::FAILURE;
            }
        }

        $image = $toolchain->buildrootImage();
        $toolchain->assertImage($image);
        $this->info('Updating feeds in '.$src);
        $feeds = Process::timeout(900)->run([
            'docker', 'run', '--rm',
            '--user', $toolchain->dockerUser(),
            '-v', $hostCache.':/opt/openwrt',
            '-w', '/opt/openwrt/src/'.$tag,
            $image,
            'sh', '-c', './scripts/feeds update -a && ./scripts/feeds install -a',
        ]);
        $this->line(trim($feeds->output()."\n".$feeds->errorOutput()));
        if ($feeds->failed()) {
            $this->error('feeds update failed with exit '.$feeds->exitCode());

            return self::FAILURE;
        }
        $this->info('OpenWrt '.$tag.' is ready');

        return self::SUCCESS;
    }
}
