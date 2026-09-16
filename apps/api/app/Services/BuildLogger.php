<?php

namespace App\Services;

use App\Models\Build;
use App\Models\BuildLog;
use Illuminate\Support\Facades\File;
use Throwable;

class BuildLogger
{
    public function __construct(private readonly WorkspaceFilesystem $filesystem) {}

    public function line(Build $build, string $line, string $stream = 'stdout'): void
    {
        $sequence = (int) BuildLog::query()->where('build_id', $build->id)->max('sequence') + 1;
        BuildLog::query()->create([
            'build_id' => $build->id,
            'sequence' => $sequence,
            'stream' => $stream,
            'content' => $line,
        ]);

        try {
            $path = $this->filesystem->pathFor($build->workspace_id).'/logs/'.$build->id.'.log';
            File::ensureDirectoryExists(dirname($path), 0750);
            File::append($path, $line."\n");
        } catch (Throwable) {
        }
    }
}
