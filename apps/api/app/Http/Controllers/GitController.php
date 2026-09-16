<?php

namespace App\Http\Controllers;

use App\Http\Concerns\AuthorizesWorkspace;
use App\Models\AuditLog;
use App\Models\Workspace;
use App\Services\GitService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

class GitController extends Controller
{
    use AuthorizesWorkspace;

    public function __construct(private readonly GitService $git) {}

    public function status(Request $request, Workspace $workspace): JsonResponse
    {
        $this->authorizeWorkspace($request, $workspace);

        return response()->json(['data' => $this->git->status($workspace)]);
    }

    public function diff(Request $request, Workspace $workspace): JsonResponse
    {
        $this->authorizeWorkspace($request, $workspace);
        $path = $request->query('path');
        $path = is_string($path) && $path !== '' ? $path : null;

        return response()->json(['data' => ['diff' => $this->git->diff($workspace, $path)]]);
    }

    public function commit(Request $request, Workspace $workspace): JsonResponse
    {
        $this->authorizeWorkspace($request, $workspace);
        $data = $request->validate([
            'message' => ['required', 'string', 'min:3', 'max:200'],
        ]);

        try {
            $commit = $this->git->commit($workspace, $request->user(), $data['message']);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        AuditLog::query()->create([
            'user_id' => $request->user()->id,
            'action' => 'git.commit',
            'resource' => $workspace->id,
            'metadata' => $commit,
        ]);

        return response()->json(['data' => $commit], 201);
    }

    public function log(Request $request, Workspace $workspace): JsonResponse
    {
        $this->authorizeWorkspace($request, $workspace);

        return response()->json(['data' => $this->git->log($workspace)]);
    }

    public function restore(Request $request, Workspace $workspace): JsonResponse
    {
        $this->authorizeWorkspace($request, $workspace);
        $data = $request->validate(['path' => ['required', 'string', 'max:512']]);
        $this->git->restore($workspace, $data['path']);

        return response()->json(['ok' => true]);
    }

    public function remote(Request $request, Workspace $workspace): JsonResponse
    {
        $this->authorizeWorkspace($request, $workspace);
        $data = $request->validate([
            'url' => ['required', 'string', 'max:500'],
            'branch' => ['nullable', 'string', 'max:120'],
            'token' => ['nullable', 'string', 'max:512'],
        ]);

        try {
            $this->git->bindRemote($workspace, $data['url'], $data['branch'] ?? null, $data['token'] ?? null);
        } catch (RuntimeException|\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        AuditLog::query()->create([
            'user_id' => $request->user()->id,
            'action' => 'git.remote',
            'resource' => $workspace->id,
            'metadata' => ['url' => $workspace->fresh()->git_remote_url],
        ]);

        $workspace->refresh();

        return response()->json([
            'data' => [
                'git_remote_url' => $workspace->git_remote_url,
                'git_remote_branch' => $workspace->git_remote_branch,
                'git_token_set' => filled($workspace->git_token),
            ],
        ]);
    }

    public function push(Request $request, Workspace $workspace): JsonResponse
    {
        $this->authorizeWorkspace($request, $workspace);
        $data = $request->validate([
            'branch' => ['nullable', 'string', 'max:120'],
        ]);
        try {
            $result = $this->git->push($workspace, $data['branch'] ?? null);
        } catch (RuntimeException|\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        AuditLog::query()->create([
            'user_id' => $request->user()->id,
            'action' => 'git.push',
            'resource' => $workspace->id,
            'metadata' => $result,
        ]);

        return response()->json(['data' => $result]);
    }

    public function pull(Request $request, Workspace $workspace): JsonResponse
    {
        $this->authorizeWorkspace($request, $workspace);
        $data = $request->validate([
            'branch' => ['nullable', 'string', 'max:120'],
        ]);
        try {
            $result = $this->git->pull($workspace, $data['branch'] ?? null);
        } catch (RuntimeException|\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['data' => $result]);
    }

    public function release(Request $request, Workspace $workspace): JsonResponse
    {
        $this->authorizeWorkspace($request, $workspace);
        $data = $request->validate([
            'tag' => ['required', 'string', 'max:40'],
            'message' => ['nullable', 'string', 'max:2000'],
            'artifact_ids' => ['sometimes', 'nullable', 'array', 'max:20'],
            'artifact_ids.*' => ['integer', 'min:1'],
        ]);

        try {
            $result = $this->git->release(
                $workspace,
                $data['tag'],
                $data['message'] ?? null,
                array_key_exists('artifact_ids', $data) ? array_values($data['artifact_ids'] ?? []) : null,
            );
        } catch (RuntimeException|\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        AuditLog::query()->create([
            'user_id' => $request->user()->id,
            'action' => 'git.release',
            'resource' => $workspace->id,
            'metadata' => $result,
        ]);

        return response()->json(['data' => $result], 201);
    }
}
