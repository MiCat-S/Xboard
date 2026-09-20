<?php

namespace Tests\Feature\Security;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * 未认证入口必须有按 IP 的限流。
 * 业务层原有的按邮箱计数只挡得住单账号，挡不住换账号横扫。
 */
class RateLimitTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    public function test_login_is_rate_limited_per_ip_even_with_different_emails(): void
    {
        $statuses = [];

        for ($i = 1; $i <= 12; $i++) {
            $statuses[] = $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.10'])
                ->postJson('/api/v1/passport/auth/login', [
                    // 每次换邮箱，绕开按邮箱的密码错误计数
                    'email' => "attacker{$i}@example.com",
                    'password' => 'whatever123',
                ])->getStatusCode();
        }

        $this->assertSame(10, count(array_filter($statuses, fn($s) => $s !== 429)));
        $this->assertSame(429, $statuses[10]);
        $this->assertSame(429, $statuses[11]);
    }

    public function test_email_verify_code_sending_is_rate_limited(): void
    {
        $statuses = [];

        for ($i = 1; $i <= 7; $i++) {
            $statuses[] = $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.11'])
                ->postJson('/api/v1/passport/comm/sendEmailVerify', [
                    'email' => "victim{$i}@example.com",
                ])->getStatusCode();
        }

        $this->assertSame(429, $statuses[5]);
    }

    public function test_throttled_response_uses_the_api_error_shape_and_is_localized(): void
    {
        for ($i = 0; $i < 11; $i++) {
            $response = $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.12'])
                ->postJson('/api/v1/passport/auth/login', [
                    'email' => "x{$i}@example.com",
                    'password' => 'whatever123',
                ]);
        }

        $response->assertStatus(429)
            ->assertJsonStructure(['status', 'message', 'data'])
            ->assertJsonPath('status', 'fail');

        $this->assertNotSame('Too Many Attempts.', $response->json('message'));
        $this->assertNotNull($response->headers->get('Retry-After'));
    }

    public function test_payment_callbacks_are_not_rate_limited(): void
    {
        // 网关重推与批量回调不能被限流挡住；这里断言路由上没有挂 throttle
        $route = collect(app('router')->getRoutes())
            ->first(fn($r) => str_contains($r->uri(), 'guest/payment/notify'));

        $this->assertNotNull($route);
        $this->assertEmpty(
            array_filter($route->middleware(), fn($m) => str_starts_with((string) $m, 'throttle'))
        );
    }

    public function test_node_report_endpoints_are_not_rate_limited(): void
    {
        $throttled = collect(app('router')->getRoutes())
            ->filter(fn($r) => str_starts_with($r->uri(), 'api/v2/server/'))
            ->filter(fn($r) => (bool) array_filter(
                $r->middleware(),
                fn($m) => str_starts_with((string) $m, 'throttle')
            ));

        $this->assertCount(0, $throttled);
    }
}
