<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;

class SyncOpenWrtDevices extends Command
{
    protected $signature = 'studio:sync-devices {--release=24.10.4}';

    protected $description = 'Refresh every OpenWrt target/device from official profiles.json';

    public function handle(): int
    {
        $release = (string) $this->option('release');
        $base = 'https://downloads.openwrt.org/releases/'.$release.'/targets';
        $this->info('Fetching OpenWrt '.$release.' target index…');
        $targets = $this->directories($base.'/');
        if ($targets === []) {
            $this->error('Unable to list targets from '.$base);

            return self::FAILURE;
        }

        $platforms = [];
        $ready = $this->imageReady((string) config('studio.build_buildroot_image', 'openwrt-ai-buildroot:24.10'));
        foreach ($targets as $target) {
            $subtargets = $this->directories($base.'/'.$target.'/');
            foreach ($subtargets as $subtarget) {
                $url = $base.'/'.$target.'/'.$subtarget.'/profiles.json';
                $this->line('  '.$target.'/'.$subtarget);
                try {
                    $response = Http::timeout(20)->withHeaders(['User-Agent' => 'OpenWrt-AI-Studio'])->acceptJson()->get($url);
                } catch (\Throwable $e) {
                    $this->warn('    skip: '.$e->getMessage());
                    continue;
                }
                if (! $response->successful()) {
                    continue;
                }
                $json = $response->json();
                if (! is_array($json) || ! isset($json['profiles']) || ! is_array($json['profiles'])) {
                    continue;
                }
                $arch = (string) ($json['arch_packages'] ?? '');
                $devices = [];
                foreach ($json['profiles'] as $profile => $meta) {
                    $titles = [];
                    foreach (($meta['titles'] ?? []) as $title) {
                        $titles[] = trim(($title['vendor'] ?? '').' '.($title['model'] ?? '').' '.($title['variant'] ?? ''));
                    }
                    $label = trim(implode(' / ', array_filter($titles))) ?: (string) $profile;
                    $devices[] = [
                        'profile' => (string) $profile,
                        'label' => $label,
                    ];
                }
                usort($devices, fn ($a, $b) => strcasecmp($a['label'], $b['label']));
                $platforms[] = [
                    'target' => $target,
                    'subtarget' => $subtarget,
                    'arch' => $arch !== '' ? $arch : 'unknown',
                    'label' => $target.' / '.$subtarget,
                    'chip' => $this->chip($target, $subtarget),
                    'sdk' => (string) config('studio.build_buildroot_image', 'openwrt-ai-buildroot:24.10'),
                    'ready' => $ready,
                    'devices' => $devices,
                ];
                $this->info('    '.count($devices).' devices');
            }
        }

        $path = config_path('openwrt-platforms.json');
        File::put($path, json_encode([
            'version' => $release,
            'platforms' => $platforms,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");
        $this->info('Wrote '.$path.' ('.count($platforms).' platforms)');
        $vendor = config_path('openwrt-vendor-platforms.json');
        if (is_file($vendor)) {
            $this->info('Vendor platforms remain in '.$vendor.' and are merged at runtime.');
        }

        return self::SUCCESS;
    }

    /**
     * @return list<string>
     */
    private function directories(string $url): array
    {
        try {
            $html = (string) Http::timeout(20)->withHeaders(['User-Agent' => 'OpenWrt-AI-Studio'])->get($url)->body();
        } catch (\Throwable) {
            return [];
        }
        preg_match_all('/href="([a-zA-Z0-9_-]+)\/"/', $html, $matches);

        return array_values(array_unique($matches[1] ?? []));
    }

    private function imageReady(string $image): bool
    {
        return Process::timeout(15)->run(['docker', 'image', 'inspect', $image])->successful();
    }

    private function chip(string $target, string $subtarget): ?string
    {
        return match ($target.'/'.$subtarget) {
            'mediatek/filogic' => 'Filogic (MT7981B/MT7981/MT7986/MT7988)',
            'mediatek/mt7622' => 'MT7622',
            'mediatek/mt7623' => 'MT7623',
            'mediatek/mt7629' => 'MT7629',
            'ramips/mt7621' => 'MT7621',
            'ramips/mt76x8' => 'MT76x8',
            'ramips/mt7628' => 'MT7628',
            'ramips/mt7620' => 'MT7620',
            'ath79/generic' => 'QCA/Atheros ath79',
            'ath79/nand' => 'QCA/Atheros ath79 NAND',
            'ath79/tiny' => 'QCA/Atheros ath79 tiny',
            'x86/64' => 'x86-64',
            'x86/generic' => 'x86',
            'rockchip/armv8' => 'Rockchip ARMv8',
            'qualcommax/ipq807x' => 'IPQ807x',
            'ipq40xx/generic' => 'IPQ40xx',
            default => null,
        };
    }
}
