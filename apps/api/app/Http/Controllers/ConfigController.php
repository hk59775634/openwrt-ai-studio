<?php

namespace App\Http\Controllers;

use App\Http\Concerns\AuthorizesWorkspace;
use App\Models\ConfigSnapshot;
use App\Models\Workspace;
use App\Services\ConfigManager;
use App\Services\DeviceCatalog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

class ConfigController extends Controller
{
    use AuthorizesWorkspace;

    public function __construct(
        private readonly ConfigManager $configs,
        private readonly DeviceCatalog $devices,
    ) {}

    public function devices(): JsonResponse
    {
        return response()->json([
            'data' => $this->devices->presets(),
            'platforms' => $this->devices->platforms(),
        ]);
    }

    public function index(Request $request, Workspace $workspace): JsonResponse
    {
        $this->authorizeWorkspace($request, $workspace);
        $items = $workspace->configSnapshots()->latest()->limit(40)->get();

        return response()->json(['data' => $items->map(fn (ConfigSnapshot $item) => $this->present($item))]);
    }

    public function store(Request $request, Workspace $workspace): JsonResponse
    {
        $this->authorizeWorkspace($request, $workspace);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:80'],
            'target' => ['nullable', 'string', 'max:64'],
            'subtarget' => ['nullable', 'string', 'max:64'],
            'profile' => ['nullable', 'string', 'max:128'],
            'content' => ['nullable', 'string', 'max:1048576'],
        ]);

        $snapshot = $this->configs->save($workspace, $request->user(), $data);

        return response()->json(['data' => $this->present($snapshot)], 201);
    }

    public function apply(Request $request, Workspace $workspace, ConfigSnapshot $snapshot): JsonResponse
    {
        $this->authorizeWorkspace($request, $workspace);
        try {
            $this->configs->apply($workspace, $snapshot);
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['ok' => true, 'workspace' => [
            'target' => $workspace->fresh()->target,
            'subtarget' => $workspace->fresh()->subtarget,
            'profile' => $workspace->fresh()->profile,
        ]]);
    }

    public function destroy(Request $request, Workspace $workspace, ConfigSnapshot $snapshot): JsonResponse
    {
        $this->authorizeWorkspace($request, $workspace);
        try {
            $this->configs->delete($workspace, $snapshot);
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['ok' => true]);
    }

    public function compare(Request $request, Workspace $workspace): JsonResponse
    {
        $this->authorizeWorkspace($request, $workspace);
        $data = $request->validate([
            'left' => ['required', 'integer'],
            'right' => ['required', 'integer'],
        ]);
        $left = ConfigSnapshot::query()->where('workspace_id', $workspace->id)->findOrFail($data['left']);
        $right = ConfigSnapshot::query()->where('workspace_id', $workspace->id)->findOrFail($data['right']);

        return response()->json(['data' => $this->configs->compare($left, $right)]);
    }

    public function generate(Request $request, Workspace $workspace): JsonResponse
    {
        $this->authorizeWorkspace($request, $workspace);
        $data = $request->validate([
            'target' => ['nullable', 'string', 'max:64'],
            'subtarget' => ['nullable', 'string', 'max:64'],
            'profile' => ['nullable', 'string', 'max:128'],
        ]);
        $preset = $this->devices->resolve($data['target'] ?? $workspace->target, $data['subtarget'] ?? $workspace->subtarget, $data['profile'] ?? $workspace->profile);
        $content = $this->configs->writeGenerated($workspace, $preset);

        return response()->json([
            'data' => [
                'content' => $content,
                'preset' => $preset,
            ],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function present(ConfigSnapshot $snapshot): array
    {
        return [
            'id' => $snapshot->id,
            'name' => $snapshot->name,
            'target' => $snapshot->target,
            'subtarget' => $snapshot->subtarget,
            'profile' => $snapshot->profile,
            'openwrt_revision' => $snapshot->openwrt_revision,
            'sha256' => $snapshot->sha256,
            'content' => $snapshot->content,
            'created_at' => $snapshot->created_at?->toIso8601String(),
        ];
    }
}
