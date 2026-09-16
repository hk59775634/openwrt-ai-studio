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
}
