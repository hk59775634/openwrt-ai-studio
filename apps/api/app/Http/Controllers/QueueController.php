<?php

namespace App\Http\Controllers;

use App\Enums\BuildStatus;
use App\Models\Build;
use App\Services\QuotaService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Redis;
use Throwable;

class QueueController extends Controller
{
    public function __invoke(Request $request, QuotaService $quotas): JsonResponse
    {
        $queued = Build::query()->where('status', BuildStatus::Queued->value)->count();
        $running = Build::query()->where('status', BuildStatus::Running->value)->count();
        $redisQueued = null;
        try {
            $redisQueued = Redis::llen('queues:builds');
        } catch (Throwable) {
        }

        return response()->json([
            'data' => [
                'queued' => $queued,
                'running' => $running,
                'redis_queued' => $redisQueued,
                'workers' => (int) config('studio.queue_workers', 2),
                'quota' => $quotas->snapshot($request->user()),
            ],
        ]);
    }
}
