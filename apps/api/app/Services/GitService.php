<?php

namespace App\Services;

use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use InvalidArgumentException;
use RuntimeException;

class GitService
{
    public function __construct(
        private readonly RepoPath $paths,
        private readonly WorkspaceFilesystem $filesystem,
        private readonly GitHubReleaseClient $githubReleases,
        private readonly ReleaseAssetCollector $releaseAssets,
    ) {}

    public function ensureRepository(Workspace $workspace): void
    {
        $repo = $this->paths->repo($workspace);
        if (! is_dir($repo)) {
            $this->filesystem->initialize($workspace);
        }

        if (! is_dir($repo.'/.git')) {
            $this->filesystem->seedSkeleton($workspace);
            $this->init($workspace);
        }
    }

    public function init(Workspace $workspace): void
    {
        $repo = $this->paths->repo($workspace);
        $this->run($repo, ['git', 'init', '-b', 'main']);
        $this->run($repo, ['git', 'config', 'user.email', 'studio@openwrt-ai.local']);
        $this->run($repo, ['git', 'config', 'user.name', 'OpenWrt AI Studio']);
        $this->run($repo, ['git', 'add', '-A']);
        $status = $this->run($repo, ['git', 'status', '--porcelain']);
        if (trim($status) !== '') {
            $this->run($repo, ['git', 'commit', '-m', 'Initial workspace']);
        }
    }

    public function cloneRemote(Workspace $workspace, string $url, ?string $branch = null, ?string $token = null): void
    {
        $url = $this->assertAllowedUrl($url);
        $repo = $this->paths->repo($workspace);
        $parent = dirname($repo);
        File::ensureDirectoryExists($parent, 0750);
        if (is_dir($repo)) {
            File::deleteDirectory($repo);
        }

        $command = ['git', 'clone', '--origin', 'origin'];
        if ($branch) {
            $command[] = '--branch';
            $command[] = $branch;
            $command[] = '--single-branch';
        }
        $command[] = $this->authenticatedUrl($url, $token);
        $command[] = $repo;
        $this->run($parent, $command, 180);
        $this->run($repo, ['git', 'config', 'user.email', 'studio@openwrt-ai.local']);
        $this->run($repo, ['git', 'config', 'user.name', 'OpenWrt AI Studio']);
        $this->run($repo, ['git', 'remote', 'set-url', 'origin', $this->displayUrl($url)]);

        $resolvedBranch = $branch ?: trim($this->run($repo, ['git', 'rev-parse', '--abbrev-ref', 'HEAD']));
        $workspace->update([
            'git_remote_url' => $this->displayUrl($url),
            'git_remote_branch' => $resolvedBranch !== '' ? $resolvedBranch : 'main',
            'git_token' => $token ?: $workspace->git_token,
        ]);
    }

    public function bindRemote(Workspace $workspace, string $url, ?string $branch = null, ?string $token = null): void
    {
        $this->ensureRepository($workspace);
        $url = $this->assertAllowedUrl($url);
        $repo = $this->paths->repo($workspace);
        $display = $this->displayUrl($url);
        try {
            $this->run($repo, ['git', 'remote', 'remove', 'origin']);
        } catch (RuntimeException) {
        }
        $this->run($repo, ['git', 'remote', 'add', 'origin', $display]);

        $payload = [
            'git_remote_url' => $display,
            'git_remote_branch' => $branch ?: ($workspace->git_remote_branch ?: 'main'),
        ];
        if ($token !== null && $token !== '') {
            $payload['git_token'] = $token;
        }
        $workspace->update($payload);
    }

    /**
     * @return array{remote: string, branch: string, sha: string}
     */
    public function push(Workspace $workspace, ?string $branch = null): array
    {
        $this->ensureRepository($workspace);
        $repo = $this->paths->repo($workspace);
        $remote = $this->requireRemote($workspace);
        $branch ??= $workspace->git_remote_branch ?: 'main';
        $dest = $this->authenticatedUrl($remote, $workspace->git_token);
        $this->run($repo, ['git', 'push', '-u', $dest, 'HEAD:'.$branch], 90);

        return [
            'remote' => $this->displayUrl($remote),
            'branch' => $branch,
            'sha' => $this->head($workspace),
        ];
    }

