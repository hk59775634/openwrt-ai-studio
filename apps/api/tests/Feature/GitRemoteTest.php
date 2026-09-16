<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\WorkspaceFilesystem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Tests\TestCase;

class GitRemoteTest extends TestCase
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

    public function test_workspace_can_be_created_from_git_url(): void
    {
        $source = $this->seedRepo('source-app', "# From git\n");
        $user = User::factory()->create();

        $create = $this->actingAs($user, 'sanctum')->postJson('/api/workspaces', [
            'name' => 'imported',
            'type' => 'app',
            'git_url' => $source,
            'git_branch' => 'main',
        ]);

        $create->assertCreated()
            ->assertJsonPath('data.name', 'imported')
            ->assertJsonPath('data.git_remote_url', $source);

        $id = $create->json('data.id');
        $this->assertFileExists((new WorkspaceFilesystem)->pathFor($id).'/repo/HELLO.md');
        $this->assertSame("# From git\n", File::get((new WorkspaceFilesystem)->pathFor($id).'/repo/HELLO.md'));
    }

    public function test_rejects_disallowed_git_scheme(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user, 'sanctum')->postJson('/api/workspaces', [
            'name' => 'bad-git',
            'type' => 'app',
            'git_url' => 'file:///etc/passwd',
        ])->assertStatus(422);
    }

    public function test_bind_push_pull_and_release_to_remote(): void
    {
        $user = User::factory()->create();
        $id = $this->actingAs($user, 'sanctum')->postJson('/api/workspaces', [
            'name' => 'push-me',
            'type' => 'app',
        ])->json('data.id');

        $bare = storage_path('framework/testing/workspaces/bare.git');
        File::ensureDirectoryExists(dirname($bare));
        $this->git(dirname($bare), ['git', 'init', '--bare', $bare]);

        $this->actingAs($user, 'sanctum')
            ->putJson("/api/workspaces/{$id}/git/remote", [
                'url' => $bare,
                'branch' => 'main',
            ])
            ->assertOk()
            ->assertJsonPath('data.git_remote_url', $bare);

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/workspaces/{$id}/git/push")
            ->assertOk()
            ->assertJsonPath('data.branch', 'main');

        $remoteLog = $this->git($bare, ['git', 'log', '-1', '--pretty=%s', 'main']);
        $this->assertStringContainsString('Initial workspace', $remoteLog);

        $this->actingAs($user, 'sanctum')
            ->putJson("/api/workspaces/{$id}/file", [
                'path' => 'README.md',
                'content' => "# pushed\n",
            ])
            ->assertOk();
        $this->actingAs($user, 'sanctum')
            ->postJson("/api/workspaces/{$id}/git/commit", ['message' => 'Prepare release'])
            ->assertCreated();
        $this->actingAs($user, 'sanctum')
            ->postJson("/api/workspaces/{$id}/git/push")
            ->assertOk();

        $this->actingAs($user, 'sanctum')
            ->putJson("/api/workspaces/{$id}/file", [
                'path' => 'README.md',
                'content' => "# local only\n",
            ])
            ->assertOk();
        $this->actingAs($user, 'sanctum')
            ->postJson("/api/workspaces/{$id}/git/commit", ['message' => 'Local commit not pushed'])
            ->assertCreated();

        $pullWhileAhead = $this->actingAs($user, 'sanctum')
            ->postJson("/api/workspaces/{$id}/git/pull")
            ->assertOk();
        $this->assertGreaterThanOrEqual(1, (int) $pullWhileAhead->json('data.ahead'));
        $this->assertSame(0, (int) $pullWhileAhead->json('data.behind'));

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/workspaces/{$id}/git/push")
            ->assertOk();

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/workspaces/{$id}/git/release", ['tag' => 'v1.0.0', 'message' => 'First release'])
            ->assertCreated()
            ->assertJsonPath('data.tag', 'v1.0.0')
            ->assertJsonPath('data.html_url', null);

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/workspaces/{$id}/git/release", ['tag' => 'v1.0.0', 'message' => 'First release'])
            ->assertCreated()
            ->assertJsonPath('data.tag', 'v1.0.0');

        $tags = $this->git($bare, ['git', 'tag']);
        $this->assertStringContainsString('v1.0.0', $tags);

        $clone = $this->seedEmptyDir('pull-clone');
        $this->git(dirname($clone), ['git', 'clone', '--branch', 'main', $bare, $clone]);
        $this->assertStringContainsString('# local only', File::get($clone.'/README.md'));
        File::put($clone.'/README.md', "# from remote\n");
        $this->git($clone, ['git', 'config', 'user.email', 'studio@test.local']);
        $this->git($clone, ['git', 'config', 'user.name', 'Studio Test']);
        $this->git($clone, ['git', 'add', '-A']);
        $this->git($clone, ['git', 'commit', '-m', 'remote change']);
        $this->git($clone, ['git', 'push', 'origin', 'main']);

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/workspaces/{$id}/git/pull")
            ->assertOk();
        $this->assertStringContainsString('# from remote', File::get(
            (new WorkspaceFilesystem)->pathFor($id).'/repo/README.md'
        ));
    }

    private function seedRepo(string $name, string $contents): string
    {
        $path = storage_path('framework/testing/workspaces/'.$name);
        File::deleteDirectory($path);
        File::ensureDirectoryExists($path);
        $this->git($path, ['git', 'init', '-b', 'main']);
        $this->git($path, ['git', 'config', 'user.email', 'studio@test.local']);
        $this->git($path, ['git', 'config', 'user.name', 'Studio Test']);
        File::put($path.'/HELLO.md', $contents);
        $this->git($path, ['git', 'add', '-A']);
        $this->git($path, ['git', 'commit', '-m', 'seed']);

        return $path;
    }

    private function seedEmptyDir(string $name): string
    {
        $path = storage_path('framework/testing/workspaces/'.$name);
        File::deleteDirectory($path);

        return $path;
    }

    /**
     * @param  list<string>  $command
     */
    private function git(string $cwd, array $command): string
    {
        File::ensureDirectoryExists($cwd);
        $result = Process::path($cwd)->timeout(20)->run($command);
        $this->assertTrue($result->successful(), trim($result->errorOutput().$result->output()));

        return $result->output();
    }
}
