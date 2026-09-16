<?php

namespace Tests\Unit;

use App\Services\DeviceCatalog;
use Tests\TestCase;

class DeviceCatalogTest extends TestCase
{
    public function test_vendor_mt7628_platform_is_merged_and_resolved(): void
    {
        $catalog = app(DeviceCatalog::class);
        $platforms = $catalog->platforms();
        $vendor = collect($platforms)->first(
            fn (array $platform) => ($platform['target'] ?? '') === 'ramips' && ($platform['subtarget'] ?? '') === 'mt7628'
        );

        $this->assertNotNull($vendor);
        $this->assertTrue((bool) $vendor['vendor']);
        $this->assertSame('mtk-mt7628', $vendor['source']);
        $this->assertSame('openwrt-ai-buildroot:14.07', $vendor['sdk']);

        $preset = $catalog->resolve('ramips', 'mt7628', 'MT7628');
        $this->assertTrue($preset['vendor']);
        $this->assertSame('mtk-mt7628', $preset['source']);
        $this->assertSame('mtk-mt7628', $preset['revision']);
        $this->assertSame('ramips_24kec', $preset['arch']);
        $this->assertStringContainsString('CONFIG_TARGET_ramips_mt7628=y', $catalog->configFor($preset, 'mtk-mt7628'));
    }
}
