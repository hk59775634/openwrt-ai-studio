<?php

namespace App\Jobs;

use App\Enums\BuildStatus;
use App\Enums\BuildType;
use App\Enums\WorkspaceStatus;
use App\Models\Build;
use App\Services\BuildLogger;
use App\Services\FirmwareCompiler;
use App\Services\SdkCompiler;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class CompileSdkJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 7500;

    public int $tries = 1;

    public function __construct(public string $buildId)
    {
        $this->onQueue('builds');
    }

    public function handle(SdkCompiler $sdk, FirmwareCompiler $firmware, BuildLogger $logger): void
    {
        $build = Build::query()->with('workspace')->findOrFail($this->buildId);
        $build->update([
            'status' => BuildStatus::Running,
            'started_at' => now(),
        ]);
        $build->workspace?->update(['status' => WorkspaceStatus::Building]);

        try {
            if ($build->type === BuildType::Firmware) {
                $firmware->compile($build);
            } else {
                $sdk->compile($build);
            }
            $build->update([
                'status' => BuildStatus::Success,
                'finished_at' => now(),
                'error' => null,
            ]);
            $build->workspace?->update(['status' => WorkspaceStatus::Success]);
        } catch (Throwable $e) {
            $logger->line($build, 'ERROR: '.$e->getMessage(), 'stderr');
            $build->update([
                'status' => BuildStatus::Failed,
                'finished_at' => now(),
                'error' => $e->getMessage(),
            ]);
            $build->workspace?->update(['status' => WorkspaceStatus::Failed]);
        }
    }
}
