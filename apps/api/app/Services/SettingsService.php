<?php

namespace App\Services;

use App\Models\Setting;
use Illuminate\Support\Facades\Crypt;

class SettingsService
{
    /**
     * @var list<string>
     */
    public const KEYS = [
        'ai_gateway_url',
        'ai_gateway_api_key',
        'ai_gateway_model',
        'ai_gateway_timeout',
        'ai_gateway_retries',
        'ai_tool_mode',
        'quota_workspaces',
        'quota_builds_per_hour',
    ];

    /**
     * @var list<string>
     */
    private const SECRETS = [
        'ai_gateway_api_key',
    ];

    public function get(string $key, mixed $default = null): mixed
    {
        $row = Setting::query()->where('key', $key)->first();
        if ($row && $row->value !== null && $row->value !== '') {
            $value = $row->is_secret ? Crypt::decryptString($row->value) : $row->value;
            if ($value !== '') {
                return $this->cast($key, $value);
            }
        }

        $fromConfig = config('studio.'.$key, $default);

        return $fromConfig === null || $fromConfig === '' ? $default : $this->cast($key, $fromConfig);
    }

    public function set(string $key, mixed $value): void
    {
        if (! in_array($key, self::KEYS, true)) {
            return;
        }

        $string = is_scalar($value) ? trim((string) $value) : '';
        $secret = in_array($key, self::SECRETS, true);

        if ($secret && $string === '') {
            return;
        }

        Setting::query()->updateOrCreate(
            ['key' => $key],
            [
                'value' => $secret ? Crypt::encryptString($string) : $string,
                'is_secret' => $secret,
            ],
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function publicPayload(): array
    {
        $key = (string) $this->get('ai_gateway_api_key', '');

        return [
            'ai_gateway_url' => rtrim((string) $this->get('ai_gateway_url', ''), '/'),
            'ai_gateway_api_key_set' => $key !== '',
            'ai_gateway_model' => (string) $this->get('ai_gateway_model', ''),
            'ai_gateway_timeout' => (int) $this->get('ai_gateway_timeout', 300),
            'ai_gateway_retries' => (int) $this->get('ai_gateway_retries', 3),
            'ai_tool_mode' => (string) $this->get('ai_tool_mode', 'auto'),
            'quota_workspaces' => (int) $this->get('quota_workspaces', 20),
            'quota_builds_per_hour' => (int) $this->get('quota_builds_per_hour', 20),
        ];
    }

    private function cast(string $key, mixed $value): mixed
    {
        if (in_array($key, ['ai_gateway_timeout', 'ai_gateway_retries', 'quota_workspaces', 'quota_builds_per_hour'], true)) {
            return (int) $value;
        }

        if ($key === 'ai_gateway_url') {
            return rtrim((string) $value, '/');
        }

        return $value;
    }
}
