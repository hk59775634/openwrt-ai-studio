<?php

namespace App\Services;

use App\Models\Workspace;
use Illuminate\Support\Facades\File;
use RuntimeException;

class WorkspaceFilesystem
{
    public function root(): string
    {
        return rtrim((string) config('studio.workspace_root'), '/');
    }

    public function pathFor(string $workspaceId): string
    {
        return $this->root().'/'.$workspaceId;
    }

    public function initialize(Workspace $workspace): void
    {
        $path = $this->pathFor($workspace->id);
        $this->assertInsideRoot($path);

        foreach ([
            $path.'/repo',
            $path.'/.workspace/config',
            $path.'/.workspace/state',
            $path.'/build',
            $path.'/artifacts',
            $path.'/logs',
        ] as $directory) {
            File::ensureDirectoryExists($directory, 0750);
        }

        File::put($path.'/.workspace/workspace.json', json_encode([
            'id' => $workspace->id,
            'name' => $workspace->name,
            'type' => $workspace->type->value,
            'openwrt_revision' => $workspace->openwrt_revision,
            'status' => $workspace->status->value,
            'created_at' => $workspace->created_at?->toIso8601String(),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");

        File::put($path.'/.workspace/target.json', json_encode([
            'target' => $workspace->target,
            'subtarget' => $workspace->subtarget,
            'profile' => $workspace->profile,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");

        $this->seedSkeleton($workspace);
        app(GitService::class)->init($workspace);
    }

    public function initializeFromGit(Workspace $workspace, string $url, ?string $branch = null, ?string $token = null): void
    {
        $path = $this->pathFor($workspace->id);
        $this->assertInsideRoot($path);

        foreach ([
            $path.'/.workspace/config',
            $path.'/.workspace/state',
            $path.'/build',
            $path.'/artifacts',
            $path.'/logs',
        ] as $directory) {
            File::ensureDirectoryExists($directory, 0750);
        }

        File::put($path.'/.workspace/workspace.json', json_encode([
            'id' => $workspace->id,
            'name' => $workspace->name,
            'type' => $workspace->type->value,
            'openwrt_revision' => $workspace->openwrt_revision,
            'status' => $workspace->status->value,
            'git_remote_url' => $url,
            'created_at' => $workspace->created_at?->toIso8601String(),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");

        File::put($path.'/.workspace/target.json', json_encode([
            'target' => $workspace->target,
            'subtarget' => $workspace->subtarget,
            'profile' => $workspace->profile,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");

        app(GitService::class)->cloneRemote($workspace, $url, $branch, $token);
    }

    public function seedSkeleton(Workspace $workspace): void
    {
        $repo = $this->pathFor($workspace->id).'/repo';
        File::ensureDirectoryExists($repo, 0750);

        $slug = preg_replace('/[^a-z0-9-]+/', '-', strtolower($workspace->name)) ?: 'project';
        $slug = trim((string) $slug, '-');
        $slug = (string) preg_replace('/^luci-(app|theme)-/', '', $slug);
        $readme = $repo.'/README.md';

        if (! File::exists($readme)) {
            File::put($readme, "# {$workspace->name}\n\nOpenWrt {$workspace->type->value} workspace on {$workspace->openwrt_revision}.\n");
        }

        if ($workspace->type->value === 'app') {
            $dir = $repo.'/package/luci-app-'.$slug;
            File::ensureDirectoryExists($dir.'/luasrc/controller', 0750);
            if (! File::exists($dir.'/Makefile')) {
                File::put($dir.'/Makefile', "include \$(TOPDIR)/rules.mk\n\nPKG_NAME:=luci-app-{$slug}\nPKG_VERSION:=0.1.0\nPKG_RELEASE:=1\n\ninclude \$(INCLUDE_DIR)/package.mk\n\ndefine Package/luci-app-{$slug}\n  SECTION:=luci\n  CATEGORY:=LuCI\n  TITLE:=LuCI app {$slug}\nendef\n\ndefine Build/Compile\nendef\n\ndefine Package/luci-app-{$slug}/install\n  \$(INSTALL_DIR) \$(1)/usr/lib/lua/luci\n  \$(CP) ./luasrc/. \$(1)/usr/lib/lua/luci/\nendef\n\n\$(eval \$(call BuildPackage,luci-app-{$slug}))\n");
            }
            if (! File::exists($dir.'/luasrc/controller/app.lua')) {
                File::put($dir.'/luasrc/controller/app.lua', "module(\"luci.controller.{$slug}\", package.seeall)\n\nfunction index()\n    entry({\"admin\", \"services\", \"{$slug}\"}, template(\"{$slug}/index\"), _(\"{$workspace->name}\"), 60)\nend\n");
            }
        } elseif ($workspace->type->value === 'theme') {
            $dir = $repo.'/package/luci-theme-'.$slug;
            File::ensureDirectoryExists($dir, 0750);
            if (! File::exists($dir.'/Makefile')) {
                File::put($dir.'/Makefile', "include \$(TOPDIR)/rules.mk\n\nPKG_NAME:=luci-theme-{$slug}\nPKG_VERSION:=0.1.0\n\ninclude \$(INCLUDE_DIR)/package.mk\n\ndefine Package/luci-theme-{$slug}\n  SECTION:=luci\n  CATEGORY:=LuCI\n  TITLE:=LuCI theme {$slug}\nendef\n\n\$(eval \$(call BuildPackage,luci-theme-{$slug}))\n");
            }
        } else {
            if (! File::exists($repo.'/.config.example')) {
                File::put($repo.'/.config.example', "# OpenWrt .config placeholder for {$workspace->type->value}\nCONFIG_TARGET_x86=y\n");
            }
        }

        if (! File::exists($repo.'/.config')) {
            $preset = app(DeviceCatalog::class)->resolve($workspace->target, $workspace->subtarget, $workspace->profile);
            File::put($repo.'/.config', app(DeviceCatalog::class)->configFor($preset, $workspace->openwrt_revision));
        }
    }

    public function cloneFrom(Workspace $source, Workspace $destination): void
    {
        $from = $this->pathFor($source->id);
        $to = $this->pathFor($destination->id);
        $this->assertInsideRoot($from);
        $this->assertInsideRoot($to);

        if (! File::isDirectory($from)) {
            throw new RuntimeException('Source workspace files are missing.');
        }

        File::copyDirectory($from, $to);
        $this->initialize($destination);
    }

    public function delete(Workspace $workspace): void
    {
        $path = $this->pathFor($workspace->id);
        $this->assertInsideRoot($path);

        if (File::isDirectory($path)) {
            File::deleteDirectory($path);
        }
    }

    public function exists(Workspace $workspace): bool
    {
        return File::isDirectory($this->pathFor($workspace->id));
    }

    private function assertInsideRoot(string $path): void
    {
        $root = $this->root();
        File::ensureDirectoryExists($root, 0750);

        $resolvedRoot = realpath($root) ?: $root;
        $candidate = $path;

        if (! str_starts_with($candidate, $resolvedRoot) && ! str_starts_with($candidate, $root)) {
            throw new RuntimeException('Workspace path escapes the configured root.');
        }

        if (str_contains($path, '..')) {
            throw new RuntimeException('Workspace path is invalid.');
        }
    }
}