    /**
     * @return array{remote: string, branch: string, sha: string, ahead: int, behind: int}
     */
    public function pull(Workspace $workspace, ?string $branch = null): array
    {
        $this->ensureRepository($workspace);
        $repo = $this->paths->repo($workspace);
        $remote = $this->requireRemote($workspace);
        $branch ??= $workspace->git_remote_branch ?: 'main';
        $dest = $this->authenticatedUrl($remote, $workspace->git_token);
        $this->run($repo, ['git', 'pull', '--ff-only', $dest, $branch], 90);
        $divergence = $this->divergenceFromFetchHead($repo);

        return [
            'remote' => $this->displayUrl($remote),
            'branch' => $branch,
            'sha' => $this->head($workspace),
            'ahead' => $divergence['ahead'],
            'behind' => $divergence['behind'],
        ];
    }

    /**
     * @return array{ahead: int, behind: int}
     */
    private function divergenceFromFetchHead(string $repo): array
    {
        $ahead = 0;
        $behind = 0;
        try {
            $counts = trim($this->run($repo, ['git', 'rev-list', '--left-right', '--count', 'FETCH_HEAD...HEAD']));
        } catch (RuntimeException) {
            return ['ahead' => 0, 'behind' => 0];
        }
        if (preg_match('/^(\d+)\s+(\d+)$/', $counts, $match)) {
            $behind = (int) $match[1];
            $ahead = (int) $match[2];
        }

        return ['ahead' => $ahead, 'behind' => $behind];
    }

    /**
     * @param  list<int>|null  $artifactIds
     * @return array{tag: string, sha: string, remote: string, html_url: ?string, created: bool, assets: list<string>}
     */
    public function release(Workspace $workspace, string $tag, ?string $message = null, ?array $artifactIds = null): array
    {
        $this->ensureRepository($workspace);
        if (! preg_match('/^v?[A-Za-z0-9._-]{1,40}$/', $tag)) {
            throw new InvalidArgumentException('Tag must look like v1.0.0.');
        }
        $repo = $this->paths->repo($workspace);
        $remote = $this->requireRemote($workspace);
        $dest = $this->authenticatedUrl($remote, $workspace->git_token);
        $this->ensureAnnotatedTag($repo, $tag, $message);
        $this->run($repo, ['git', 'push', $dest, 'refs/tags/'.$tag], 90);

        $result = [
            'tag' => $tag,
            'sha' => $this->head($workspace),
            'remote' => $this->displayUrl($remote),
            'html_url' => null,
            'created' => false,
            'assets' => [],
        ];

        $github = $this->githubRepository($remote);
        if ($github === null) {
            return $result;
        }
        if (! $workspace->git_token) {
            throw new RuntimeException(
                'Tag '.$tag.' was pushed, but creating a GitHub Release needs the Access token. Paste a PAT with repo write, Bind remote, then click Release again.'
            );
        }

        $files = $this->releaseAssets->collect(
            $workspace,
            $artifactIds,
            $tag,
            $github['owner'],
            $github['name'],
            $message ?: $this->releaseNotes($repo, $tag),
        );
        $published = $this->githubReleases->publish(
            $github['owner'],
            $github['name'],
            $workspace->git_token,
            $tag,
            $message ?: $this->releaseNotes($repo, $tag),
            $workspace->git_remote_branch ?: 'main',
            $files,
        );

        return array_merge($result, [
            'html_url' => $published['html_url'],
            'created' => $published['created'],
            'assets' => $published['assets'],
        ]);
    }

    /**
     * @return array{owner: string, name: string}|null
     */
    public function githubRepository(string $url): ?array
    {
        $url = $this->displayUrl(trim($url));
        if (! preg_match('#github\.com[:/]([^/\s]+)/([^/\s]+)#i', $url, $match)) {
            return null;
        }
        $name = preg_replace('/\.git$/', '', rtrim($match[2], '/')) ?: $match[2];

        return [
            'owner' => $match[1],
            'name' => $name,
        ];
    }

    private function ensureAnnotatedTag(string $repo, string $tag, ?string $message): void
    {
        $existing = trim($this->run($repo, ['git', 'tag', '-l', '--', $tag]));
        if ($existing === $tag) {
            return;
        }
        $this->run($repo, ['git', 'tag', '-a', $tag, '-m', $message ?: $tag]);
    }

