<?php

namespace App\Http\Controllers;

use App\Enums\BuildStatus;
use App\Models\Build;
use App\Services\ArtifactStore;
use App\Services\OpenWrtToolchain;
use App\Services\SandboxRuntime;
use App\Services\SettingsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Redis;
use Throwable;

class HealthController extends Controller
{
    public function __invoke(): JsonResponse
    {
        $checks = [
            'database' => $this->database(),
            'redis' => $this->redis(),
            'ai_gateway' => $this->aiGateway(),
            'sandbox' => app(SandboxRuntime::class)->health(),
            'minio' => app(ArtifactStore::class)->health(),
            'queue' => $this->queue(),
            'openwrt' => $this->openwrt(),
        ];

        $ok = $checks['database']['ok'] && $checks['redis']['ok'] && $checks['ai_gateway']['ok'];

        return response()->json([
            'ok' => $ok,
            'service' => 'openwrt-ai-studio-api',
            'checks' => $checks,
        ], $ok ? 200 : 503);
    }

    /**
     * @return array{ok: bool, detail?: string}
     */
    private function database(): array
    {
        try {
            DB::connection()->getPdo();

            return ['ok' => true];
        } catch (Throwable $e) {
            return ['ok' => false, 'detail' => $e->getMessage()];
        }
    }

    /**
     * @return array{ok: bool, detail?: string}
     */
    private function redis(): array
    {
        try {
            Redis::connection()->ping();

            return ['ok' => true];
        } catch (Throwable $e) {
            return ['ok' => false, 'detail' => $e->getMessage()];
        }
    }

    /**
     * @return array{ok: bool, url: string, detail?: string}
     */
    private function aiGateway(): array
    {
        $settings = app(SettingsService::class);
        $url = rtrim((string) $settings->get('ai_gateway_url', config('studio.ai_gateway_url')), '/');
        $key = (string) $settings->get('ai_gateway_api_key', config('studio.ai_gateway_api_key'));

        try {
            $response = Http::timeout(8)
                ->withToken($key)
                ->acceptJson()
                ->get($url.'/v1/models');

            return [
                'ok' => $response->successful(),
                'url' => $url,
                'detail' => $response->successful() ? null : 'HTTP '.$response->status(),
            ];
        } catch (Throwable $e) {
            return [
                'ok' => false,
                'url' => $url,
                'detail' => $e->getMessage(),
            ];
        }
    }

    /**
     * @return array{ok: bool, queued: int, running: int, redis_queued?: int|null, workers: int}
     */
    private function queue(): array
    {
        $queued = Build::query()->where('status', BuildStatus::Queued->value)->count();
        $running = Build::query()->where('status', BuildStatus::Running->value)->count();
        $redisQueued = null;
        try {
            $redisQueued = Redis::llen('queues:builds');
        } catch (Throwable) {
        }

        return [
            'ok' => true,
            'queued' => $queued,
            'running' => $running,
            'redis_queued' => $redisQueued,
            'workers' => (int) config('studio.queue_workers', 2),
        ];
    }

    /**
     * @return array{ok: bool, image: string, source?: string}
     */
    private function openwrt(): array
    {
        $image = (string) config('studio.build_buildroot_image');
        $cache = rtrim((string) config('studio.openwrt_cache_root'), '/');
        $source = $cache.'/src/v24.10.4/Makefile';
        $vendor = $cache.'/src/mtk-mt7628/Makefile';
        $legacy = 'openwrt-ai-buildroot:14.07';
        $toolchain = app(OpenWrtToolchain::class);
        $imageOk = $toolchain->imageInstalled($image);

        return [
            'ok' => $imageOk && is_file($source),
            'image' => $image,
            'source' => is_file($source) ? $source : null,
            'vendor_sdk' => is_file($vendor) ? $vendor : null,
            'legacy_image' => $toolchain->imageInstalled($legacy) ? $legacy : null,
        ];
    }
}
