<?php

namespace Tests\Unit;

use App\Enums\BuildStatus;
use App\Enums\BuildType;
use App\Models\Artifact;
use App\Models\Build;
use App\Models\User;
use App\Models\Workspace;
use App\Services\ReleaseAssetCollector;
use App\Services\WorkspaceFilesystem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use InvalidArgumentException;
use Tests\TestCase;

class ReleaseAssetCollectorTest extends TestCase
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

    public function test_collects_latest_ipk_and_sysupgrade_from_build_files(): void
    {
        [$workspace, $user] = $this->workspace();
        $this->seedArtifact($workspace, $user, 'luci-app-vps000_1.3.3_all.ipk', 'old-ipk', now()->subMinutes(10));
        $this->seedArtifact($workspace, $user, 'luci-app-vps000_1.3.4_all.ipk', 'new-ipk', now()->subMinutes(2));
        $this->seedArtifact($workspace, $user, 'openwrt-ramips-mt7628-mt7628-squashfs-sysupgrade.bin', 'old-fw', now()->subMinutes(8));
        $this->seedArtifact($workspace, $user, 'openwrt-ramips-mt7628-mt7628-squashfs-sysupgrade.bin', 'new-fw', now()->subMinute());
        $this->seedArtifact($workspace, $user, 'luci-app-vps000_9.9.9_all.ipk', 'failed-ipk', now(), BuildStatus::Failed);

        $files = app(ReleaseAssetCollector::class)->collect($workspace, null, 'v1.3.4', 'VPS668', 'luci-app-vps000', 'notes');
        $named = $this->named($files);

        $this->assertSame('new-ipk', $named['luci-app-vps000_1.3.4_all.ipk']);
        $this->assertArrayNotHasKey('luci-app-vps000_1.3.3_all.ipk', $named);
        $this->assertSame('new-fw', $named['openwrt-ramips-mt7628-mt7628-squashfs-sysupgrade.bin']);
        $this->assertArrayHasKey('manifest.json', $named);
        $manifest = json_decode($named['manifest.json'], true);
        $this->assertSame('v1.3.4', $manifest['tag']);
        $this->assertSame('luci-app-vps000_1.3.4_all.ipk', $manifest['vps000']['filename']);
        $this->assertSame('openwrt-ramips-mt7628-mt7628-squashfs-sysupgrade.bin', $manifest['firmware']['filename']);
        $this->assertStringContainsString('/releases/download/v1.3.4/', $manifest['vps000']['url']);
    }

    public function test_collects_checked_artifacts_instead_of_latest(): void
    {
        [$workspace, $user] = $this->workspace();
        $oldIpk = $this->seedArtifact($workspace, $user, 'luci-app-vps000_1.3.3_all.ipk', 'old-ipk', now()->subMinutes(10));
        $this->seedArtifact($workspace, $user, 'luci-app-vps000_1.3.4_all.ipk', 'new-ipk', now()->subMinutes(2));
        $oldFw = $this->seedArtifact($workspace, $user, 'openwrt-ramips-mt7628-mt7628-squashfs-sysupgrade.bin', 'old-fw', now()->subMinutes(8));
        $this->seedArtifact($workspace, $user, 'openwrt-ramips-mt7628-mt7628-squashfs-sysupgrade.bin', 'new-fw', now()->subMinute());

        $files = app(ReleaseAssetCollector::class)->collect(
            $workspace,
            [$oldIpk, $oldFw],
            'v1.3.3',
            'VPS668',
            'luci-app-vps000',
            'notes',
        );
        $named = $this->named($files);

        $this->assertSame('old-ipk', $named['luci-app-vps000_1.3.3_all.ipk']);
        $this->assertSame('old-fw', $named['openwrt-ramips-mt7628-mt7628-squashfs-sysupgrade.bin']);
        $this->assertArrayNotHasKey('luci-app-vps000_1.3.4_all.ipk', $named);
        $this->assertSame('1.3.3', json_decode($named['manifest.json'], true)['version']);
    }

    public function test_empty_selection_is_rejected(): void
    {
        [$workspace] = $this->workspace();
        $this->expectException(InvalidArgumentException::class);
        app(ReleaseAssetCollector::class)->collect($workspace, [], 'v1.0.0', 'VPS668', 'luci-app-vps000', 'notes');
    }

    /**
     * @return array{0: Workspace, 1: User}
     */
    private function workspace(): array
    {
        $user = User::factory()->create();
        $id = $this->actingAs($user, 'sanctum')->postJson('/api/workspaces', [
            'name' => 'luci-app-vps000',
            'type' => 'app',
        ])->json('data.id');

        return [Workspace::query()->findOrFail($id), $user];
    }

    /**
     * @param  list<string>  $files
     * @return array<string, string>
     */
    private function named(array $files): array
    {
        $named = [];
        foreach ($files as $path) {
            $named[basename($path)] = (string) file_get_contents($path);
        }

        return $named;
    }

    private function seedArtifact(
        Workspace $workspace,
        User $user,
        string $name,
        string $contents,
        mixed $createdAt,
        BuildStatus $status = BuildStatus::Success,
    ): int {
        $build = Build::query()->create([
            'workspace_id' => $workspace->id,
            'user_id' => $user->id,
            'type' => str_ends_with($name, '.ipk') ? BuildType::App : BuildType::Firmware,
            'status' => $status,
            'package_name' => 'luci-app-vps000',
            'git_commit' => 'deadbeef',
            'openwrt_revision' => 'mtk-mt7628',
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
            'finished_at' => $createdAt,
        ]);
        $dir = (new WorkspaceFilesystem)->pathFor($workspace->id).'/artifacts/'.$build->id;
        File::ensureDirectoryExists($dir, 0750);
        file_put_contents($dir.'/'.$name, $contents);
        $artifact = Artifact::query()->create([
            'build_id' => $build->id,
            'name' => $name,
            'object_key' => 'artifacts/'.$build->id.'/'.$name,
            'sha256' => hash('sha256', $contents),
            'size' => strlen($contents),
        ]);

        return (int) $artifact->id;
    }
}
