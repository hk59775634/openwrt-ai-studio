<?php

namespace App\Http\Controllers;

use App\Http\Concerns\AuthorizesWorkspace;
use App\Jobs\RunAgentTurnJob;
use App\Models\AiSession;
use App\Models\Workspace;
use App\Services\AgentRunner;
use App\Services\AiGateway;
use App\Services\GitService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

class AiSessionController extends Controller
{
    use AuthorizesWorkspace;

    public function __construct(
        private readonly AgentRunner $agents,
        private readonly AiGateway $gateway,
        private readonly GitService $git,
    ) {}

    public function models(): JsonResponse
    {
        return response()->json([
            'data' => $this->gateway->models(),
            'default' => $this->gateway->defaultModel(),
        ]);
    }

    public function index(Request $request, Workspace $workspace): JsonResponse
    {
        $this->authorizeWorkspace($request, $workspace);

        $sessions = $workspace->aiSessions()
            ->where('user_id', $request->user()?->id)
            ->with(['messages' => fn ($query) => $query->where('role', 'user')->orderBy('id')])
            ->latest()
            ->limit(50)
            ->get(['id', 'model', 'agent_type', 'status', 'created_at', 'updated_at']);

        return response()->json([
            'data' => $sessions->map(fn (AiSession $session) => [
                'id' => $session->id,
                'model' => $session->model,
                'agent_type' => $session->agent_type,
                'status' => $session->status,
                'created_at' => $session->created_at?->toIso8601String(),
                'updated_at' => $session->updated_at?->toIso8601String(),
                'preview' => $this->preview($session),
            ]),
        ]);
    }

    public function store(Request $request, Workspace $workspace): JsonResponse
    {
        $this->authorizeWorkspace($request, $workspace);
        $data = $request->validate([
            'model' => ['nullable', 'string', 'max:120'],
        ]);

        $session = $this->agents->createSession($workspace, $request->user(), $data['model'] ?? null);

        return response()->json(['data' => $this->present($session)], 201);
    }

    public function show(Request $request, AiSession $aiSession): JsonResponse
    {
        $this->authorizeSession($request, $aiSession);

        return response()->json(['data' => $this->present($aiSession->load(['messages', 'operations']))]);
    }

    public function message(Request $request, AiSession $aiSession): JsonResponse
    {
        $this->authorizeSession($request, $aiSession);
        $data = $request->validate([
            'content' => ['required', 'string', 'min:1', 'max:8000'],
            'model' => ['nullable', 'string', 'max:120'],
        ]);

        abort_if($aiSession->status === 'running', 409, 'Agent is already working.');

        if (! empty($data['model'])) {
            $aiSession->update(['model' => $data['model']]);
        }

        $this->agents->beginTurn($aiSession, $data['content']);
        RunAgentTurnJob::dispatch($aiSession->id, (int) $request->user()->id);

        $aiSession->refresh()->load(['messages', 'operations']);
        $finished = in_array($aiSession->status, ['idle', 'error'], true);

        return response()->json([
            'data' => $this->present($aiSession),
            'diff' => $finished ? $this->git->diff($aiSession->workspace) : '',
        ]);
    }

    public function stop(Request $request, AiSession $aiSession): JsonResponse
    {
        $this->authorizeSession($request, $aiSession);

        try {
            $session = $this->agents->stop($aiSession);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['data' => $this->present($session)]);
    }

    public function destroy(Request $request, AiSession $aiSession): JsonResponse
    {
        $this->authorizeSession($request, $aiSession);
        $aiSession->delete();

        return response()->json(['ok' => true]);
    }

    private function authorizeSession(Request $request, AiSession $session): void
    {
        abort_unless($session->user_id === $request->user()?->id, 403);
        $this->authorizeWorkspace($request, $session->workspace);
    }

    private function preview(AiSession $session): string
    {
        $text = trim((string) ($session->messages->first()?->content ?? ''));
        if ($text === '') {
            return 'New chat';
        }
        $text = preg_replace('/\s+/', ' ', $text) ?? $text;

        return mb_strlen($text) > 72 ? mb_substr($text, 0, 69).'…' : $text;
    }

    private function visibleMessage(\App\Models\AiMessage $message): bool
    {
        if ($message->role === 'user') {
            return true;
        }
        if ($message->role !== 'assistant' || ! empty($message->tool_calls)) {
            return false;
        }
        $text = trim((string) $message->content);
        if ($text === '') {
            return false;
        }
        if (str_starts_with($text, 'Agent failed:')) {
            return true;
        }

        return ! str_contains($text, '"tool"');
    }

    /**
     * @return array<string, mixed>
     */
    private function present(AiSession $session): array
    {
        $session->loadMissing(['messages', 'operations']);

        return [
            'id' => $session->id,
            'workspace_id' => $session->workspace_id,
            'model' => $session->model,
            'agent_type' => $session->agent_type,
            'status' => $session->status,
            'created_at' => $session->created_at?->toIso8601String(),
            'updated_at' => $session->updated_at?->toIso8601String(),
            'messages' => $session->messages
                ->filter(fn ($message) => $this->visibleMessage($message))
                ->values()
                ->map(fn ($message) => [
                    'id' => $message->id,
                    'role' => $message->role,
                    'content' => $message->content,
                    'created_at' => $message->created_at?->toIso8601String(),
                ]),
            'operations' => $session->operations->map(fn ($operation) => [
                'id' => $operation->id,
                'tool' => $operation->tool,
                'status' => $operation->status,
                'input' => $operation->input,
                'output' => $operation->output,
                'created_at' => $operation->created_at?->toIso8601String(),
            ]),
        ];
    }
}
