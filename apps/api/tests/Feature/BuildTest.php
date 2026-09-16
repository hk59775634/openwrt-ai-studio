<?php

namespace Tests\Feature;

use App\Models\BuildLog;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class BuildTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'studio.workspace_root' => storage_path('framework/testing/workspaces'),
            'studio.build_driver' => 'stub',
        ]);
        File::ensureDirectoryExists(config('studio.workspace_root'));
    }

    protected function tearDown(): void
    {
        File::deleteDirectory(storage_path('framework/testing/workspaces'));
        parent::tearDown();
    }

    public function test_app_workspace_can_compile_ipk(): void
    {
        $user = User::factory()->create();
        $id = $this->actingAs($user, 'sanctum')->postJson('/api/workspaces', [
            'name' => 'luci-app-vpn',
            'type' => 'app',
        ])->json('data.id');

        $build = $this->actingAs($user, 'sanctum')->postJson("/api/workspaces/{$id}/builds");
        $build->assertCreated()
            ->assertJsonPath('data.status', 'success')
            ->assertJsonPath('data.package_name', 'luci-app-vpn');

        $this->assertNotEmpty($build->json('data.artifacts.0.name'));
        $this->assertStringEndsWith('.ipk', (string) $build->json('data.artifacts.0.name'));

        $workspace = Workspace::query()->findOrFail($id);
        $artifact = $workspace->path.'/'.$build->json('data.artifacts.0.name');
        $onDisk = $workspace->path.'/artifacts/'.$build->json('data.id').'/'.$build->json('data.artifacts.0.name');
        $this->assertFileExists($onDisk);
        $this->assertSame('!<arch>', substr((string) file_get_contents($onDisk), 0, 7));

        $download = $this->actingAs($user, 'sanctum')->get($build->json('data.artifacts.0.download'));
        $download->assertOk();
        $this->assertStringContainsString('ipk', (string) $download->headers->get('content-disposition'));
        unset($artifact);
    }

    public function test_artifact_download_does_not_require_login(): void
    {
        $user = User::factory()->create();
        $id = $this->actingAs($user, 'sanctum')->postJson('/api/workspaces', [
            'name' => 'luci-app-public-dl',
            'type' => 'app',
        ])->json('data.id');
        $created = $this->actingAs($user, 'sanctum')->postJson("/api/workspaces/{$id}/builds")->assertCreated();
        $url = $created->json('data.artifacts.0.download');
        $buildId = $created->json('data.id');

        $this->app['auth']->forgetGuards();

        $this->get($url)
            ->assertOk()
            ->assertHeader('content-disposition');
        $this->getJson('/api/builds/'.$buildId)
            ->assertUnauthorized();
    }

    public function test_firmware_workspace_produces_image_and_foreign_user_is_denied(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $firmware = $this->actingAs($owner, 'sanctum')->postJson('/api/workspaces', [
            'name' => 'full-image',
            'type' => 'firmware',
        ])->json('data.id');

        $image = $this->actingAs($owner, 'sanctum')
            ->postJson("/api/workspaces/{$firmware}/builds")
            ->assertCreated()
            ->assertJsonPath('data.status', 'success')
            ->assertJsonPath('data.type', 'firmware');
        $this->assertStringContainsString('.img.gz', (string) $image->json('data.artifacts.0.name'));
        $this->assertTrue(
            BuildLog::query()
                ->where('build_id', $image->json('data.id'))
                ->where('content', 'like', '%固件打包成功%')
                ->exists()
        );

        $app = $this->actingAs($owner, 'sanctum')->postJson('/api/workspaces', [
            'name' => 'luci-app-safe',
            'type' => 'app',
        ])->json('data.id');
        $first = $this->actingAs($owner, 'sanctum')->postJson("/api/workspaces/{$app}/builds");
        $first->assertCreated();
        $second = $this->actingAs($owner, 'sanctum')->postJson("/api/workspaces/{$app}/builds");
        $second->assertCreated()->assertJsonPath('data.status', 'success');

        $this->actingAs($other, 'sanctum')
            ->getJson('/api/builds/'.$first->json('data.id'))
            ->assertForbidden();
    }

    public function test_owner_can_delete_finished_build_and_artifacts(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $id = $this->actingAs($owner, 'sanctum')->postJson('/api/workspaces', [
            'name' => 'luci-app-cleanup',
            'type' => 'app',
        ])->json('data.id');

        $first = $this->actingAs($owner, 'sanctum')->postJson("/api/workspaces/{$id}/builds")->assertCreated();
        $second = $this->actingAs($owner, 'sanctum')->postJson("/api/workspaces/{$id}/builds")->assertCreated();
        $firstId = $first->json('data.id');
        $artifact = $first->json('data.artifacts.0.name');
        $onDisk = Workspace::query()->findOrFail($id)->path.'/artifacts/'.$firstId.'/'.$artifact;
        $this->assertFileExists($onDisk);

        $this->actingAs($other, 'sanctum')
            ->deleteJson('/api/builds/'.$firstId)
            ->assertForbidden();
        $this->assertFileExists($onDisk);

        $this->actingAs($owner, 'sanctum')
            ->deleteJson('/api/builds/'.$firstId)
            ->assertOk()
            ->assertJsonPath('ok', true);

        $this->assertFileDoesNotExist($onDisk);
        $this->actingAs($owner, 'sanctum')
            ->getJson('/api/builds/'.$firstId)
            ->assertNotFound();
        $this->actingAs($owner, 'sanctum')
            ->getJson("/api/workspaces/{$id}/builds")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $second->json('data.id'));
    }

    public function test_build_list_omits_full_logs_and_logs_endpoint_returns_a_tail(): void
    {
        $user = User::factory()->create();
        $id = $this->actingAs($user, 'sanctum')->postJson('/api/workspaces', [
            'name' => 'luci-app-logs',
            'type' => 'app',
        ])->json('data.id');
        $created = $this->actingAs($user, 'sanctum')->postJson("/api/workspaces/{$id}/builds")->assertCreated();
        $buildId = $created->json('data.id');

        $start = (int) BuildLog::query()->where('build_id', $buildId)->max('sequence');
        for ($i = 1; $i <= 40; $i++) {
            BuildLog::query()->create([
                'build_id' => $buildId,
                'sequence' => $start + $i,
                'stream' => 'stdout',
                'content' => 'make line '.$i,
            ]);
        }

        $this->actingAs($user, 'sanctum')
            ->getJson("/api/workspaces/{$id}/builds")
            ->assertOk()
            ->assertJsonPath('data.0.logs', [])
            ->assertJsonPath('data.0.artifacts.0.name', $created->json('data.artifacts.0.name'));

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/builds/'.$buildId)
            ->assertOk()
            ->assertJsonPath('data.logs', []);

        $tail = $this->actingAs($user, 'sanctum')
            ->getJson('/api/builds/'.$buildId.'/logs?limit=10')
            ->assertOk()
            ->assertJsonPath('truncated', true);
        $this->assertCount(10, $tail->json('data'));
        $this->assertStringContainsString('make line 40', (string) $tail->json('data.9.content'));

        $after = (int) $tail->json('after');
        $this->actingAs($user, 'sanctum')
            ->getJson('/api/builds/'.$buildId.'/logs?after='.$after)
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_build_is_rejected_until_changes_are_committed(): void
    {
        $user = User::factory()->create();
        $id = $this->actingAs($user, 'sanctum')->postJson('/api/workspaces', [
            'name' => 'luci-app-commit-gate',
            'type' => 'app',
        ])->json('data.id');

        $this->actingAs($user, 'sanctum')
            ->putJson("/api/workspaces/{$id}/file", [
                'path' => 'README.md',
                'content' => "# Uncommitted\n",
            ])
            ->assertOk();

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/workspaces/{$id}/builds")
            ->assertStatus(422)
            ->assertJsonPath('message', 'Commit your changes before building.');

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/workspaces/{$id}/builds", ['type' => 'firmware'])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Commit your changes before building.');

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/workspaces/{$id}/git/commit", ['message' => 'Save README before the build'])
            ->assertCreated();

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/workspaces/{$id}/builds")
            ->assertCreated()
            ->assertJsonPath('data.status', 'success');
    }
}
