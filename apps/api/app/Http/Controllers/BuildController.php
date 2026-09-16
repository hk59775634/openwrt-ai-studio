<?php

namespace App\Http\Controllers;

use App\Http\Concerns\AuthorizesWorkspace;
use App\Models\Artifact;
use App\Models\Build;
use App\Models\BuildLog;
use App\Models\Workspace;
use App\Services\ArtifactStore;
use App\Services\BuildManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

class BuildController extends Controller
{
    use AuthorizesWorkspace;

    public function __construct(
        private readonly BuildManager $builds,
        private readonly ArtifactStore $artifacts,
    ) {}

    public function index(Request $request, Workspace $workspace): JsonResponse
    {
        $this->authorizeWorkspace($request, $workspace);

        $items = $workspace->builds()->with('artifacts')->latest()->limit(20)->get();

        return response()->json(['data' => $items->map(fn (Build $build) => $this->present($build))]);
    }

    public function store(Request $request, Workspace $workspace): JsonResponse
    {
        $this->authorizeWorkspace($request, $workspace);

        $data = $request->validate([
            'type' => ['nullable', 'in:app,theme,sdk,firmware'],
        ]);

        try {
            $build = $this->builds->queue($workspace, $request->user(), $data['type'] ?? null);
        } catch (InvalidArgumentException|RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['data' => $this->present($build)], 201);
    }

    public function show(Request $request, Build $build): JsonResponse
    {
        $this->authorizeBuild($request, $build);

        return response()->json(['data' => $this->present($build->load(['artifacts']))]);
    }

    public function logs(Request $request, Build $build): JsonResponse
    {
        $this->authorizeBuild($request, $build);
        $after = max(0, (int) $request->query('after', 0));
        $limit = (int) $request->query('limit', 400);
        if ($limit <= 0) {
            $limit = 400;
        }
        $limit = min(1000, $limit);
        $max = (int) BuildLog::query()->where('build_id', $build->id)->max('sequence');
        $truncated = false;

        $query = BuildLog::query()->where('build_id', $build->id);
        if ($after === 0) {
            $start = max(0, $max - $limit);
            $truncated = $start > 0;
            $rows = $query->where('sequence', '>', $start)->orderBy('sequence')->limit($limit)->get();
        } else {
            $rows = $query->where('sequence', '>', $after)->orderBy('sequence')->limit($limit)->get();
        }

        return response()->json([
            'data' => $rows->map(fn ($log) => [
                'sequence' => $log->sequence,
                'stream' => $log->stream,
                'content' => strlen((string) $log->content) > 4000
                    ? substr((string) $log->content, 0, 4000).'…'
                    : $log->content,
            ])->values(),
            'status' => $build->status->value,
            'after' => $rows->last()?->sequence ?? $after,
            'max_sequence' => $max,
            'truncated' => $truncated,
        ]);
    }

    public function cancel(Request $request, Build $build): JsonResponse
    {
        $this->authorizeBuild($request, $build);

        try {
            $build = $this->builds->cancel($build, $request->user());
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['data' => $this->present($build)]);
    }

    public function destroy(Request $request, Build $build): JsonResponse
    {
        $this->authorizeBuild($request, $build);

        try {
            $this->builds->delete($build, $request->user());
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['ok' => true]);
    }

    public function download(Build $build, Artifact $artifact): Response
    {
        abort_unless($artifact->build_id === $build->id, 404);

        $filename = str_replace(['"', "\r", "\n", '/'], '', $artifact->name) ?: 'artifact.bin';
        $headers = [
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'public, max-age=300',
        ];

        $local = $this->artifacts->localPath($build, $artifact);
        if ($local) {
            return response()->download($local, $filename, $headers);
        }

        try {
            $body = $this->artifacts->stream($build, $artifact);
        } catch (RuntimeException) {
            abort(404);
        }

        return response($body, 200, $headers + [
            'Content-Type' => 'application/octet-stream',
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
        ]);
    }

    private function authorizeBuild(Request $request, Build $build): void
    {
        abort_unless($build->user_id === $request->user()?->id, 403);
        $this->authorizeWorkspace($request, $build->workspace);
    }

    /**
     * @return array<string, mixed>
     */
    private function present(Build $build): array
    {
        $build->loadMissing(['artifacts']);

        return [
            'id' => $build->id,
            'workspace_id' => $build->workspace_id,
            'type' => $build->type->value,
            'status' => $build->status->value,
            'package_name' => $build->package_name,
            'git_commit' => $build->git_commit,
            'openwrt_revision' => $build->openwrt_revision,
            'architecture' => $build->architecture,
            'command' => $build->command,
            'error' => $build->error,
            'started_at' => $build->started_at?->toIso8601String(),
            'finished_at' => $build->finished_at?->toIso8601String(),
            'created_at' => $build->created_at?->toIso8601String(),
            'logs' => [],
            'artifacts' => $build->artifacts->map(fn ($artifact) => [
                'id' => $artifact->id,
                'name' => $artifact->name,
                'sha256' => $artifact->sha256,
                'size' => $artifact->size,
                'download' => '/api/builds/'.$build->id.'/artifacts/'.$artifact->id,
            ]),
        ];
    }
}
