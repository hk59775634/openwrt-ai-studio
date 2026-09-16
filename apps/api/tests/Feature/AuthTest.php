<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    public function test_first_user_is_admin_and_can_login(): void
    {
        $register = $this->postJson('/api/register', [
            'name' => 'Ada',
            'email' => 'ada@example.com',
            'password' => 'password123',
        ]);

        $register->assertCreated()
            ->assertJsonPath('user.role', UserRole::Admin->value)
            ->assertJsonStructure(['token', 'user' => ['id', 'email']]);

        $this->postJson('/api/register', [
            'name' => 'Bob',
            'email' => 'bob@example.com',
            'password' => 'password123',
        ])->assertCreated()->assertJsonPath('user.role', UserRole::Developer->value);

        $login = $this->postJson('/api/login', [
            'email' => 'ada@example.com',
            'password' => 'password123',
        ]);

        $login->assertOk()->assertJsonStructure(['token', 'user']);
    }

    public function test_guest_cannot_list_workspaces(): void
    {
        $this->getJson('/api/workspaces')->assertUnauthorized();
    }
}
