<?php

namespace Tests\Feature;

use App\Jobs\RunAgentTurnJob;
use App\Models\AiSession;
use App\Models\User;
use App\Models\Workspace;
use App\Services\AgentRunner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AiSessionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'studio.workspace_root' => storage_path('framework/testing/workspaces'),
            'studio.sandbox_driver' => 'local',
            'studio.ai_tool_mode' => 'openai',
            'studio.ai_gateway_model' => 'test-model',
        ]);
        File::ensureDirectoryExists(config('studio.workspace_root'));
    }

    protected function tearDown(): void
    {
        File::deleteDirectory(storage_path('framework/testing/workspaces'));
        parent::tearDown();
    }

    public function test_agent_can_write_file_and_records_operation(): void
    {
        Http::fake([
            '*/v1/models' => Http::response(['data' => [['id' => 'test-model']]]),
            '*/v1/chat/completions' => Http::sequence()
                ->push($this->toolCallResponse('call_write', 'write_file', [
                    'path' => 'NOTES.md',
                    'content' => "# M3\n\nEdited by the agent.\n",
                ]))
                ->push($this->textResponse('Created NOTES.md with a short project note.')),
        ]);

        $user = User::factory()->create();
        $id = $this->actingAs($user, 'sanctum')->postJson('/api/workspaces', [
            'name' => 'luci-app-ai',
            'type' => 'app',
        ])->json('data.id');

        $session = $this->actingAs($user, 'sanctum')->postJson("/api/workspaces/{$id}/ai/sessions", [
            'model' => 'test-model',
        ]);
        $session->assertCreated();
        $sessionId = $session->json('data.id');

        $reply = $this->actingAs($user, 'sanctum')->postJson("/api/ai/sessions/{$sessionId}/messages", [
            'content' => 'Add NOTES.md describing this workspace.',
        ]);

        $reply->assertOk()
            ->assertJsonPath('data.status', 'idle')
            ->assertJsonPath('data.operations.0.tool', 'write_file')
            ->assertJsonPath('data.operations.0.status', 'ok');

        $this->assertStringContainsString('Created NOTES.md', (string) $reply->json('data.messages.1.content'));
        $this->assertStringContainsString('Edited by the agent', (string) $reply->json('diff'));

        $workspace = Workspace::query()->findOrFail($id);
        $this->assertFileExists($workspace->path.'/repo/NOTES.md');
        $this->assertStringContainsString('Edited by the agent', File::get($workspace->path.'/repo/NOTES.md'));
    }

    public function test_agent_rejects_path_escape_and_foreign_session(): void
    {
        Http::fake([
            '*/v1/chat/completions' => Http::sequence()
                ->push($this->toolCallResponse('call_bad', 'write_file', [
                    'path' => '../.workspace/workspace.json',
                    'content' => 'nope',
                ]))
                ->push($this->textResponse('I could not write outside the repository.')),
        ]);

        $owner = User::factory()->create();
        $other = User::factory()->create();
        $id = $this->actingAs($owner, 'sanctum')->postJson('/api/workspaces', [
            'name' => 'safe-ai',
            'type' => 'app',
        ])->json('data.id');

        $sessionId = $this->actingAs($owner, 'sanctum')
            ->postJson("/api/workspaces/{$id}/ai/sessions")
            ->json('data.id');

        $reply = $this->actingAs($owner, 'sanctum')->postJson("/api/ai/sessions/{$sessionId}/messages", [
            'content' => 'Overwrite workspace.json one directory up.',
        ]);
        $reply->assertOk()->assertJsonPath('data.operations.0.status', 'error');
        $this->assertStringContainsString('Invalid path', (string) $reply->json('data.operations.0.output'));

        $this->actingAs($other, 'sanctum')
            ->postJson("/api/ai/sessions/{$sessionId}/messages", ['content' => 'hello'])
            ->assertForbidden();
    }

    public function test_sandbox_exec_allowlist(): void
    {
        Http::fake([
            '*/v1/chat/completions' => Http::sequence()
                ->push($this->toolCallResponse('call_exec', 'sandbox_exec', [
                    'argv' => ['pwd'],
                ]))
                ->push($this->textResponse('The sandbox working directory is /workspace.')),
        ]);

        $user = User::factory()->create();
        $id = $this->actingAs($user, 'sanctum')->postJson('/api/workspaces', [
            'name' => 'sbx',
            'type' => 'theme',
        ])->json('data.id');
        $sessionId = $this->actingAs($user, 'sanctum')
            ->postJson("/api/workspaces/{$id}/ai/sessions")
            ->json('data.id');

        $reply = $this->actingAs($user, 'sanctum')->postJson("/api/ai/sessions/{$sessionId}/messages", [
            'content' => 'What is the sandbox cwd?',
        ]);
        $reply->assertOk()->assertJsonPath('data.operations.0.status', 'ok');
        $this->assertStringContainsString('/workspace', (string) $reply->json('data.operations.0.output'));
    }

    public function test_agent_turn_is_queued_and_rejects_overlap(): void
    {
        Bus::fake();

        $user = User::factory()->create();
        $id = $this->actingAs($user, 'sanctum')->postJson('/api/workspaces', [
            'name' => 'queued-ai',
            'type' => 'app',
        ])->json('data.id');
        $sessionId = $this->actingAs($user, 'sanctum')
            ->postJson("/api/workspaces/{$id}/ai/sessions")
            ->json('data.id');

        $reply = $this->actingAs($user, 'sanctum')->postJson("/api/ai/sessions/{$sessionId}/messages", [
            'content' => 'Start a long agent turn.',
        ]);
        $reply->assertOk()->assertJsonPath('data.status', 'running');
        $this->assertSame('Start a long agent turn.', $reply->json('data.messages.0.content'));
        Bus::assertDispatched(RunAgentTurnJob::class);

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/ai/sessions/{$sessionId}/messages", ['content' => 'another'])
            ->assertStatus(409);
    }

    public function test_user_can_stop_a_running_turn(): void
    {
        Bus::fake();

        $user = User::factory()->create();
        $other = User::factory()->create();
        $id = $this->actingAs($user, 'sanctum')->postJson('/api/workspaces', [
            'name' => 'stop-ai',
            'type' => 'app',
        ])->json('data.id');
        $sessionId = $this->actingAs($user, 'sanctum')
            ->postJson("/api/workspaces/{$id}/ai/sessions")
            ->json('data.id');

        $this->actingAs($user, 'sanctum')->postJson("/api/ai/sessions/{$sessionId}/messages", [
            'content' => 'Start a long agent turn.',
        ])->assertOk()->assertJsonPath('data.status', 'running');

        $this->actingAs($other, 'sanctum')
            ->postJson("/api/ai/sessions/{$sessionId}/stop")
            ->assertForbidden();

        $stopped = $this->actingAs($user, 'sanctum')
            ->postJson("/api/ai/sessions/{$sessionId}/stop")
            ->assertOk();
        $this->assertSame('idle', $stopped->json('data.status'));
        $messages = $stopped->json('data.messages');
        $this->assertSame('Stopped.', end($messages)['content']);

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/ai/sessions/{$sessionId}/stop")
            ->assertStatus(422);

        app(AgentRunner::class)->finishTurn(
            AiSession::query()->findOrFail($sessionId),
            $user,
        );
        $afterJob = $this->actingAs($user, 'sanctum')
            ->getJson("/api/ai/sessions/{$sessionId}")
            ->assertOk();
        $this->assertSame('idle', $afterJob->json('data.status'));
        $afterMessages = $afterJob->json('data.messages');
        $this->assertSame('Stopped.', end($afterMessages)['content']);

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/ai/sessions/{$sessionId}/messages", ['content' => 'Continue after stop.'])
            ->assertOk()
            ->assertJsonPath('data.status', 'running');
    }

    public function test_user_can_list_and_delete_conversation_history(): void
    {
        Bus::fake();

        $user = User::factory()->create();
        $other = User::factory()->create();
        $id = $this->actingAs($user, 'sanctum')->postJson('/api/workspaces', [
            'name' => 'history-ai',
            'type' => 'app',
        ])->json('data.id');

        $first = $this->actingAs($user, 'sanctum')
            ->postJson("/api/workspaces/{$id}/ai/sessions")
            ->json('data.id');
        $this->actingAs($user, 'sanctum')->postJson("/api/ai/sessions/{$first}/messages", [
            'content' => 'Explain the Makefile.',
        ]);
        $second = $this->actingAs($user, 'sanctum')
            ->postJson("/api/workspaces/{$id}/ai/sessions")
            ->json('data.id');

        $list = $this->actingAs($user, 'sanctum')
            ->getJson("/api/workspaces/{$id}/ai/sessions")
            ->assertOk()
            ->assertJsonCount(2, 'data');
        $this->assertSame($second, $list->json('data.0.id'));
        $this->assertSame('New chat', $list->json('data.0.preview'));
        $this->assertSame($first, $list->json('data.1.id'));
        $this->assertSame('Explain the Makefile.', $list->json('data.1.preview'));

        $this->actingAs($other, 'sanctum')
            ->deleteJson("/api/ai/sessions/{$first}")
            ->assertForbidden();

        $this->actingAs($user, 'sanctum')
            ->deleteJson("/api/ai/sessions/{$first}")
            ->assertOk()
            ->assertJsonPath('ok', true);

        $this->actingAs($user, 'sanctum')
            ->getJson("/api/workspaces/{$id}/ai/sessions")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $second);

        $this->actingAs($user, 'sanctum')
            ->getJson("/api/ai/sessions/{$first}")
            ->assertNotFound();
    }

    public function test_agent_stops_after_max_tool_iterations(): void
    {
        config(['studio.ai_max_iterations' => 2]);

        Http::fake([
            '*/v1/models' => Http::response(['data' => [['id' => 'test-model']]]),
            '*/v1/chat/completions' => Http::sequence()
                ->push($this->toolCallResponse('call_1', 'write_file', [
                    'path' => 'ONE.md',
                    'content' => "one\n",
                ]))
                ->push($this->toolCallResponse('call_2', 'write_file', [
                    'path' => 'TWO.md',
                    'content' => "two\n",
                ])),
        ]);

        $user = User::factory()->create();
        $id = $this->actingAs($user, 'sanctum')->postJson('/api/workspaces', [
            'name' => 'cap-ai',
            'type' => 'app',
        ])->json('data.id');
        $sessionId = $this->actingAs($user, 'sanctum')
            ->postJson("/api/workspaces/{$id}/ai/sessions", ['model' => 'test-model'])
            ->json('data.id');

        $reply = $this->actingAs($user, 'sanctum')->postJson("/api/ai/sessions/{$sessionId}/messages", [
            'content' => 'Edit several files.',
        ]);

        $messages = $reply->json('data.messages');
        $reply->assertOk()->assertJsonPath('data.status', 'idle');
        $this->assertSame(
            'Stopped after 2 tool steps. Review the diff and continue if needed.',
            end($messages)['content'],
        );
    }

    public function test_agent_with_zero_max_iterations_keeps_going_until_a_reply(): void
    {
        config(['studio.ai_max_iterations' => 0]);

        Http::fake([
            '*/v1/models' => Http::response(['data' => [['id' => 'test-model']]]),
            '*/v1/chat/completions' => Http::sequence()
                ->push($this->toolCallResponse('call_1', 'write_file', [
                    'path' => 'ONE.md',
                    'content' => "one\n",
                ]))
                ->push($this->toolCallResponse('call_2', 'write_file', [
                    'path' => 'TWO.md',
                    'content' => "two\n",
                ]))
                ->push($this->toolCallResponse('call_3', 'write_file', [
                    'path' => 'THREE.md',
                    'content' => "three\n",
                ]))
                ->push($this->textResponse('Wrote three files.')),
        ]);

        $user = User::factory()->create();
        $id = $this->actingAs($user, 'sanctum')->postJson('/api/workspaces', [
            'name' => 'uncapped-ai',
            'type' => 'app',
        ])->json('data.id');
        $sessionId = $this->actingAs($user, 'sanctum')
            ->postJson("/api/workspaces/{$id}/ai/sessions", ['model' => 'test-model'])
            ->json('data.id');

        $reply = $this->actingAs($user, 'sanctum')->postJson("/api/ai/sessions/{$sessionId}/messages", [
            'content' => 'Edit several files.',
        ]);

        $messages = $reply->json('data.messages');
        $reply->assertOk()->assertJsonPath('data.status', 'idle');
        $this->assertSame('Wrote three files.', end($messages)['content']);
        $this->assertCount(3, $reply->json('data.operations'));
    }

    public function test_agent_retries_transient_gateway_errors(): void
    {
        config(['studio.ai_gateway_retries' => 2]);

        Http::fake([
            '*/v1/models' => Http::response(['data' => [['id' => 'test-model']]]),
            '*/v1/chat/completions' => Http::sequence()
                ->push('error code: 504', 504)
                ->push($this->textResponse('Recovered after a gateway timeout.')),
        ]);

        $user = User::factory()->create();
        $id = $this->actingAs($user, 'sanctum')->postJson('/api/workspaces', [
            'name' => 'retry-ai',
            'type' => 'app',
        ])->json('data.id');
        $sessionId = $this->actingAs($user, 'sanctum')
            ->postJson("/api/workspaces/{$id}/ai/sessions", ['model' => 'test-model'])
            ->json('data.id');

        $reply = $this->actingAs($user, 'sanctum')->postJson("/api/ai/sessions/{$sessionId}/messages", [
            'content' => 'Say hello.',
        ]);

        $messages = $reply->json('data.messages');
        $reply->assertOk()->assertJsonPath('data.status', 'idle');
        $this->assertSame('Recovered after a gateway timeout.', end($messages)['content']);
        $this->assertSame(2, $this->chatRequestCount());
    }

    public function test_agent_does_not_retry_client_errors(): void
    {
        config(['studio.ai_gateway_retries' => 3]);

        Http::fake([
            '*/v1/models' => Http::response(['data' => [['id' => 'test-model']]]),
            '*/v1/chat/completions' => Http::response('invalid api key', 401),
        ]);

        $user = User::factory()->create();
        $id = $this->actingAs($user, 'sanctum')->postJson('/api/workspaces', [
            'name' => 'auth-ai',
            'type' => 'app',
        ])->json('data.id');
        $sessionId = $this->actingAs($user, 'sanctum')
            ->postJson("/api/workspaces/{$id}/ai/sessions", ['model' => 'test-model'])
            ->json('data.id');

        $reply = $this->actingAs($user, 'sanctum')->postJson("/api/ai/sessions/{$sessionId}/messages", [
            'content' => 'Say hello.',
        ]);

        $messages = $reply->json('data.messages');
        $reply->assertOk()->assertJsonPath('data.status', 'error');
        $this->assertStringContainsString('HTTP 401', (string) end($messages)['content']);
        $this->assertSame(1, $this->chatRequestCount());
    }

    public function test_agent_streams_sse_deltas_into_the_visible_reply(): void
    {
        $sse = implode("\n", [
            'data: {"choices":[{"delta":{"role":"assistant","content":"Hel"}}]}',
            'data: {"choices":[{"delta":{"content":"lo from SSE."}}]}',
            'data: [DONE]',
            '',
        ]);

        Http::fake([
            '*/v1/models' => Http::response(['data' => [['id' => 'test-model']]]),
            '*/v1/chat/completions' => Http::response($sse, 200, ['Content-Type' => 'text/event-stream']),
        ]);

        $user = User::factory()->create();
        $id = $this->actingAs($user, 'sanctum')->postJson('/api/workspaces', [
            'name' => 'stream-ai',
            'type' => 'app',
        ])->json('data.id');
        $sessionId = $this->actingAs($user, 'sanctum')
            ->postJson("/api/workspaces/{$id}/ai/sessions", ['model' => 'test-model'])
            ->json('data.id');

        $reply = $this->actingAs($user, 'sanctum')->postJson("/api/ai/sessions/{$sessionId}/messages", [
            'content' => 'Say hello.',
        ]);

        $messages = $reply->json('data.messages');
        $reply->assertOk()->assertJsonPath('data.status', 'idle');
        $this->assertSame('Hello from SSE.', end($messages)['content']);
        Http::assertSent(fn ($request): bool => str_contains($request->url(), '/v1/chat/completions') && $request['stream'] === true);
    }

    public function test_agent_streams_tool_call_deltas(): void
    {
        $sse = implode("\n", [
            'data: {"choices":[{"delta":{"tool_calls":[{"index":0,"id":"call_stream","type":"function","function":{"name":"write_file","arguments":""}}]}}]}',
            'data: {"choices":[{"delta":{"tool_calls":[{"index":0,"function":{"arguments":"{\\"path\\":\\"STREAM.md\\",\\"content\\":\\"streamed\\\\n\\"}"}}]}}]}',
            'data: [DONE]',
            '',
        ]);

        Http::fake([
            '*/v1/models' => Http::response(['data' => [['id' => 'test-model']]]),
            '*/v1/chat/completions' => Http::sequence()
                ->push($sse, 200, ['Content-Type' => 'text/event-stream'])
                ->push($this->textResponse('Wrote STREAM.md.')),
        ]);

        $user = User::factory()->create();
        $id = $this->actingAs($user, 'sanctum')->postJson('/api/workspaces', [
            'name' => 'stream-tools',
            'type' => 'app',
        ])->json('data.id');
        $sessionId = $this->actingAs($user, 'sanctum')
            ->postJson("/api/workspaces/{$id}/ai/sessions", ['model' => 'test-model'])
            ->json('data.id');

        $reply = $this->actingAs($user, 'sanctum')->postJson("/api/ai/sessions/{$sessionId}/messages", [
            'content' => 'Write STREAM.md.',
        ]);

        $reply->assertOk()
            ->assertJsonPath('data.status', 'idle')
            ->assertJsonPath('data.operations.0.tool', 'write_file')
            ->assertJsonPath('data.operations.0.status', 'ok');
        $messages = $reply->json('data.messages');
        $this->assertSame('Wrote STREAM.md.', end($messages)['content']);

        $workspace = Workspace::query()->findOrFail($id);
        $this->assertFileExists($workspace->path.'/repo/STREAM.md');
        $this->assertSame("streamed\n", File::get($workspace->path.'/repo/STREAM.md'));
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    private function toolCallResponse(string $id, string $name, array $arguments): array
    {
        return [
            'choices' => [[
                'message' => [
                    'role' => 'assistant',
                    'content' => null,
                    'tool_calls' => [[
                        'id' => $id,
                        'type' => 'function',
                        'function' => [
                            'name' => $name,
                            'arguments' => json_encode($arguments),
                        ],
                    ]],
                ],
            ]],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function textResponse(string $text): array
    {
        return [
            'choices' => [[
                'message' => [
                    'role' => 'assistant',
                    'content' => $text,
                ],
            ]],
        ];
    }

    private function chatRequestCount(): int
    {
        return collect(Http::recorded())
            ->filter(fn (array $pair): bool => str_contains($pair[0]->url(), '/v1/chat/completions'))
            ->count();
    }
}
