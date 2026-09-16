<?php

namespace Tests\Unit;

use App\Services\GitHubReleaseClient;
use App\Services\GitService;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class GitHubReleaseClientTest extends TestCase
{
    public function test_parses_github_owner_and_repo_from_https_and_ssh_urls(): void
    {
        $git = app(GitService::class);
        $this->assertSame(
            ['owner' => 'VPS668', 'name' => 'luci-app-vps000'],
            $git->githubRepository('https://github.com/VPS668/luci-app-vps000')
        );
        $this->assertSame(
            ['owner' => 'VPS668', 'name' => 'luci-app-vps000'],
            $git->githubRepository('https://github.com/VPS668/luci-app-vps000.git')
        );
        $this->assertSame(
            ['owner' => 'VPS668', 'name' => 'luci-app-vps000'],
            $git->githubRepository('git@github.com:VPS668/luci-app-vps000.git')
        );
        $this->assertNull($git->githubRepository('/tmp/bare.git'));
    }

    public function test_creates_github_release_when_tag_exists_without_a_release(): void
    {
        Http::fake([
            'https://api.github.com/repos/VPS668/luci-app-vps000/releases/tags/v1.3.4' => Http::response(['message' => 'Not Found'], 404),
            'https://api.github.com/repos/VPS668/luci-app-vps000/releases' => Http::response([
                'id' => 42,
                'html_url' => 'https://github.com/VPS668/luci-app-vps000/releases/tag/v1.3.4',
                'upload_url' => 'https://uploads.github.com/repos/VPS668/luci-app-vps000/releases/42/assets{?name,label}',
                'assets' => [],
            ], 201),
        ]);

        $published = app(GitHubReleaseClient::class)->publish(
            'VPS668',
            'luci-app-vps000',
            'test-token',
            'v1.3.4',
            'notes',
            'main',
        );

        $this->assertTrue($published['created']);
        $this->assertSame([], $published['assets']);
        $this->assertSame('https://github.com/VPS668/luci-app-vps000/releases/tag/v1.3.4', $published['html_url']);
        Http::assertSent(fn ($request) => $request->method() === 'POST'
            && $request->url() === 'https://api.github.com/repos/VPS668/luci-app-vps000/releases'
            && $request['tag_name'] === 'v1.3.4');
    }

    public function test_reuses_existing_github_release(): void
    {
        Http::fake([
            'https://api.github.com/repos/VPS668/luci-app-vps000/releases/tags/v1.3.4' => Http::response([
                'id' => 7,
                'html_url' => 'https://github.com/VPS668/luci-app-vps000/releases/tag/v1.3.4',
                'upload_url' => 'https://uploads.github.com/repos/VPS668/luci-app-vps000/releases/7/assets{?name,label}',
                'assets' => [],
            ], 200),
        ]);

        $published = app(GitHubReleaseClient::class)->publish(
            'VPS668',
            'luci-app-vps000',
            'test-token',
            'v1.3.4',
            'notes',
            'main',
        );

        $this->assertFalse($published['created']);
        $this->assertSame(7, $published['id']);
        $this->assertSame([], $published['assets']);
    }

    public function test_uploads_ipk_and_sysupgrade_and_replaces_existing_asset(): void
    {
        $dir = storage_path('framework/testing/release-assets');
        File::ensureDirectoryExists($dir);
        $ipk = $dir.'/luci-app-vps000_1.3.4_all.ipk';
        $fw = $dir.'/openwrt-ramips-mt7628-mt7628-squashfs-sysupgrade.bin';
        file_put_contents($ipk, 'ipk');
        file_put_contents($fw, 'firmware');

        Http::fake([
            'https://api.github.com/repos/VPS668/luci-app-vps000/releases/tags/v1.3.4' => Http::response([
                'id' => 7,
                'html_url' => 'https://github.com/VPS668/luci-app-vps000/releases/tag/v1.3.4',
                'upload_url' => 'https://uploads.github.com/repos/VPS668/luci-app-vps000/releases/7/assets{?name,label}',
                'assets' => [[
                    'name' => 'luci-app-vps000_1.3.4_all.ipk',
                    'url' => 'https://api.github.com/repos/VPS668/luci-app-vps000/releases/assets/99',
                ]],
            ], 200),
            'https://api.github.com/repos/VPS668/luci-app-vps000/releases/assets/99' => Http::response([], 204),
            'https://uploads.github.com/repos/VPS668/luci-app-vps000/releases/7/assets*' => Http::response(['id' => 1], 201),
        ]);

        $published = app(GitHubReleaseClient::class)->publish(
            'VPS668',
            'luci-app-vps000',
            'test-token',
            'v1.3.4',
            'notes',
            'main',
            [$ipk, $fw],
        );

        $this->assertSame(
            ['luci-app-vps000_1.3.4_all.ipk', 'openwrt-ramips-mt7628-mt7628-squashfs-sysupgrade.bin'],
            $published['assets']
        );
        Http::assertSent(fn ($request) => $request->method() === 'DELETE'
            && $request->url() === 'https://api.github.com/repos/VPS668/luci-app-vps000/releases/assets/99');
    }
}