    private function releaseNotes(string $repo, string $tag): string
    {
        $path = $repo.'/RELEASE_NOTES';
        if (is_file($path)) {
            $notes = trim((string) file_get_contents($path));
            if ($notes !== '') {
                return $notes;
            }
        }

        return $tag;
    }

    public function assertAllowedUrl(string $url): string
    {
        $url = trim($url);
        if ($url === '' || strlen($url) > 500) {
            throw new InvalidArgumentException('Git URL is invalid.');
        }
        if (preg_match('#(^|://)(file|javascript|data):#i', $url)) {
            throw new InvalidArgumentException('This Git URL scheme is not allowed.');
        }
        if (str_starts_with($url, '/') || str_starts_with($url, './')) {
            if (! defined('STUDIO_RUNNING_TESTS')) {
                throw new InvalidArgumentException('Local Git paths are not allowed.');
            }

            return $url;
        }
        if (! preg_match('#^(https://|git@|ssh://)#i', $url)) {
            throw new InvalidArgumentException('Use an https://, ssh://, or git@ URL.');
        }

        return $url;
    }

    public function displayUrl(string $url): string
    {
        return preg_replace('#://([^/@]+)@#', '://', $url) ?: $url;
    }

    private function authenticatedUrl(string $url, ?string $token): string
    {
        if (! $token || ! str_starts_with($url, 'https://')) {
            return $url;
        }

        $parts = parse_url($url);
        if (! is_array($parts) || empty($parts['host'])) {
            return $url;
        }

        $path = $parts['path'] ?? '';
        $port = isset($parts['port']) ? ':'.$parts['port'] : '';

        return 'https://x-access-token:'.rawurlencode($token).'@'.$parts['host'].$port.$path;
    }

    private function requireRemote(Workspace $workspace): string
    {
        $url = (string) $workspace->git_remote_url;
        if ($url === '') {
            throw new RuntimeException('This workspace is not bound to a Git remote.');
        }

        return $url;
    }

    /**
     * @return array{branch: string, clean: bool, staged: list<string>, unstaged: list<string>, untracked: list<string>}
     */
    public function status(Workspace $workspace): array
    {
        $this->ensureRepository($workspace);
        $repo = $this->paths->repo($workspace);
        $branch = trim($this->run($repo, ['git', 'rev-parse', '--abbrev-ref', 'HEAD']));
        $porcelain = $this->run($repo, ['git', 'status', '--porcelain=v1']);

        $staged = [];
        $unstaged = [];
        $untracked = [];
        foreach (preg_split("/\r\n|\n|\r/", $porcelain) as $line) {
            if ($line === '') {
                continue;
            }
            $code = substr($line, 0, 2);
            $file = ltrim(substr($line, 3));
            if (str_starts_with($code, '??')) {
                $untracked[] = $file;
            } else {
                if ($code[0] !== ' ' && $code[0] !== '?') {
                    $staged[] = $file;
                }
                if ($code[1] !== ' ' && $code[1] !== '?') {
                    $unstaged[] = $file;
                }
            }
        }

        return [
            'branch' => $branch !== '' ? $branch : 'main',
            'clean' => $porcelain === '',
            'staged' => array_values(array_unique($staged)),
            'unstaged' => array_values(array_unique($unstaged)),
            'untracked' => $untracked,
        ];
    }

    public function assertClean(Workspace $workspace, string $message = 'Commit your changes before building.'): void
    {
        $status = $this->status($workspace);
        if (! $status['clean']) {
            throw new RuntimeException($message);
        }
    }

    public function diff(Workspace $workspace, ?string $path = null): string
    {
        $this->ensureRepository($workspace);
        $repo = $this->paths->repo($workspace);
        $args = ['git', 'diff', '--', ];
        if ($path) {
            $this->paths->resolve($workspace, $path);
            $args[] = $path;
        }

        $unstaged = $this->run($repo, $args);
        $staged = $this->run($repo, array_merge(['git', 'diff', '--cached', '--'], $path ? [$path] : []));
        $untracked = $this->untrackedDiff($workspace, $path);

        return trim(implode("\n", array_filter([$staged, $unstaged, $untracked])));
    }

