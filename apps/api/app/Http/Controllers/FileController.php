<?php

namespace App\Http\Controllers;

use App\Http\Concerns\AuthorizesWorkspace;
use App\Models\Workspace;
use App\Services\FileService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

class FileController extends Controller
{
    use AuthorizesWorkspace;

    public function __construct(private readonly FileService $files) {}

    public function tree(Request $request, Workspace $workspace): JsonResponse
    {
        $this->authorizeWorkspace($request, $workspace);

        return response()->json(['data' => $this->files->tree($workspace)]);
    }

    public function show(Request $request, Workspace $workspace): JsonResponse
    {
        $this->authorizeWorkspace($request, $workspace);
        $data = $request->validate(['path' => ['required', 'string', 'max:512']]);

        try {
            return response()->json(['data' => $this->files->read($workspace, $data['path'])]);
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    public function update(Request $request, Workspace $workspace): JsonResponse
    {
        $this->authorizeWorkspace($request, $workspace);
        $data = $request->validate([
            'path' => ['required', 'string', 'max:512'],
            'content' => ['present', 'string', 'max:1048576'],
        ]);

        try {
            $this->files->write($workspace, $data['path'], $data['content']);
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['ok' => true]);
    }

    public function store(Request $request, Workspace $workspace): JsonResponse
    {
        $this->authorizeWorkspace($request, $workspace);
        $data = $request->validate([
            'path' => ['required', 'string', 'max:512'],
            'type' => ['required', 'in:file,dir'],
        ]);

        try {
            $this->files->create($workspace, $data['path'], $data['type']);
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['ok' => true], 201);
    }
}
