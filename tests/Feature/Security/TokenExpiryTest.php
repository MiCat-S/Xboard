<?php

namespace Tests\Feature\Security;

use App\Models\User;
use App\Services\AuthService;
use App\Utils\Helper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * PersonalAccessToken::findToken() 只按哈希查找，过期判断在 Sanctum 的 Guard 里。
 * AuthService 必须自己补上，否则过期 token 仍能通过 getQuickLoginUrl 换到新会话。
 */
class TokenExpiryTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = new User();
        $this->user->email = 'holder@example.com';
        $this->user->password = bcrypt('secret123');
        $this->user->uuid = Helper::guid(true);
        $this->user->token = Helper::guid();
        $this->user->save();
    }

    private function plainToken(\DateTimeInterface $expiresAt): string
    {
        $token = $this->user->createToken('test', ['*'], $expiresAt)->plainTextToken;

        return explode('|', $token)[1];
    }

    public function test_valid_token_resolves_to_the_user(): void
    {
        $token = $this->plainToken(now()->addYear());

        $this->assertSame(
            $this->user->id,
            AuthService::findUserByBearerToken('Bearer ' . $token)?->id
        );
    }

    public function test_expired_token_is_rejected(): void
    {
        $token = $this->plainToken(now()->subDay());

        $this->assertNull(AuthService::findUserByBearerToken('Bearer ' . $token));
    }

    public function test_token_of_a_banned_user_is_rejected(): void
    {
        $token = $this->plainToken(now()->addYear());

        $this->user->banned = 1;
        $this->user->save();

        $this->assertNull(AuthService::findUserByBearerToken('Bearer ' . $token));
    }

    public function test_empty_and_garbage_tokens_are_rejected(): void
    {
        $this->assertNull(AuthService::findUserByBearerToken('Bearer '));
        $this->assertNull(AuthService::findUserByBearerToken('Bearer not-a-real-token'));
    }

    public function test_expired_token_cannot_mint_a_quick_login_url(): void
    {
        $expired = $this->plainToken(now()->subDay());

        $this->postJson('/api/v1/passport/auth/getQuickLoginUrl', [], [
            'authorization' => 'Bearer ' . $expired,
        ])->assertStatus(401);
    }

    public function test_valid_token_can_mint_a_quick_login_url(): void
    {
        $valid = $this->plainToken(now()->addYear());

        $this->postJson('/api/v1/passport/auth/getQuickLoginUrl', [], [
            'authorization' => 'Bearer ' . $valid,
        ])->assertStatus(200);
    }
}
