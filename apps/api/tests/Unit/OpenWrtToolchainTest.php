<?php

namespace Tests\Unit;

use App\Models\Build;
use App\Services\OpenWrtToolchain;
use Tests\TestCase;

class OpenWrtToolchainTest extends TestCase
{
    public function test_vendor_preset_selects_legacy_image_and_source_args(): void
    {
        $toolchain = new OpenWrtToolchain;
        $preset = [
            'target' => 'ramips',
            'subtarget' => 'mt7628',
            'profile' => 'MT7628',
            'arch' => 'ramips_24kec',
            'sdk' => 'openwrt-ai-buildroot:14.07',
            'vendor' => true,
            'source' => 'mtk-mt7628',
            'revision' => 'mtk-mt7628',
        ];
        $this->assertSame('openwrt-ai-buildroot:14.07', $toolchain->imageFor($preset));

        $build = new Build([
            'openwrt_revision' => 'mtk-mt7628',
            'git_commit' => 'abc123',
            'cpu_limit' => 4,
        ]);
        $args = $toolchain->buildArgs($build, $preset);
        $this->assertContains('--vendor', $args);
        $this->assertContains('mtk-mt7628', $args);
        $this->assertContains('--source', $args);
        $this->assertSame('firmware', $args[array_search('--mode', $args, true) + 1]);
    }

    public function test_official_preset_does_not_pass_vendor_flag(): void
    {
        $toolchain = new OpenWrtToolchain;
        $preset = [
            'target' => 'x86',
            'subtarget' => '64',
            'profile' => 'generic',
            'arch' => 'x86_64',
            'sdk' => 'openwrt-ai-buildroot:24.10',
            'vendor' => false,
            'source' => null,
        ];
        $build = new Build([
            'openwrt_revision' => '24.10',
            'git_commit' => 'def',
            'cpu_limit' => 2,
        ]);
        $args = $toolchain->buildArgs($build, $preset, 'package', 'luci-app-demo', '0.1.0');
        $this->assertNotContains('--vendor', $args);
        $this->assertNotContains('--source', $args);
        $this->assertContains('luci-app-demo', $args);
    }

    public function test_cache_volumes_prefer_host_paths_for_docker_binds(): void
    {
        config([
            'studio.openwrt_cache_root' => '/data/openwrt',
            'studio.openwrt_dl_root' => '/data/downloads-cache',
            'studio.openwrt_ccache_root' => '/data/ccache',
            'studio.openwrt_host_cache_root' => '/srv/studio/data/openwrt',
            'studio.openwrt_host_dl_root' => '/srv/studio/data/downloads-cache',
            'studio.openwrt_host_ccache_root' => '/srv/studio/data/ccache',
            'studio.studio_host_root' => '/opt/openwrt-ai-studio',
        ]);

        $toolchain = new OpenWrtToolchain;
        $this->assertSame('/opt/openwrt-ai-studio/docker/buildroot/official.sh', $toolchain->scriptPath('docker/buildroot/official.sh'));
        $this->assertSame([
            '/srv/studio/data/openwrt:/opt/openwrt',
            '/srv/studio/data/downloads-cache:/opt/openwrt/dl',
            '/srv/studio/data/ccache:/ccache',
        ], $toolchain->cacheVolumes());
    }

    public function test_script_path_requires_studio_host_root(): void
    {
        config(['studio.studio_host_root' => '']);
        $this->expectException(\RuntimeException::class);
        (new OpenWrtToolchain)->scriptPath('docker/buildroot/official.sh');
    }
}