    /**
     * @return array{sha: string, message: string, branch: string}
     */
    public function commit(Workspace $workspace, User $user, string $message): array
    {
        $this->ensureRepository($workspace);
        $repo = $this->paths->repo($workspace);
        $this->run($repo, ['git', 'add', '-A']);
        $status = $this->run($repo, ['git', 'status', '--porcelain']);
        if (trim($status) === '') {
            throw new RuntimeException('Nothing to commit.');
        }

        $this->run($repo, [
            'git',
            '-c', 'user.email='.$user->email,
            '-c', 'user.name='.$user->name,
            'commit',
            '-m',
            $message,
        ]);

        return [
            'sha' => trim($this->run($repo, ['git', 'rev-parse', 'HEAD'])),
            'message' => $message,
            'branch' => trim($this->run($repo, ['git', 'rev-parse', '--abbrev-ref', 'HEAD'])),
        ];
    }

    /**
     * @return list<array{sha: string, message: string, author: string, date: string}>
     */
    public function log(Workspace $workspace, int $limit = 20): array
    {
        $this->ensureRepository($workspace);
        $repo = $this->paths->repo($workspace);
        $raw = $this->run($repo, [
            'git', 'log',
            '-n', (string) $limit,
            '--pretty=format:%H%x09%s%x09%an%x09%aI',
        ]);

        if ($raw === '') {
            return [];
        }

        $entries = [];
        foreach (preg_split("/\r\n|\n|\r/", $raw) as $line) {
            $parts = explode("\t", $line, 4);
            if (count($parts) < 4) {
                continue;
            }
            $entries[] = [
                'sha' => $parts[0],
                'message' => $parts[1],
                'author' => $parts[2],
                'date' => $parts[3],
            ];
        }

        return $entries;
    }

    public function head(Workspace $workspace): string
    {
        $this->ensureRepository($workspace);

        return trim($this->run($this->paths->repo($workspace), ['git', 'rev-parse', 'HEAD']));
    }

    public function exportTree(Workspace $workspace, string $destination, ?string $commit = null): void
    {
        $this->ensureRepository($workspace);
        $repo = $this->paths->repo($workspace);
        $commit ??= $this->head($workspace);
        File::ensureDirectoryExists($destination, 0750);
        $archive = Process::path($repo)->timeout(30)->run(['git', 'archive', '--format=tar', $commit]);
        if ($archive->failed()) {
            throw new RuntimeException(trim($archive->errorOutput() ?: $archive->output()) ?: 'git archive failed');
        }
        $tar = $destination.'/snapshot.tar';
        file_put_contents($tar, $archive->output());
        $extract = Process::path($destination)->timeout(30)->run(['tar', '-xf', $tar]);
        File::delete($tar);
        if ($extract->failed()) {
            throw new RuntimeException(trim($extract->errorOutput() ?: $extract->output()) ?: 'tar extract failed');
        }
    }

    public function restore(Workspace $workspace, string $path): void
    {
        $this->ensureRepository($workspace);
        $this->paths->resolve($workspace, $path);
        $this->run($this->paths->repo($workspace), ['git', 'checkout', '--', $path]);
    }

    private function untrackedDiff(Workspace $workspace, ?string $path): string
    {
        $repo = $this->paths->repo($workspace);
        $raw = $this->run($repo, ['git', 'ls-files', '--others', '--exclude-standard']);
        $chunks = [];
        foreach (preg_split("/\r\n|\n|\r/", $raw) as $file) {
            if ($file === '' || ($path && $file !== $path)) {
                continue;
            }
            $full = $this->paths->resolve($workspace, $file);
            if (! is_file($full) || filesize($full) > 256 * 1024) {
                continue;
            }
            $content = file_get_contents($full) ?: '';
            $chunks[] = "diff --git a/{$file} b/{$file}\nnew file mode 100644\n--- /dev/null\n+++ b/{$file}\n".$content;
        }

        return implode("\n", $chunks);
    }

    /**
     * @param  list<string>  $command
     */
    public function run(string $repo, array $command, int $timeout = 20): string
    {
        $result = Process::path($repo)
            ->timeout($timeout)
            ->run($command);

        if ($result->failed() && ! str_contains($result->errorOutput(), 'unknown revision')) {
            $error = trim($result->errorOutput() ?: $result->output());
            throw new RuntimeException($error !== '' ? $error : 'Git command failed.');
        }

        return $result->output();
    }
}
