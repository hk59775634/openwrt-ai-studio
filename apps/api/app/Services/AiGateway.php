<?php

namespace App\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

class AiGateway
{
    public function __construct(private readonly SettingsService $settings) {}

    public function url(): string
    {
        return rtrim((string) $this->settings->get('ai_gateway_url', config('studio.ai_gateway_url')), '/');
    }

    /**
     * @return list<string>
     */
    public function models(): array
    {
        $response = $this->client()->get($this->url().'/v1/models');
        if ($response->failed()) {
            throw new RuntimeException('AI gateway models failed (HTTP '.$response->status().').');
        }

        $rows = $response->json('data');
        if (! is_array($rows)) {
            return [];
        }

        $ids = [];
        foreach ($rows as $row) {
            if (is_array($row) && isset($row['id']) && is_string($row['id']) && $row['id'] !== '') {
                $ids[] = $row['id'];
            }
        }

        return array_values(array_unique($ids));
    }

    public function defaultModel(): string
    {
        $configured = trim((string) $this->settings->get('ai_gateway_model', config('studio.ai_gateway_model')));
        if ($configured !== '') {
            return $configured;
        }

        $models = $this->models();
        $preferred = [
            'auto',
            'fusion',
            'gemini-3.5-flash',
            'gemini-3.6-flash',
            'qwen3-coder-480b',
            'kimi-k2.7-code',
            'claude-haiku-4-5',
            'gpt-4o-mini',
        ];
        foreach ($preferred as $id) {
            if (in_array($id, $models, true)) {
                return $id;
            }
        }

        return $models[0] ?? 'gpt-4o-mini';
    }

    /**
     * @param  list<array<string, mixed>>  $messages
     * @param  list<array<string, mixed>>|null  $tools
     * @param  (callable(string, bool): void)|null  $onDelta
     * @return array<string, mixed>
     */
    public function chat(string $model, array $messages, ?array $tools = null, ?callable $onDelta = null): array
    {
        $retries = max(0, (int) $this->settings->get('ai_gateway_retries', config('studio.ai_gateway_retries', 3)));
        $attempts = $retries + 1;
        $last = null;

        for ($attempt = 1; $attempt <= $attempts; $attempt++) {
            try {
                return $this->chatOnce($model, $messages, $tools, $onDelta);
            } catch (Throwable $e) {
                $last = $e;
                if (! $this->isRetryable($e) || $attempt === $attempts) {
                    throw $e;
                }
                if ($onDelta) {
                    $onDelta('', true);
                }
                $this->backoff($attempt);
            }
        }

        throw $last ?? new RuntimeException('AI gateway chat failed.');
    }

    /**
     * @param  list<array<string, mixed>>  $messages
     * @param  list<array<string, mixed>>|null  $tools
     * @param  (callable(string, bool): void)|null  $onDelta
     * @return array<string, mixed>
     */
    private function chatOnce(string $model, array $messages, ?array $tools = null, ?callable $onDelta = null): array
    {
        $payload = $this->completionPayload($model, $messages, $tools, true);

        if (defined('STUDIO_RUNNING_TESTS')) {
            return $this->chatOnceViaHttp($payload, $model, $messages, $tools, $onDelta);
        }

        return $this->chatOnceViaCurl($payload, $model, $messages, $tools, $onDelta);
    }

