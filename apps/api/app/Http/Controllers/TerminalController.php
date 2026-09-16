<?php

namespace App\Http\Controllers;

use App\Http\Concerns\AuthorizesWorkspace;
use App\Models\Workspace;
use App\Services\TerminalService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;
use RuntimeException;

class TerminalController extends Controller
{
    use AuthorizesWorkspace;

    public function __construct(private readonly TerminalService $terminal) {}

    public function run(Request $request, Workspace $workspace): JsonResponse
    {
        $this->authorizeWorkspace($request, $workspace);
        $data = $request->validate([
            'command' => ['required', 'string', 'max:240'],
        ]);

        try {
            return response()->json(['data' => $this->terminal->run($workspace, $data['command'])]);
        } catch (InvalidArgumentException|RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }
}
