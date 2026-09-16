<?php

namespace App\Http\Controllers;

use App\Http\Concerns\AuthorizesAdmin;
use App\Services\SettingsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SettingsController extends Controller
{
    use AuthorizesAdmin;

    public function __construct(private readonly SettingsService $settings) {}

    public function show(Request $request): JsonResponse
    {
        $this->authorizeAdmin($request);

        return response()->json(['data' => $this->settings->publicPayload()]);
    }

    public function update(Request $request): JsonResponse
    {
        $this->authorizeAdmin($request);
        $data = $request->validate([
            'ai_gateway_url' => ['nullable', 'string', 'max:255'],
            'ai_gateway_api_key' => ['nullable', 'string', 'max:512'],
            'ai_gateway_model' => ['nullable', 'string', 'max:120'],
            'ai_gateway_timeout' => ['nullable', 'integer', 'min:10', 'max:300'],
            'ai_tool_mode' => ['nullable', 'in:auto,openai,json'],
            'quota_workspaces' => ['nullable', 'integer', 'min:1', 'max:500'],
            'quota_builds_per_hour' => ['nullable', 'integer', 'min:1', 'max:500'],
        ]);

        if (isset($data['ai_gateway_url']) && $data['ai_gateway_url'] !== '') {
            $url = rtrim($data['ai_gateway_url'], '/');
            if (! preg_match('#^https?://#i', $url)) {
                return response()->json(['message' => 'AI gateway URL must start with http:// or https://'], 422);
            }
            $data['ai_gateway_url'] = $url;
        }

        foreach ($data as $key => $value) {
            if ($value === null) {
                continue;
            }
            $this->settings->set($key, $value);
        }

        return response()->json(['data' => $this->settings->publicPayload()]);
    }
}