    /**
     * @param  list<array<string, mixed>>  $messages
     * @param  list<array<string, mixed>>|null  $tools
     * @return array<string, mixed>
     */
    private function completionPayload(string $model, array $messages, ?array $tools, bool $stream): array
    {
        $payload = [
            'model' => $model,
            'messages' => $messages,
            'temperature' => 0.2,
        ];
        if ($stream) {
            $payload['stream'] = true;
        }
        if ($tools) {
            $payload['tools'] = $tools;
            $payload['tool_choice'] = 'auto';
        }

        return $payload;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  list<array<string, mixed>>  $messages
     * @param  list<array<string, mixed>>|null  $tools
     * @param  (callable(string, bool): void)|null  $onDelta
     * @return array<string, mixed>
     */
    private function chatOnceViaHttp(array $payload, string $model, array $messages, ?array $tools, ?callable $onDelta): array
    {
        try {
            $response = $this->client()
                ->withHeaders(['Accept' => 'text/event-stream, application/json'])
                ->post($this->url().'/v1/chat/completions', $payload);
        } catch (ConnectionException $e) {
            throw new RuntimeException('AI gateway chat failed: '.$e->getMessage(), 0, $e);
        }

        return $this->parseCompletionResponse(
            $response->status(),
            (string) $response->header('Content-Type'),
            (string) $response->body(),
            $response->json('choices.0.message'),
            $model,
            $messages,
            $tools,
            $onDelta,
        );
    }

    /**
     * Guzzle's stream=true option uses PHP fopen (StreamHandler), which fails on this
     * HTTPS POST. Curl still asks the gateway for SSE and parses tokens as they arrive.
     *
     * @param  array<string, mixed>  $payload
     * @param  list<array<string, mixed>>  $messages
     * @param  list<array<string, mixed>>|null  $tools
     * @param  (callable(string, bool): void)|null  $onDelta
     * @return array<string, mixed>
     */
    private function chatOnceViaCurl(array $payload, string $model, array $messages, ?array $tools, ?callable $onDelta): array
    {
        $seconds = max(10, (int) $this->settings->get('ai_gateway_timeout', config('studio.ai_gateway_timeout', 300)));
        $socketTimeout = (int) ini_get('default_socket_timeout');
        if ($socketTimeout > 0 && $socketTimeout < $seconds) {
            ini_set('default_socket_timeout', (string) $seconds);
        }

        $content = '';
        $toolCalls = [];
        $carry = '';
        $raw = '';
        $headers = [];

        $handle = curl_init($this->url().'/v1/chat/completions');
        if ($handle === false) {
            throw new RuntimeException('AI gateway chat failed: unable to start HTTP client.');
        }

        $options = [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Accept: text/event-stream, application/json',
                'Authorization: Bearer '.(string) $this->settings->get('ai_gateway_api_key', config('studio.ai_gateway_api_key')),
            ],
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => $seconds,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HEADERFUNCTION => function ($ch, string $header) use (&$headers): int {
                $line = trim($header);
                $split = strpos($line, ':');
                if ($split !== false) {
                    $name = strtolower(trim(substr($line, 0, $split)));
                    $headers[$name] = trim(substr($line, $split + 1));
                }

                return strlen($header);
            },
            CURLOPT_WRITEFUNCTION => function ($ch, string $data) use (&$raw, &$carry, &$content, &$toolCalls, $onDelta): int {
                $raw .= $data;
                $this->ingestSseBytes($data, $carry, $content, $toolCalls, $onDelta);

                return strlen($data);
            },
        ];
        if (defined('CURLOPT_TCP_KEEPALIVE')) {
            $options[CURLOPT_TCP_KEEPALIVE] = 1;
        }

        curl_setopt_array($handle, $options);
        $ok = curl_exec($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_HTTP_CODE);
        $errno = curl_errno($handle);
        $error = curl_error($handle);
        curl_close($handle);

        if ($ok === false) {
            throw new RuntimeException(
                'AI gateway chat failed: cURL error '.$errno.($error !== '' ? ': '.$error : '')
            );
        }

        $this->ingestSseBytes("\n", $carry, $content, $toolCalls, $onDelta);

        $jsonMessage = null;
        $decoded = json_decode($raw, true);
        if (is_array($decoded)) {
            $candidate = $decoded['choices'][0]['message'] ?? null;
            $jsonMessage = is_array($candidate) ? $candidate : null;
        }

        if ($status >= 400) {
            return $this->parseCompletionResponse(
                $status,
                (string) ($headers['content-type'] ?? ''),
                $raw,
                $jsonMessage,
                $model,
                $messages,
                $tools,
                $onDelta,
            );
        }

        if ($content !== '' || $toolCalls !== []) {
            return $this->sseMessage($content, $toolCalls);
        }

        return $this->parseCompletionResponse(
            $status,
            (string) ($headers['content-type'] ?? ''),
            $raw,
            $jsonMessage,
            $model,
            $messages,
            $tools,
            $onDelta,
        );
    }

