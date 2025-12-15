<?php

namespace Tests\Feature\Auth;

use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use App\Models\User;

class LoginTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function it_requires_email_and_password(): void
    {
        $response = $this->post('/api/auth/login', [
            'email' => '',
            'password' => ''
        ]);

        $this->assertValidationError($response, 'email');
        $this->assertValidationError($response, 'password');
    }

    /** @test */
    public function it_requires_valid_credentials(): void
    {
        $response = $this->post('/api/auth/login', [
            'email' => 'invalid@example.com',
            'password' => 'wrongpassword'
        ]);

        $this->assertUnauthorizedResponse($response);
    }

    /** @test */
    public function it_authenticates_with_valid_credentials(): void
    {
        $user = User::factory()->create([
            'password' => bcrypt('password123')
        ]);

        $response = $this->post('/api/auth/login', [
            'email' => $user->email,
            'password' => 'password123'
        ]);

        $this->assertSuccessResponse($response);
        $this->assertArrayHasKey('token', $response->json('data'));
        $this->assertArrayHasKey('user', $response->json('data'));
    }

    /** @test */
    public function it_returns_user_data_with_token(): void
    {
        $user = User::factory()->create([
            'password' => bcrypt('password123')
        ]);

        $response = $this->post('/api/auth/login', [
            'email' => $user->email,
            'password' => 'password123'
        ]);

        $data = $response->json('data');

        $this->assertEquals($user->id, $data['user']['id']);
        $this->assertEquals($user->name, $data['user']['name']);
        $this->assertEquals($user->email, $data['user']['email']);
        $this->assertNotEmpty($data['token']);
    }

    /** @test */
    public function it_handles_rate_limiting(): void
    {
        // Simulate multiple login attempts
        for ($i = 0; $i < 6; $i++) {
            $this->post('/api/auth/login', [
                'email' => 'test@example.com',
                'password' => 'wrongpassword'
            ]);
        }

        // Last attempt should trigger rate limiting
        $response = $this->post('/api/auth/login', [
            'email' => 'test@example.com',
            'password' => 'wrongpassword'
        ]);

        $this->assertStatus(429); // Too Many Requests
    }

    /** @test */
    public function it_validates_email_format(): void
    {
        $response = $this->post('/api/auth/login', [
            'email' => 'invalid-email',
            'password' => 'password123'
        ]);

        $this->assertValidationError($response, 'email');
    }

    /** @test */
    public function it_handles_inactive_user(): void
    {
        $user = User::factory()->create(['status' => 'inactive']);

        $response = $this->post('/api/auth/login', [
            'email' => $user->email,
            'password' => 'password123'
        ]);

        $this->assertUnauthorizedResponse($response);
    }

    /** @test */
    public function it_handles_suspended_user(): void
    {
        $user = User::factory()->create(['status' => 'suspended']);

        $response = $this->post('/api/auth/login', [
            'email' => $user->email,
            'password' => 'password123'
        ]);

        $this->assertUnauthorizedResponse($response);
    }

    /** @test */
    public function it_remember_me_functionality_works(): void
    {
        $user = User::factory()->create([
            'password' => bcrypt('password123')
        ]);

        $response = $this->post('/api/auth/login', [
            'email' => $user->email,
            'password' => 'password123',
            'remember_me' => true
        ]);

        $this->assertSuccessResponse($response);

        // Check if remember token is set
        $this->assertDatabaseHas('personal_access_tokens', [
            'tokenable_type' => \App\Models\User::class,
            'tokenable_id' => $user->id
        ]);
    }

    /** @test */
    public function it_logout_functionality_works(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('auth_token');

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $token->plainTextToken
        ])->post('/api/auth/logout');

        $this->assertSuccessResponse($response);

        // Verify token is revoked
        $this->assertDatabaseMissing('personal_access_tokens', [
            'id' => $token->id
        ]);
    }

    /** @test */
    public function it_handles_expired_token(): void
    {
        $user = User::factory()->create();
        $expiredToken = $user->createToken('auth_token', now()->subMinutes(30));

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $expiredToken->plainTextToken
        ])->post('/api/auth/logout');

        $this->assertSuccessResponse($response);
    }

    /** @test */
    public function it_prevents_brute_force_attacks(): void
    {
        // Test with suspicious activity
        $user = User::factory()->create();

        // Simulate rapid login attempts
        for ($i = 0; $i < 10; $i++) {
            $this->post('/api/auth/login', [
                'email' => $user->email,
                'password' => 'wrongpassword' . $i
            ]);
        }

        // Should trigger security measures
        $response = $this->post('/api/auth/login', [
            'email' => $user->email,
            'password' => 'password123'
        ]);

        // Response should be delayed or blocked
        $this->assertContains($response->status(), [429, 403]);
    }

    /** @test */
    public function it_sanitizes_input(): void
    {
        $response = $this->post('/api/auth/login', [
            'email' => '<script>alert("xss")</script>@test.com',
            'password' => 'password123'
        ]);

        $this->assertValidationError($response, 'email');

        // Verify XSS is prevented
        $this->assertStringNotContainsString('<script>', $response->content());
    }

    /** @test */
    public function it_maintains_session_security(): void
    {
        $user = User::factory()->create([
            'password' => bcrypt('password123')
        ]);

        $response = $this->post('/api/auth/login', [
            'email' => $user->email,
            'password' => 'password123'
        ]);

        $this->assertSuccessResponse($response);

        // Verify session is secure
        $this->assertArrayHasKey('token', $response->json('data'));
        $token = $response->json('data')['token'];
        $this->assertNotEmpty($token);

        // Verify token is properly formatted
        $this->assertStringStartsWith('Bearer ', $token);
    }
}
