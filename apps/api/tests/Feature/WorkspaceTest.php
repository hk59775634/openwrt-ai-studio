<?php

namespace Tests\Feature;

use App\Enums\ProjectType;
use App\Models\User;
use App\Services\WorkspaceFilesystem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class WorkspaceTest extends TestCase
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

    public function test_user_can_create_open_and_delete_workspace(): void
    {
        $user = User::factory()->create();

        $create = $this->actingAs($user, 'sanctum')->postJson('/api/workspaces', [
            'name' => 'luci-app-demo',
            'type' => ProjectType::App->value,
            'openwrt_revision' => '24.10',
        ]);

        $create->assertCreated()
            ->assertJsonPath('data.name', 'luci-app-demo')
            ->assertJsonPath('data.status', 'ready');

        $id = $create->json('data.id');
        $this->assertDirectoryExists((new WorkspaceFilesystem)->pathFor($id).'/repo');
        $this->assertFileExists((new WorkspaceFilesystem)->pathFor($id).'/.workspace/workspace.json');
        $this->assertFileExists((new WorkspaceFilesystem)->pathFor($id).'/repo/.config');
        $create->assertJsonPath('data.target', 'x86');
        $this->actingAs($user, 'sanctum')
            ->getJson('/api/workspaces/'.$id.'/files')
            ->assertOk()
            ->assertJsonFragment(['name' => '.config']);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/workspaces/'.$id)
            ->assertOk()
            ->assertJsonPath('data.id', $id);

        $this->actingAs($user, 'sanctum')
            ->deleteJson('/api/workspaces/'.$id)
            ->assertOk();

        $this->assertDirectoryDoesNotExist((new WorkspaceFilesystem)->pathFor($id));
        $this->assertDatabaseMissing('workspaces', ['id' => $id]);
    }

    public function test_workspace_is_isolated_per_user(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();

        $id = $this->actingAs($owner, 'sanctum')->postJson('/api/workspaces', [
            'name' => 'owner-only',
            'type' => 'theme',
        ])->json('data.id');

        $this->actingAs($other, 'sanctum')
            ->getJson('/api/workspaces/'.$id)
            ->assertForbidden();

        $this->actingAs($other, 'sanctum')
            ->getJson('/api/workspaces')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_vendor_mt7628_workspace_uses_vendor_revision(): void
    {
        $user = User::factory()->create();

        $create = $this->actingAs($user, 'sanctum')->postJson('/api/workspaces', [
            'name' => 'mtk-firmware',
            'type' => 'firmware',
            'openwrt_revision' => '24.10',
            'target' => 'ramips',
            'subtarget' => 'mt7628',
            'profile' => 'MT7628',
        ]);

        $create->assertCreated()
            ->assertJsonPath('data.openwrt_revision', 'mtk-mt7628')
            ->assertJsonPath('data.target', 'ramips')
            ->assertJsonPath('data.subtarget', 'mt7628')
            ->assertJsonPath('data.profile', 'MT7628');

        $config = (string) file_get_contents((new WorkspaceFilesystem)->pathFor($create->json('data.id')).'/repo/.config');
        $this->assertStringContainsString('CONFIG_TARGET_ramips_mt7628=y', $config);
        $this->assertStringContainsString('CONFIG_PACKAGE_kmod-mt7628=y', $config);
    }
}
