<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use App\Models\User;


class AuthTest extends TestCase
{
    use RefreshDatabase;

     public function test_user_can_register(): void
    {
        $response = $this->postJson('/api/v1/register', [
            'name' => 'Aminata',
            'email' => 'a@example.com',
            'password' => 'secret123',
            'password_confirmation' => 'secret123',
        ]);

        $response->assertCreated()->assertJsonStructure(['user', 'token']);
        $response->assertJsonMissingPath('user.password');
    }

    public function test_register_rejects_duplicate_email(): void
    {
        $user = User::factory()->create(['email' => 'a@example.com']);
        $this->postJson('/api/v1/register', [
            'name' => 'Binta',
            'email' => 'a@example.com',
            'password' => 'secret123',
            'password_confirmation' => 'secret123',
        ])->assertStatus(422)->assertJsonValidationErrors(['email']);
    }
     public function test_register_rejects_mismatched_passwords(): void
    {
        $this->postJson('/api/v1/register', [
            'name' => 'Binta',
            'email' => 'b@example.com',
            'password' => 'secret123',
            'password_confirmation' => 'secret1234',
        ])->assertStatus(422)->assertJsonValidationErrors(['password']);
    }
}