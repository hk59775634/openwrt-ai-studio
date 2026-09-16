<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\User;
use App\Services\AiGateway;
use App\Services\SettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_update_ai_settings_and_developer_cannot(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $dev = User::factory()->create(['role' => UserRole::Developer]);

        $this->actingAs($dev, 'sanctum')
            ->getJson('/api/admin/settings')
            ->assertForbidden();

        $this->actingAs($admin, 'sanctum')
            ->getJson('/api/admin/settings')
            ->assertOk()
            ->assertJsonPath('data.ai_gateway_url', 'https://freellmapi.ai101.eu.org')
            ->assertJsonPath('data.ai_gateway_api_key_set', true);

        $this->actingAs($admin, 'sanctum')
            ->putJson('/api/admin/settings', [
                'ai_gateway_url' => 'https://llm.example.test',
                'ai_gateway_api_key' => 'secret-key-123',
                'ai_gateway_model' => 'studio-model',
                'quota_workspaces' => 7,
            ])
            ->assertOk()
            ->assertJsonPath('data.ai_gateway_url', 'https://llm.example.test')
            ->assertJsonPath('data.ai_gateway_model', 'studio-model')
            ->assertJsonPath('data.quota_workspaces', 7)
            ->assertJsonPath('data.ai_gateway_api_key_set', true)
            ->assertJsonMissingPath('data.ai_gateway_api_key');

        $settings = app(SettingsService::class);
        $this->assertSame('https://llm.example.test', app(AiGateway::class)->url());
        $this->assertSame('secret-key-123', $settings->get('ai_gateway_api_key'));
        $this->assertSame(7, $settings->get('quota_workspaces'));
    }

    public function test_blank_api_key_does_not_clear_existing_secret(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $this->actingAs($admin, 'sanctum')->putJson('/api/admin/settings', [
            'ai_gateway_api_key' => 'keep-me',
        ])->assertOk();

        $this->actingAs($admin, 'sanctum')->putJson('/api/admin/settings', [
            'ai_gateway_url' => 'https://llm.example.test',
            'ai_gateway_api_key' => '',
        ])->assertOk();

        $this->assertSame('keep-me', app(SettingsService::class)->get('ai_gateway_api_key'));
    }
}
