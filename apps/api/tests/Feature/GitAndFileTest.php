<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class GitAndFileTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['studio.workspace_root' => storage_path('framework/testing/workspaces')]);
        File::ensureDirectoryExists(config('studio.workspace_root'));
    }

    protected function tearDown(): void
    {
        File::deleteDirectory(storage_path('framework/testing/workspaces'));
        parent::tearDown();
    }

    public function test_user_can_edit_diff_and_commit(): void
    {
        $user = User::factory()->create(['name' => 'Ada']);
        $id = $this->actingAs($user, 'sanctum')->postJson('/api/workspaces', [
            'name' => 'luci-app-vpn',
            'type' => 'app',
        ])->json('data.id');

        $this->actingAs($user, 'sanctum')
            ->getJson("/api/workspaces/{$id}/files")
            ->assertOk()
            ->assertJsonFragment(['name' => 'README.md']);

        $this->actingAs($user, 'sanctum')
            ->putJson("/api/workspaces/{$id}/file", [
                'path' => 'README.md',
                'content' => "# VPN\n\nedited\n",
            ])
            ->assertOk();

        $this->actingAs($user, 'sanctum')
            ->getJson("/api/workspaces/{$id}/git/diff")
            ->assertOk()
            ->assertJsonPath('data.diff', fn ($diff) => str_contains((string) $diff, 'edited'));

        $commit = $this->actingAs($user, 'sanctum')->postJson("/api/workspaces/{$id}/git/commit", [
            'message' => 'Document the VPN app',
        ]);
        $commit->assertCreated()->assertJsonPath('data.message', 'Document the VPN app');
        $this->assertNotEmpty($commit->json('data.sha'));

        $this->actingAs($user, 'sanctum')
            ->getJson("/api/workspaces/{$id}/git/status")
            ->assertOk()
            ->assertJsonPath('data.clean', true);
    }

    public function test_file_api_rejects_path_escape_and_terminal_rejects_shell(): void
    {
        $user = User::factory()->create();
        $id = $this->actingAs($user, 'sanctum')->postJson('/api/workspaces', [
            'name' => 'safe',
            'type' => 'theme',
        ])->json('data.id');

        $this->actingAs($user, 'sanctum')
            ->getJson("/api/workspaces/{$id}/file?path=".urlencode('../.workspace/workspace.json'))
            ->assertStatus(422);

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/workspaces/{$id}/terminal", ['command' => 'rm -rf /'])
            ->assertStatus(422);

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/workspaces/{$id}/terminal", ['command' => 'ls'])
            ->assertOk()
            ->assertJsonPath('data.cwd', '/workspace');
    }
}