    /**
     * @param  array<string, mixed>|null  $jsonMessage
     * @param  list<array<string, mixed>>  $messages
     * @param  list<array<string, mixed>>|null  $tools
     * @param  (callable(string, bool): void)|null  $onDelta
     * @return array<string, mixed>
     */
    private function parseCompletionResponse(
        int $status,
        string $contentType,
        string $body,
        mixed $jsonMessage,
        string $model,
        array $messages,
        ?array $tools,
        ?callable $onDelta,
    ): array {
        if ($status >= 400) {
            if (in_array($status, [400, 415, 422], true) && preg_match('/stream|event-stream/i', $body)) {
                return $this->chatOnceBuffered($model, $messages, $tools, $onDelta);
            }
            throw new RuntimeException(
                'AI gateway chat failed (HTTP '.$status.'): '.$this->safeBody($body)
            );
        }

        $contentType = strtolower($contentType);
        if (! str_contains($contentType, 'event-stream') && is_array($jsonMessage)) {
            $text = $jsonMessage['content'] ?? '';
            if (is_string($text) && $text !== '' && $onDelta) {
                $onDelta($text, false);
            }

            return $jsonMessage;
        }

        return $this->consumeSseString($body, $onDelta);
    }

    /**
     * @param  list<array<string, mixed>>  $messages
     * @param  list<array<string, mixed>>|null  $tools
     * @param  (callable(string, bool): void)|null  $onDelta
     * @return array<string, mixed>
     */
    private function chatOnceBuffered(string $model, array $messages, ?array $tools = null, ?callable $onDelta = null): array
    {
        $payload = [
            'model' => $model,
            'messages' => $messages,
            'temperature' => 0.2,
        ];
        if ($tools) {
            $payload['tools'] = $tools;
            $payload['tool_choice'] = 'auto';
        }

        try {
            $response = $this->client()->post($this->url().'/v1/chat/completions', $payload);
        } catch (ConnectionException $e) {
            throw new RuntimeException('AI gateway chat failed: '.$e->getMessage(), 0, $e);
        }

        if ($response->failed()) {
            throw new RuntimeException(
                'AI gateway chat failed (HTTP '.$response->status().'): '.$this->safeBody($response->body())
            );
        }

        $message = $response->json('choices.0.message');
        if (! is_array($message)) {
            throw new RuntimeException('AI gateway returned an empty completion.');
        }
        $text = $message['content'] ?? '';
        if (is_string($text) && $text !== '' && $onDelta) {
            $onDelta($text, false);
        }

        return $message;
    }

    /**
     * @param  (callable(string, bool): void)|null  $onDelta
     * @return array<string, mixed>
     */
    private function consumeSseString(string $body, ?callable $onDelta): array
    {
        $content = '';
        $toolCalls = [];
        $carry = '';
        $this->ingestSseBytes($body."\n", $carry, $content, $toolCalls, $onDelta);

        return $this->sseMessage($content, $toolCalls);
    }

    /**
     * @param  array<int, array<string, mixed>>  $toolCalls
     * @param  (callable(string, bool): void)|null  $onDelta
     */
    private function ingestSseBytes(string $chunk, string &$carry, string &$content, array &$toolCalls, ?callable $onDelta): void
    {
        $carry .= $chunk;
        while (($offset = strpos($carry, "\n")) !== false) {
            $line = trim(substr($carry, 0, $offset), "\r");
            $carry = substr($carry, $offset + 1);
            if ($this->applySseLine($line, $content, $toolCalls, $onDelta)) {
                $carry = '';

                return;
            }
        }
    }

    /**
     * @param  array<int, array<string, mixed>>  $toolCalls
     * @return array<string, mixed>
     */
    private function sseMessage(string $content, array $toolCalls): array
    {
        if ($content === '' && $toolCalls === []) {
            throw new RuntimeException('AI gateway returned an empty completion.');
        }

        $message = [
            'role' => 'assistant',
            'content' => $content,
            'tool_calls' => null,
        ];
        if ($toolCalls !== []) {
            ksort($toolCalls);
            $message['tool_calls'] = array_values($toolCalls);
        }

        return $message;
    }

