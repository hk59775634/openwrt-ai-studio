<?php

namespace Tests\Unit;

use Tests\TestCase;

class OfficialBuildScriptTest extends TestCase
{
    public function test_firmware_rsync_excludes_only_top_level_bin(): void
    {
        $excludes = ['/.git', '/bin/', '/build_dir/', '/staging_dir/', '/tmp/', '/dl', '/.studio.lock'];

        $this->assertTrue($this->rsyncWouldExclude('bin/firmware.img', $excludes));
        $this->assertTrue($this->rsyncWouldExclude('build_dir/host/missing-macros', $excludes));
        $this->assertFalse($this->rsyncWouldExclude('tools/missing-macros/src/bin/help2man', $excludes));
        $this->assertFalse($this->rsyncWouldExclude('package/base-files/files/bin/busybox', $excludes));
        $this->assertFalse($this->rsyncWouldExclude('package/base-files/files/bin/board_detect', $excludes));
        $this->assertFalse($this->rsyncWouldExclude('feeds/luci/Makefile', $excludes));

        $legacy = ['bin/', 'build_dir/', 'staging_dir/'];
        $this->assertTrue($this->rsyncWouldExclude('tools/missing-macros/src/bin/help2man', $legacy));
    }

    public function test_vendor_sdk_path_skips_official_clone_and_feeds_update(): void
    {
        $candidates = [
            dirname(base_path(), 2).'/docker/buildroot/official.sh',
            rtrim((string) config('studio.studio_host_root', ''), '/').'/docker/buildroot/official.sh',
        ];
        $script = '';
        foreach ($candidates as $path) {
            if (is_file($path)) {
                $script = (string) file_get_contents($path);
                break;
            }
        }
        if ($script === '') {
            $this->markTestSkipped('official.sh is not mounted in the API container');
        }

        $this->assertStringContainsString('--source)', $script);
        $this->assertStringContainsString('--vendor)', $script);
        $this->assertStringContainsString('mtk-mt7628', $script);
        $this->assertStringContainsString('skipping feeds update', $script);
        $this->assertStringContainsString('do not clone official OpenWrt', $script);
        $this->assertStringContainsString('find bin -type f', $script);
        $this->assertStringNotContainsString('find bin/targets', $script);
        $this->assertStringContainsString('固件打包成功', $script);
        $this->assertStringContainsString('FIRMWARE PACKAGING SUCCEEDED', $script);
        $this->assertStringContainsString('Injected root package', $script);
        $this->assertStringContainsString('Cleared vendor files/ overlay paths owned by', $script);
    }

    /**
     * @param  list<string>  $excludes
     */
    private function rsyncWouldExclude(string $relative, array $excludes): bool
    {
        $relative = ltrim($relative, '/');
        foreach ($excludes as $pattern) {
            if (str_starts_with($pattern, '/')) {
                $prefix = ltrim($pattern, '/');
                $prefix = rtrim($prefix, '/');
                if ($relative === $prefix || str_starts_with($relative, $prefix.'/')) {
                    return true;
                }
                continue;
            }
            $dir = rtrim($pattern, '/');
            if ($relative === $dir || str_starts_with($relative, $dir.'/') || str_contains('/'.$relative, '/'.$dir.'/')) {
                return true;
            }
        }

        return false;
    }
}
