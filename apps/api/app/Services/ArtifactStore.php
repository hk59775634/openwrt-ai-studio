<?php

namespace App\Services;

use App\Models\Artifact;
use App\Models\Build;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

class ArtifactStore
{
    public function put(Build $build, string $absolutePath): Artifact
    {
        $name = basename($absolutePath);
        $sha = hash_file('sha256', $absolutePath) ?: throw new RuntimeException('Unable to hash artifact.');
        $size = filesize($absolutePath) ?: 0;
        $relative = 'artifacts/'.$build->id.'/'.$name;
        $key = $build->workspace_id.'/'.$relative;

        $objectKey = $relative;
        if ($this->driver() === 'minio') {
            try {
                $this->minioPut($key, (string) file_get_contents($absolutePath), $this->mime($name));
                $objectKey = $key;
            } catch (Throwable) {
                $objectKey = $relative;
            }
        }

        return Artifact::query()->create([
            'build_id' => $build->id,
            'name' => $name,
            'object_key' => $objectKey,
            'sha256' => $sha,
            'size' => $size,
        ]);
    }

    public function localPath(Build $build, Artifact $artifact): ?string
    {
        $path = app(WorkspaceFilesystem::class)->pathFor($build->workspace_id).'/artifacts/'.$build->id.'/'.$artifact->name;

        return is_file($path) ? $path : null;
    }

    public function forget(Build $build): void
    {
        $dir = app(WorkspaceFilesystem::class)->pathFor($build->workspace_id).'/artifacts/'.$build->id;
        if (is_dir($dir)) {
            File::deleteDirectory($dir);
        }

        if ($this->driver() !== 'minio') {
            return;
        }

        $build->loadMissing('artifacts');
        foreach ($build->artifacts as $artifact) {
            try {
                $this->signed('DELETE', $artifact->object_key, '', 'application/octet-stream');
            } catch (Throwable) {
            }
        }
    }

    public function stream(Build $build, Artifact $artifact): string
    {
        $local = $this->localPath($build, $artifact);
        if ($local) {
            return (string) file_get_contents($local);
        }
        if ($this->driver() === 'minio') {
            return $this->minioGet($artifact->object_key);
        }

        throw new RuntimeException('Artifact is missing.');
    }

    public function driver(): string
    {
        return (string) config('studio.artifact_store', 'local');
    }

    /**
     * @return array{ok: bool, driver: string, detail?: string}
     */
    public function health(): array
    {
        if ($this->driver() !== 'minio') {
            return ['ok' => true, 'driver' => 'local'];
        }

        try {
            $response = Http::timeout(5)->get(rtrim((string) config('studio.minio_endpoint'), '/').'/minio/health/live');

            return [
                'ok' => $response->successful(),
                'driver' => 'minio',
                'detail' => $response->successful() ? null : 'HTTP '.$response->status(),
            ];
        } catch (\Throwable $e) {
            return ['ok' => false, 'driver' => 'minio', 'detail' => $e->getMessage()];
        }
    }

    private function mime(string $name): string
    {
        return str_ends_with($name, '.ipk') ? 'application/vnd.debian.binary-package' : 'application/octet-stream';
    }

    private function minioPut(string $key, string $body, string $type): void
    {
        $this->signed('PUT', $key, $body, $type);
    }

    private function minioGet(string $key): string
    {
        $response = $this->signed('GET', $key, '', 'application/octet-stream');

        return (string) $response;
    }

    private function signed(string $method, string $key, string $body, string $type): string
    {
        $endpoint = rtrim((string) config('studio.minio_endpoint'), '/');
        $bucket = (string) config('studio.minio_bucket', 'artifacts');
        $access = (string) config('studio.minio_access_key');
        $secret = (string) config('studio.minio_secret_key');
        $region = 'us-east-1';
        $now = gmdate('Ymd\THis\Z');
        $date = substr($now, 0, 8);
        $host = parse_url($endpoint, PHP_URL_HOST);
        $port = parse_url($endpoint, PHP_URL_PORT);
        if ($port) {
            $host .= ':'.$port;
        }
        $uri = '/'.$bucket.'/'.str_replace('%2F', '/', rawurlencode($key));
        $payloadHash = hash('sha256', $body);
        $canonicalHeaders = 'host:'.$host."\n".'x-amz-content-sha256:'.$payloadHash."\n".'x-amz-date:'.$now."\n";
        $signedHeaders = 'host;x-amz-content-sha256;x-amz-date';
        $canonical = $method."\n".$uri."\n\n".$canonicalHeaders."\n".$signedHeaders."\n".$payloadHash;
        $scope = $date.'/'.$region.'/s3/aws4_request';
        $stringToSign = 'AWS4-HMAC-SHA256'."\n".$now."\n".$scope."\n".hash('sha256', $canonical);
        $signingKey = hash_hmac('sha256', 'aws4_request', hash_hmac('sha256', 's3', hash_hmac('sha256', $region, hash_hmac('sha256', $date, 'AWS4'.$secret, true), true), true), true);
        $signature = hash_hmac('sha256', $stringToSign, $signingKey);
        $authorization = 'AWS4-HMAC-SHA256 Credential='.$access.'/'.$scope.', SignedHeaders='.$signedHeaders.', Signature='.$signature;

        $request = Http::timeout(20)
            ->withHeaders([
                'Authorization' => $authorization,
                'x-amz-content-sha256' => $payloadHash,
                'x-amz-date' => $now,
                'Content-Type' => $type,
            ]);

        $url = $endpoint.$uri;
        $response = match ($method) {
            'PUT' => $request->withBody($body, $type)->put($url),
            'DELETE' => $request->delete($url),
            default => $request->get($url),
        };
        if ($response->failed()) {
            throw new RuntimeException('MinIO '.$method.' failed (HTTP '.$response->status().').');
        }

        return (string) $response->body();
    }
}
