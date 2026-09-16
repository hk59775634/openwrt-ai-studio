<?php

namespace App\Services;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use RuntimeException;

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
     * @return array<string, mixed>
     */
    public function chat(string $model, array $messages, ?array $tools = null): array
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

        $response = $this->client()->post($this->url().'/v1/chat/completions', $payload);
        if ($response->failed()) {
            throw new RuntimeException(
                'AI gateway chat failed (HTTP '.$response->status().'): '.$this->safeBody($response->body())
            );
        }

        $message = $response->json('choices.0.message');
        if (! is_array($message)) {
            throw new RuntimeException('AI gateway returned an empty completion.');
        }

        return $message;
    }

    public function supportsNativeTools(): bool
    {
        return in_array($this->settings->get('ai_tool_mode', config('studio.ai_tool_mode')), ['openai', 'auto'], true);
    }

    private function client(): PendingRequest
    {
        return Http::timeout((int) $this->settings->get('ai_gateway_timeout', config('studio.ai_gateway_timeout', 90)))
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
