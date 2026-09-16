<?php

namespace Tests\Unit;

use App\Models\User;
use App\Models\Workspace;
use App\Services\PackageResolver;
use App\Services\WorkspaceFilesystem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use InvalidArgumentException;
use Tests\TestCase;

class PackageResolverTest extends TestCase
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

    public function test_resolves_package_under_package_directory(): void
    {
        $workspace = $this->makeWorkspace('luci-app-demo');
        $resolved = app(PackageResolver::class)->resolve($workspace);

        $this->assertSame('luci-app-demo', $resolved['name']);
        $this->assertDirectoryExists($resolved['path']);
    }

    public function test_resolves_makefile_at_repository_root(): void
    {
        $workspace = $this->makeWorkspace('imported-vps');
        $repo = (new WorkspaceFilesystem)->pathFor($workspace->id).'/repo';
        File::deleteDirectory($repo.'/package');
        File::put($repo.'/Makefile', "include \$(TOPDIR)/rules.mk\n\nPKG_NAME:=luci-app-vps000\nPKG_VERSION:=1.3.3\n\ninclude \$(INCLUDE_DIR)/package.mk\n\n\$(eval \$(call BuildPackage,\$(PKG_NAME)))\n");
        File::ensureDirectoryExists($repo.'/files/etc/config');
        File::put($repo.'/files/etc/config/vps000', "config vps000 'main'\n\toption enabled '1'\n");

        $resolved = app(PackageResolver::class)->resolve($workspace);

        $this->assertSame('luci-app-vps000', $resolved['name']);
        $this->assertSame('1.3.3', $resolved['version']);
        $this->assertSame(realpath($repo), realpath($resolved['path']));
    }

    public function test_rejects_workspace_without_any_package_makefile(): void
    {
        $workspace = $this->makeWorkspace('empty-app');
        $repo = (new WorkspaceFilesystem)->pathFor($workspace->id).'/repo';
        File::deleteDirectory($repo.'/package');
        File::put($repo.'/Makefile', "# not an OpenWrt package\nall:\n\techo hi\n");

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('No OpenWrt package Makefile with PKG_NAME was found');
        app(PackageResolver::class)->resolve($workspace);
    }

    private function makeWorkspace(string $name): Workspace
    {
        $user = User::factory()->create();
        $id = $this->actingAs($user, 'sanctum')->postJson('/api/workspaces', [
            'name' => $name,
            'type' => 'app',
        ])->json('data.id');

        return Workspace::query()->findOrFail($id);
    }
}