    /**
     * @param  array<int, array<string, mixed>>  $toolCalls
     * @param  (callable(string, bool): void)|null  $onDelta
     */
    private function applySseLine(string $line, string &$content, array &$toolCalls, ?callable $onDelta): bool
    {
        if ($line === '' || str_starts_with($line, ':') || str_starts_with($line, 'event:')) {
            return false;
        }
        if (! str_starts_with($line, 'data:')) {
            return false;
        }
        $data = trim(substr($line, 5));
        if ($data === '[DONE]') {
            return true;
        }
        $decoded = json_decode($data, true);
        if (! is_array($decoded)) {
            return false;
        }
        $delta = $decoded['choices'][0]['delta'] ?? $decoded['choices'][0]['message'] ?? [];
        if (! is_array($delta)) {
            return false;
        }
        $piece = $delta['content'] ?? null;
        if (is_string($piece) && $piece !== '') {
            $content .= $piece;
            if ($onDelta) {
                $onDelta($piece, false);
            }
        }
        $calls = $delta['tool_calls'] ?? null;
        if (is_array($calls)) {
            $this->mergeToolCallDeltas($toolCalls, $calls);
        }

        return false;
    }

    /**
     * @param  array<int, array<string, mixed>>  $toolCalls
     * @param  list<array<string, mixed>>  $deltas
     */
    private function mergeToolCallDeltas(array &$toolCalls, array $deltas): void
    {
        foreach ($deltas as $delta) {
            if (! is_array($delta)) {
                continue;
            }
            $index = (int) ($delta['index'] ?? count($toolCalls));
            if (! isset($toolCalls[$index])) {
                $toolCalls[$index] = [
                    'id' => '',
                    'type' => 'function',
                    'function' => ['name' => '', 'arguments' => ''],
                ];
            }
            if (isset($delta['id']) && is_string($delta['id']) && $delta['id'] !== '') {
                $toolCalls[$index]['id'] = $delta['id'];
            }
            if (isset($delta['type']) && is_string($delta['type']) && $delta['type'] !== '') {
                $toolCalls[$index]['type'] = $delta['type'];
            }
            $function = $delta['function'] ?? [];
            if (is_array($function)) {
                if (isset($function['name']) && is_string($function['name'])) {
                    $toolCalls[$index]['function']['name'] .= $function['name'];
                }
                if (isset($function['arguments']) && is_string($function['arguments'])) {
                    $toolCalls[$index]['function']['arguments'] .= $function['arguments'];
                }
            }
        }
    }

    private function isRetryable(Throwable $e): bool
    {
        if ($e instanceof ConnectionException) {
            return true;
        }

        $message = $e->getMessage();
        if (preg_match('/HTTP (408|425|429|500|502|503|504)\b/', $message)) {
            return true;
        }

        return (bool) preg_match('/timed? ?out|cURL error (28|52|56)|Connection reset|Empty reply|disconnected/i', $message);
    }

    private function backoff(int $attempt): void
    {
        if (defined('STUDIO_RUNNING_TESTS')) {
            return;
        }

        sleep(min(8, 2 ** ($attempt - 1)));
    }

    public function supportsNativeTools(): bool
    {
        return in_array($this->settings->get('ai_tool_mode', config('studio.ai_tool_mode')), ['openai', 'auto'], true);
    }

    private function client(): PendingRequest
    {
        $seconds = max(10, (int) $this->settings->get('ai_gateway_timeout', config('studio.ai_gateway_timeout', 300)));
        $socketTimeout = (int) ini_get('default_socket_timeout');
        if ($socketTimeout > 0 && $socketTimeout < $seconds) {
            ini_set('default_socket_timeout', (string) $seconds);
        }

        return Http::timeout($seconds)
            ->connectTimeout(10)
            ->withToken((string) $this->settings->get('ai_gateway_api_key', config('studio.ai_gateway_api_key')))
            ->acceptJson()
            ->asJson();
    }

    private function safeBody(string $body): string
    {
        $body = preg_replace('/(sk-|freellmapi-)[A-Za-z0-9_-]+/', '[redacted]', $body) ?? $body;

        return substr(trim($body), 0, 280);
    }
}
