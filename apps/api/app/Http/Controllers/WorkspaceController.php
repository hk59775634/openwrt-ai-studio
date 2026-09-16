<?php

namespace App\Http\Controllers;

use App\Enums\ProjectType;
use App\Http\Concerns\AuthorizesWorkspace;
use App\Http\Resources\WorkspaceResource;
use App\Models\Workspace;
use App\Services\WorkspaceManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;
use InvalidArgumentException;
use RuntimeException;

class WorkspaceController extends Controller
{
    use AuthorizesWorkspace;

    public function __construct(private readonly WorkspaceManager $workspaces) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $items = $request->user()
            ->workspaces()
            ->active()
            ->latest()
            ->get();

        return WorkspaceResource::collection($items);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:80'],
            'type' => ['required', Rule::enum(ProjectType::class)],
            'openwrt_revision' => ['nullable', 'string', 'max:64'],
            'target' => ['nullable', 'string', 'max:64'],
            'subtarget' => ['nullable', 'string', 'max:64'],
            'profile' => ['nullable', 'string', 'max:128'],
            'git_url' => ['nullable', 'string', 'max:500'],
            'git_branch' => ['nullable', 'string', 'max:120'],
            'git_token' => ['nullable', 'string', 'max:512'],
        ]);

        try {
            $workspace = $this->workspaces->create($request->user(), $data);
        } catch (RuntimeException|InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return (new WorkspaceResource($workspace))
            ->response()
            ->setStatusCode(201);
    }

    public function show(Request $request, Workspace $workspace): WorkspaceResource
    {
        $this->authorizeWorkspace($request, $workspace);

        return new WorkspaceResource($workspace);
    }

    public function destroy(Request $request, Workspace $workspace): JsonResponse
    {
        $this->authorizeWorkspace($request, $workspace);
        $this->workspaces->delete($request->user(), $workspace);

        return response()->json(['ok' => true]);
    }

    public function clone(Request $request, Workspace $workspace): JsonResponse
    {
        $this->authorizeWorkspace($request, $workspace);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:80'],
        ]);

        try {
            $clone = $this->workspaces->clone($request->user(), $workspace, $data['name']);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return (new WorkspaceResource($clone))
            ->response()
            ->setStatusCode(201);
    }
}
