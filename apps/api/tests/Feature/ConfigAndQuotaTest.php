<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class ConfigAndQuotaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'studio.workspace_root' => storage_path('framework/testing/workspaces'),
            'studio.build_driver' => 'stub',
            'studio.artifact_store' => 'local',
        ]);
        File::ensureDirectoryExists(config('studio.workspace_root'));
    }

    protected function tearDown(): void
    {
        File::deleteDirectory(storage_path('framework/testing/workspaces'));
        parent::tearDown();
    }

    public function test_config_snapshots_can_be_saved_applied_and_compared(): void
    {
        $user = User::factory()->create();
        $id = $this->actingAs($user, 'sanctum')->postJson('/api/workspaces', [
            'name' => 'luci-app-cfg',
            'type' => 'app',
        ])->json('data.id');

        $this->actingAs($user, 'sanctum')->getJson('/api/devices')
            ->assertOk()
            ->assertJsonFragment(['target' => 'x86'])
            ->assertJsonFragment(['target' => 'ramips'])
            ->assertJsonFragment(['subtarget' => 'filogic'])
            ->assertJsonFragment(['subtarget' => 'mt7628'])
            ->assertJsonFragment(['source' => 'mtk-mt7628']);

        $first = $this->actingAs($user, 'sanctum')->postJson("/api/workspaces/{$id}/configs", [
            'name' => 'x86-generic',
            'target' => 'x86',
            'subtarget' => '64',
            'profile' => 'generic',
        ]);
        $first->assertCreated()->assertJsonPath('data.name', 'x86-generic');

        $second = $this->actingAs($user, 'sanctum')->postJson("/api/workspaces/{$id}/configs", [
            'name' => 'ax6000',
            'target' => 'mediatek',
            'subtarget' => 'filogic',
            'profile' => 'xiaomi_redmi-router-ax6000',
            'content' => "CONFIG_TARGET_mediatek=y\nCONFIG_PACKAGE_luci=y\n",
        ]);
        $second->assertCreated();

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/workspaces/{$id}/configs/".$second->json('data.id').'/apply')
            ->assertOk();

        $this->actingAs($user, 'sanctum')
            ->getJson("/api/workspaces/{$id}")
            ->assertJsonPath('data.target', 'mediatek')
            ->assertJsonPath('data.profile', 'xiaomi_redmi-router-ax6000');

        $this->actingAs($user, 'sanctum')
            ->getJson("/api/workspaces/{$id}/file?path=.config")
            ->assertOk()
            ->assertJsonPath('data.content', fn ($content) => str_contains((string) $content, 'CONFIG_TARGET_mediatek=y'));

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/workspaces/{$id}/configs/compare", [
                'left' => $first->json('data.id'),
                'right' => $second->json('data.id'),
            ])
            ->assertOk()
            ->assertJsonPath('data.left', 'x86-generic');

        $firstId = $first->json('data.id');
        $this->actingAs($user, 'sanctum')
            ->deleteJson("/api/workspaces/{$id}/configs/{$firstId}")
            ->assertOk()
            ->assertJsonPath('ok', true);

        $this->actingAs($user, 'sanctum')
            ->getJson("/api/workspaces/{$id}/configs")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonMissing(['name' => 'x86-generic']);

        $other = User::factory()->create();
        $this->actingAs($other, 'sanctum')
            ->deleteJson("/api/workspaces/{$id}/configs/".$second->json('data.id'))
            ->assertForbidden();

        $this->actingAs($user, 'sanctum')->getJson('/api/queue')
            ->assertOk()
            ->assertJsonPath('data.workers', 2);
    }

    public function test_workspace_quota_is_enforced(): void
    {
        config(['studio.quota_workspaces' => 1]);
        $user = User::factory()->create();

        $this->actingAs($user, 'sanctum')->postJson('/api/workspaces', [
            'name' => 'one',
            'type' => 'app',
        ])->assertCreated();

        $this->actingAs($user, 'sanctum')->postJson('/api/workspaces', [
            'name' => 'two',
            'type' => 'app',
        ])->assertStatus(422)->assertJsonPath('message', 'Workspace quota reached (1).');
    }

    public function test_build_hourly_quota_is_enforced(): void
    {
        config(['studio.quota_builds_per_hour' => 1]);
        $user = User::factory()->create();
        $id = $this->actingAs($user, 'sanctum')->postJson('/api/workspaces', [
            'name' => 'luci-app-limit',
            'type' => 'app',
        ])->json('data.id');

        $this->actingAs($user, 'sanctum')->postJson("/api/workspaces/{$id}/builds")->assertCreated();
        $this->actingAs($user, 'sanctum')
            ->postJson("/api/workspaces/{$id}/builds")
            ->assertStatus(422)
            ->assertJsonPath('message', 'Build quota reached (1 per hour).');
    }
}
