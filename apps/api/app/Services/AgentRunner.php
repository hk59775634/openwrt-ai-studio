<?php

namespace App\Services;

use App\Models\AiMessage;
use App\Models\AiSession;
use App\Models\AuditLog;
use App\Models\User;
use App\Models\Workspace;
use RuntimeException;
use Throwable;

class AgentRunner
{
    public function __construct(
        private readonly AiGateway $gateway,
        private readonly AgentToolExecutor $tools,
        private readonly GitService $git,
    ) {}

    public function beginTurn(AiSession $session, string $content): void
    {
        $session->update(['status' => 'running']);
        $session->messages()->create([
            'role' => 'user',
            'content' => $content,
        ]);
    }

    public function stop(AiSession $session): AiSession
    {
        if ($session->status !== 'running') {
            throw new RuntimeException('Agent is not running.');
        }

        $session->update(['status' => 'idle']);
        $session->messages()->create([
            'role' => 'assistant',
            'content' => 'Stopped.',
        ]);

        return $session->fresh()->load(['messages', 'operations']);
    }

    public function finishTurn(AiSession $session, User $user): void
    {
        $session->refresh();
        if ($session->status !== 'running') {
            return;
        }

        try {
            $this->runLoop($session, $user);
            if ($session->fresh()->status === 'running') {
                $session->update(['status' => 'idle']);
            }
        } catch (Throwable $e) {
            $session->refresh();
            if ($session->status !== 'running') {
                return;
            }
            $session->update(['status' => 'error']);
            $session->messages()->create([
                'role' => 'assistant',
                'content' => 'Agent failed: '.$e->getMessage(),
            ]);
        }

        $session->load('operations');

        AuditLog::query()->create([
            'user_id' => $user->id,
            'action' => 'ai.message',
            'resource' => $session->id,
            'metadata' => [
                'workspace_id' => $session->workspace_id,
                'model' => $session->model,
                'operations' => $session->operations->count(),
            ],
        ]);
    }

    /**
     * @return array{session: AiSession, diff: string}
     */
    public function send(AiSession $session, User $user, string $content): array
    {
        $this->beginTurn($session, $content);
        $this->finishTurn($session, $user);
        $session->refresh()->load(['messages', 'operations']);

        return [
            'session' => $session,
            'diff' => $this->git->diff($session->workspace),
        ];
    }

    public function createSession(Workspace $workspace, User $user, ?string $model = null): AiSession
    {
        $resolved = $model ?: $this->gateway->defaultModel();

        return AiSession::query()->create([
            'workspace_id' => $workspace->id,
            'user_id' => $user->id,
            'agent_type' => (string) config('studio.agent_type', 'studio'),
            'model' => $resolved,
            'status' => 'idle',
        ]);
    }

