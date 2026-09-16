<?php

namespace App\Services;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class GitHubReleaseClient
{
    /**
     * @param  list<string>  $files
     * @return array{html_url: string, created: bool, id: int, assets: list<string>}
     */
    public function publish(string $owner, string $repo, string $token, string $tag, string $notes, string $target, array $files = []): array
    {
        $api = 'https://api.github.com/repos/'.$owner.'/'.$repo;
        $headers = [
            'Accept' => 'application/vnd.github+json',
            'X-GitHub-Api-Version' => '2022-11-28',
            'User-Agent' => 'OpenWrt-AI-Studio',
        ];
        $client = Http::timeout(45)->withToken($token)->withHeaders($headers);

        $existing = $client->get($api.'/releases/tags/'.$tag);
        if ($existing->successful()) {
            $assets = $this->uploadAssets($token, $headers, (string) $existing->json('upload_url'), $existing->json('assets') ?? [], $files);

            return [
                'html_url' => (string) $existing->json('html_url'),
                'created' => false,
                'id' => (int) $existing->json('id'),
                'assets' => $assets,
            ];
        }
        if ($existing->status() !== 404) {
            throw new RuntimeException($this->errorMessage($existing, 'lookup'));
        }

        $created = $client->post($api.'/releases', [
            'tag_name' => $tag,
            'name' => $tag,
            'body' => $notes,
            'target_commitish' => $target !== '' ? $target : 'main',
            'draft' => false,
            'prerelease' => false,
        ]);

        if ($created->status() === 422 && str_contains((string) $created->body(), 'already_exists')) {
            $again = $client->get($api.'/releases/tags/'.$tag);
            if ($again->successful()) {
                $assets = $this->uploadAssets($token, $headers, (string) $again->json('upload_url'), $again->json('assets') ?? [], $files);

                return [
                    'html_url' => (string) $again->json('html_url'),
                    'created' => false,
                    'id' => (int) $again->json('id'),
                    'assets' => $assets,
                ];
            }
        }

        if (! $created->successful()) {
            throw new RuntimeException($this->errorMessage($created, 'create'));
        }

        $assets = $this->uploadAssets($token, $headers, (string) $created->json('upload_url'), $created->json('assets') ?? [], $files);

        return [
            'html_url' => (string) $created->json('html_url'),
            'created' => true,
            'id' => (int) $created->json('id'),
            'assets' => $assets,
        ];
    }

    /**
     * @param  array<string, string>  $headers
     * @param  list<array<string, mixed>>  $existingAssets
     * @param  list<string>  $files
     * @return list<string>
     */
    private function uploadAssets(string $token, array $headers, string $uploadUrl, array $existingAssets, array $files): array
    {
        $endpoint = preg_replace('/\{.*\}$/', '', $uploadUrl) ?: $uploadUrl;
        $have = [];
        foreach ($existingAssets as $asset) {
            if (! empty($asset['name']) && ! empty($asset['url'])) {
                $have[(string) $asset['name']] = (string) $asset['url'];
            }
        }

        $uploaded = [];
        foreach ($files as $path) {
            if (! is_file($path)) {
                continue;
            }
            $name = basename($path);
            if (isset($have[$name])) {
                Http::timeout(30)->withToken($token)->withHeaders($headers)->delete($have[$name]);
                unset($have[$name]);
            }
            $body = file_get_contents($path);
            if ($body === false) {
                continue;
            }
            $response = Http::timeout(180)
                ->withToken($token)
                ->withHeaders($headers)
                ->withBody($body, 'application/octet-stream')
                ->post($endpoint.'?name='.rawurlencode($name));
            if (! $response->successful()) {
                throw new RuntimeException($this->errorMessage($response, 'upload '.$name));
            }
            $uploaded[] = $name;
        }

        return $uploaded;
    }

    private function errorMessage(Response $response, string $action): string
    {
        $detail = $response->json('message') ?: trim($response->body());
        $detail = is_string($detail) ? $detail : (string) json_encode($detail);
        $status = $response->status();
        if ($status === 401 || $status === 403) {
            return 'GitHub Release '.$action.' failed ('.$status.'). The Access token can push tags but may lack permission to create Releases. Use a classic PAT with repo scope, or a fine-grained token with Contents: Read and write.';
        }

        return 'GitHub Release '.$action.' failed ('.$status.'): '.$detail;
    }
}
