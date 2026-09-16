<?php

namespace App\Services;

use Illuminate\Support\Facades\File;

class DeviceCatalog
{
    /**
     * @return list<array<string, mixed>>
     */
    public function platforms(): array
    {
        $path = config_path('openwrt-platforms.json');
        if (! is_file($path)) {
            $platforms = $this->fallbackPlatforms();
        } else {
            $decoded = json_decode((string) File::get($path), true);
            $platforms = $decoded['platforms'] ?? [];
            if (! is_array($platforms) || $platforms === []) {
                $platforms = $this->fallbackPlatforms();
            }
        }

        $byKey = [];
        foreach ($platforms as $platform) {
            if (! is_array($platform)) {
                continue;
            }
            $byKey[($platform['target'] ?? '').'/'.($platform['subtarget'] ?? '')] = $platform;
        }
        foreach ($this->vendorPlatforms() as $platform) {
            $key = ($platform['target'] ?? '').'/'.($platform['subtarget'] ?? '');
            $byKey[$key] = $platform;
        }
        $platforms = array_values($byKey);

        usort($platforms, function (array $a, array $b) {
            $rank = function (array $p): int {
                $key = ($p['target'] ?? '').'/'.($p['subtarget'] ?? '');
                return match ($key) {
                    'x86/64' => 0,
                    'mediatek/filogic' => 1,
                    'ramips/mt7628' => 1,
                    'ramips/mt7621' => 2,
                    'ath79/generic' => 3,
                    default => 10,
                };
            };

            return [$rank($a), $a['target'].'/'.$a['subtarget']] <=> [$rank($b), $b['target'].'/'.$b['subtarget']];
        });

        return $platforms;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function presets(): array
    {
        $presets = [];
        foreach ($this->platforms() as $platform) {
            foreach ($platform['devices'] as $device) {
                $presets[] = $this->presetFrom($platform, $device);
            }
        }

        return $presets;
    }

    /**
     * @return array<string, mixed>
     */
    public function resolve(?string $target, ?string $subtarget, ?string $profile): array
    {
        foreach ($this->presets() as $preset) {
            if ($preset['target'] === $target && $preset['subtarget'] === $subtarget && $preset['profile'] === $profile) {
                return $preset;
            }
        }
        if ($target && $subtarget) {
            foreach ($this->platforms() as $platform) {
                if ($platform['target'] !== $target || $platform['subtarget'] !== $subtarget) {
                    continue;
                }
                $device = $platform['devices'][0] ?? ['profile' => 'generic', 'label' => $platform['label']];
                if ($profile) {
                    $device = ['profile' => $profile, 'label' => $profile];
                    foreach ($platform['devices'] as $item) {
                        if ($item['profile'] === $profile) {
                            $device = $item;
                            break;
                        }
                    }
                }

                return $this->presetFrom($platform, $device);
            }

            return [
                'target' => $target,
                'subtarget' => $subtarget,
                'profile' => $profile ?: 'generic',
                'arch' => 'x86_64',
                'label' => $target.'/'.$subtarget.' · '.($profile ?: 'generic'),
                'sdk' => (string) config('studio.build_buildroot_image'),
                'vendor' => false,
                'source' => null,
                'revision' => null,
            ];
        }

        return $this->presets()[0];
    }

    /**
     * @param  array<string, mixed>  $preset
     */
    public function configFor(array $preset, string $revision): string
    {
        if (! empty($preset['vendor']) && ! empty($preset['source'])) {
            $sdkConfig = rtrim((string) config('studio.openwrt_cache_root', '/data/openwrt'), '/').'/src/'.$preset['source'].'/.config';
            if (is_file($sdkConfig)) {
                return (string) File::get($sdkConfig);
            }

            return implode("\n", [
                '# Vendor SDK '.$preset['source'].' · '.$preset['label'],
                'CONFIG_TARGET_'.$preset['target'].'=y',
                'CONFIG_TARGET_'.$preset['target'].'_'.$preset['subtarget'].'=y',
                'CONFIG_TARGET_'.$preset['target'].'_'.$preset['subtarget'].'_'.$preset['profile'].'=y',
                'CONFIG_PACKAGE_luci=y',
                '',
            ]);
        }

        $targetKey = 'CONFIG_TARGET_'.$preset['target'];
        $subKey = $targetKey.'_'.$preset['subtarget'];

        return implode("\n", [
            '# OpenWrt '.$revision.' · '.$preset['label'],
            $targetKey.'=y',
            $subKey.'=y',
            'CONFIG_TARGET_DEVICE_'.$preset['target'].'_'.$preset['subtarget'].'_DEVICE_'.$preset['profile'].'=y',
            'CONFIG_TARGET_MULTI_PROFILE=n',
            'CONFIG_TARGET_PROFILE="'.$preset['profile'].'"',
            'CONFIG_TARGET_ARCH_PACKAGES="'.$preset['arch'].'"',
            'CONFIG_PACKAGE_luci=y',
            '',
        ]);
    }

    /**
     * @param  array<string, mixed>  $platform
     * @param  array{profile: string, label: string}  $device
     * @return array<string, mixed>
     */
    private function presetFrom(array $platform, array $device): array
    {
        $source = $platform['source'] ?? null;
        $vendor = (bool) ($platform['vendor'] ?? false);
        $ready = (bool) ($platform['ready'] ?? true);
        if (is_string($source) && $source !== '') {
            $makefile = rtrim((string) config('studio.openwrt_cache_root', '/data/openwrt'), '/').'/src/'.$source.'/Makefile';
            $ready = is_file($makefile);
        }

        return [
            'target' => $platform['target'],
            'subtarget' => $platform['subtarget'],
            'profile' => $device['profile'],
            'arch' => $platform['arch'],
            'label' => $platform['label'].' · '.$device['label'],
            'sdk' => $platform['sdk'] ?? (string) config('studio.build_buildroot_image'),
            'vendor' => $vendor,
            'source' => $source,
            'revision' => $platform['revision'] ?? $source,
            'ready' => $ready,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function vendorPlatforms(): array
    {
        $path = config_path('openwrt-vendor-platforms.json');
        if (! is_file($path)) {
            return [];
        }
        $decoded = json_decode((string) File::get($path), true);
        $platforms = $decoded['platforms'] ?? [];
        if (! is_array($platforms)) {
            return [];
        }

        $result = [];
        foreach ($platforms as $platform) {
            if (! is_array($platform)) {
                continue;
            }
            $source = (string) ($platform['source'] ?? '');
            if ($source !== '') {
                $makefile = rtrim((string) config('studio.openwrt_cache_root', '/data/openwrt'), '/').'/src/'.$source.'/Makefile';
                $platform['ready'] = is_file($makefile);
            }
            $result[] = $platform;
        }

        return $result;
    }

    /**
     * @return list<array{target: string, subtarget: string, arch: string, label: string, sdk: string, devices: list<array{profile: string, label: string}>}>
     */
    private function fallbackPlatforms(): array
    {
        return [
            [
                'target' => 'x86',
                'subtarget' => '64',
                'arch' => 'x86_64',
                'label' => 'x86 / 64',
                'chip' => 'x86-64',
                'sdk' => 'openwrt-ai-buildroot:24.10',
                'ready' => true,
                'devices' => [['profile' => 'generic', 'label' => 'Generic x86/64']],
            ],
        ];
    }
}