    private function runLoop(AiSession $session, User $user): void
    {
        $workspace = $session->workspace;
        $this->git->ensureRepository($workspace);
        $messages = $this->llmMessages($session, $workspace);
        $max = (int) config('studio.ai_max_iterations', 0);
        $unlimited = $max <= 0;
        $useTools = $this->gateway->supportsNativeTools();
        $nativeFailed = false;

        for ($i = 0; $unlimited || $i < $max; $i++) {
            if ($this->wasStopped($session)) {
                return;
            }

            $tools = ($useTools && ! $nativeFailed) ? $this->tools->definitions() : null;

            try {
                $choice = $this->requestChat($session, $messages, $tools);
            } catch (RuntimeException $e) {
                if ($this->wasStopped($session) || $e->getMessage() === 'Agent stopped.') {
                    return;
                }
                if ($tools && $this->looksLikeMissingTools($e->getMessage())) {
                    $nativeFailed = true;
                    $choice = $this->requestChat($session, $messages, null);
                } else {
                    throw $e;
                }
            }

            if ($this->wasStopped($session)) {
                return;
            }

            $toolCalls = $choice['tool_calls'] ?? null;
            if (is_array($toolCalls) && $toolCalls !== []) {
                $messages[] = $this->sanitizeChoice($choice);
                foreach ($toolCalls as $call) {
                    $result = $this->runToolCall($session, $workspace, $user, $call);
                    $messages[] = $result;
                }

                continue;
            }

            $text = trim((string) ($choice['content'] ?? ''));
            $action = $this->parseJsonAction($text);
            if ($action && isset($action['tool'])) {
                $messages[] = ['role' => 'assistant', 'content' => $text];
                $output = $this->tools->execute(
                    $session,
                    $workspace,
                    $user,
                    (string) $action['tool'],
                    is_array($action['args'] ?? null) ? $action['args'] : [],
                );
                $session->messages()->create([
                    'role' => 'tool',
                    'content' => $output,
                    'tool_call_id' => 'json',
                ]);
                $messages[] = ['role' => 'user', 'content' => "TOOL RESULT for {$action['tool']}:\n".$output];

                continue;
            }

            $reply = is_array($action) && isset($action['reply']) ? (string) $action['reply'] : $text;
            if ($reply === '') {
                $reply = 'Done. Check the diff for file changes.';
            }
            $this->upsertFinalAssistant($session, $reply);

            return;
        }

        $session->messages()->create([
            'role' => 'assistant',
            'content' => 'Stopped after '.$max.' tool steps. Review the diff and continue if needed.',
        ]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function llmMessages(AiSession $session, Workspace $workspace): array
    {
        $messages = [[
            'role' => 'system',
            'content' => $this->systemPrompt($workspace),
        ]];

        foreach ($session->messages()->orderBy('id')->get() as $message) {
            if (! $message instanceof AiMessage) {
                continue;
            }
            if ($message->role === 'tool') {
                $messages[] = [
                    'role' => 'tool',
                    'tool_call_id' => $message->tool_call_id ?: 'tool',
                    'content' => (string) $message->content,
                ];

                continue;
            }
            $row = [
                'role' => $message->role,
                'content' => (string) $message->content,
            ];
            if ($message->role === 'assistant' && is_array($message->tool_calls) && $message->tool_calls !== []) {
                $row['tool_calls'] = $message->tool_calls;
            }
            $messages[] = $row;
        }

        return $messages;
    }

    private function systemPrompt(Workspace $workspace): string
    {
        $tree = substr($this->tools->previewTree($workspace), 0, 2500);

        return <<<PROMPT
You are the OpenWrt AI Studio coding agent for one isolated workspace.
Project: {$workspace->name}
Type: {$workspace->type->value}
OpenWrt revision: {$workspace->openwrt_revision}
Working directory: /workspace

Current files:
{$tree}

Rules:
- Only read or modify files inside this workspace repository.
- Prefer small, reviewable edits. After writes, summarize the diff.
- Never access host paths, other workspaces, Docker, or secrets.
- Do not run make/feeds or destructive git commands. Use sandbox_exec only for ls, pwd, cat, head, wc, or read-only git.
- Do not commit unless the user explicitly asks; the IDE Commit button is preferred.
- If native tools are available, call them. Otherwise reply with a single JSON object:
  {"tool":"write_file","args":{"path":"README.md","content":"..."}}
  or {"reply":"markdown for the user"}
PROMPT;
    }

    /**
     * @param  list<array<string, mixed>>  $messages
     * @param  list<array<string, mixed>>|null  $tools
     * @return array<string, mixed>
     */
    private function requestChat(AiSession $session, array $messages, ?array $tools): array
    {
        $draft = null;
        $assembled = '';
        $lastFlush = 0.0;

        $flush = function (bool $force = false) use ($session, &$draft, &$assembled, &$lastFlush): void {
            if ($assembled === '') {
                return;
            }
            $now = microtime(true);
            if (! $force && $draft !== null && ($now - $lastFlush) < 0.12) {
                return;
            }
            if ($draft === null) {
                $draft = $session->messages()->create([
                    'role' => 'assistant',
                    'content' => $assembled,
                ]);
            } else {
                $draft->update(['content' => $assembled]);
            }
            $lastFlush = $now;
        };

        $choice = $this->gateway->chat(
            $session->model,
            $messages,
            $tools,
            function (string $delta, bool $reset = false) use ($session, &$assembled, &$draft, $flush): void {
                if ($reset) {
                    $assembled = '';
                    if ($draft !== null) {
                        $draft->update(['content' => '', 'tool_calls' => null]);
                    }

                    return;
                }
                if ($this->wasStopped($session)) {
                    throw new RuntimeException('Agent stopped.');
                }
                $assembled .= $delta;
                $flush();
            },
        );

        $flush(true);

        $content = isset($choice['content']) && is_string($choice['content']) ? $choice['content'] : $assembled;
        $toolCalls = $choice['tool_calls'] ?? null;
        $normalized = [
            'role' => 'assistant',
            'content' => $content,
            'tool_calls' => is_array($toolCalls) && $toolCalls !== [] ? $toolCalls : null,
        ];

        if ($draft !== null) {
            $draft->update([
                'content' => $normalized['content'],
                'tool_calls' => $normalized['tool_calls'],
            ]);
        } elseif ($normalized['content'] !== '' || $normalized['tool_calls'] !== null) {
            $this->storeAssistant($session, $normalized);
        }

        return $normalized;
    }

    /**
     * @param  array<string, mixed>  $choice
     */
    private function storeAssistant(AiSession $session, array $choice): void
    {
        $session->messages()->create([
            'role' => 'assistant',
            'content' => isset($choice['content']) && is_string($choice['content']) ? $choice['content'] : '',
            'tool_calls' => $choice['tool_calls'] ?? null,
        ]);
    }

    private function upsertFinalAssistant(AiSession $session, string $reply): void
    {
        $last = $session->messages()->orderByDesc('id')->first();
        if ($last instanceof AiMessage && $last->role === 'assistant' && empty($last->tool_calls)) {
            $last->update(['content' => $reply]);

            return;
        }

        $session->messages()->create([
            'role' => 'assistant',
            'content' => $reply,
        ]);
    }

    /**
     * @param  array<string, mixed>  $choice
     * @return array<string, mixed>
     */
    private function sanitizeChoice(array $choice): array
    {
        $row = [
            'role' => 'assistant',
            'content' => $choice['content'] ?? '',
        ];
        if (isset($choice['tool_calls'])) {
            $row['tool_calls'] = $choice['tool_calls'];
        }

        return $row;
    }

    /**
     * @param  array<string, mixed>  $call
     * @return array<string, mixed>
     */
    private function runToolCall(AiSession $session, Workspace $workspace, User $user, array $call): array
    {
        $id = (string) ($call['id'] ?? uniqid('call_', true));
        $name = (string) ($call['function']['name'] ?? $call['name'] ?? '');
        $raw = $call['function']['arguments'] ?? $call['arguments'] ?? '{}';
        $arguments = is_array($raw) ? $raw : json_decode((string) $raw, true);
        if (! is_array($arguments)) {
            $arguments = [];
        }

        $output = $this->tools->execute($session, $workspace, $user, $name, $arguments);
        $session->messages()->create([
            'role' => 'tool',
            'content' => $output,
            'tool_call_id' => $id,
        ]);

        return [
            'role' => 'tool',
            'tool_call_id' => $id,
            'content' => $output,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function parseJsonAction(string $text): ?array
    {
        $text = trim($text);
        if ($text === '') {
            return null;
        }
        if (preg_match('/```(?:json)?\s*(\{.*\})\s*```/s', $text, $match)) {
            $text = $match[1];
        }
        $start = strpos($text, '{');
        $end = strrpos($text, '}');
        if ($start === false || $end === false || $end <= $start) {
            return null;
        }
        $decoded = json_decode(substr($text, $start, $end - $start + 1), true);

        return is_array($decoded) ? $decoded : null;
    }

    private function looksLikeMissingTools(string $message): bool
    {
        return (bool) preg_match('/tool|function.?call|unsupported|400|422/i', $message);
    }

    private function wasStopped(AiSession $session): bool
    {
        $session->refresh();

        return $session->status !== 'running';
    }
}
