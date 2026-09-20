<?php

namespace Tests\Unit\Security;

use App\Http\Middleware\RequestLog;
use App\Services\Plugin\PluginManager;
use App\Services\ThemeService;
use App\Utils\Helper;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionMethod;
use Tests\TestCase;

/**
 * 纯函数层面的加固：跳转过滤、路径名校验、审计脱敏、历史口令比较。
 */
class InputHardeningTest extends TestCase
{
    public static function unsafeRedirects(): array
    {
        return [
            'absolute https' => ['https://evil.com'],
            'absolute http' => ['http://evil.com/path'],
            'protocol relative' => ['//evil.com'],
            'javascript scheme' => ['javascript:alert(1)'],
            'data scheme' => ['data:text/html,x'],
            'absolute path' => ['/etc/passwd'],
            'traversal' => ['../../secret'],
            'encoded traversal' => ['..%2f..%2fx'],
            'backslash' => ['a\\b'],
            'crlf injection' => ["x\r\nSet-Cookie: a=b"],
            'empty' => [''],
            'too long' => [/* 300 chars */ ''],
        ];
    }

    #[DataProvider('unsafeRedirects')]
    public function test_unsafe_redirects_fall_back_to_default(string $redirect): void
    {
        if ($redirect === '') {
            $redirect = str_repeat('a', 300);
        }

        $this->assertSame('dashboard', Helper::sanitizeRedirect($redirect));
    }

    public function test_non_string_redirect_falls_back_to_default(): void
    {
        $this->assertSame('dashboard', Helper::sanitizeRedirect(null));
        $this->assertSame('dashboard', Helper::sanitizeRedirect(['a']));
    }

    public function test_in_site_relative_paths_are_preserved(): void
    {
        foreach (['dashboard', 'order/2024', 'plan?id=3', 'invite'] as $redirect) {
            $this->assertSame($redirect, Helper::sanitizeRedirect($redirect));
        }
    }

    public function test_theme_names_that_escape_the_theme_directory_are_rejected(): void
    {
        foreach (['Xboard', 'v2board', 'my-theme_1'] as $valid) {
            $this->assertTrue(ThemeService::isValidThemeName($valid), $valid);
        }

        foreach (['../../app', 'a/b', '..', '', 'theme;rm -rf', 'thème'] as $invalid) {
            $this->assertFalse(ThemeService::isValidThemeName($invalid), $invalid);
        }
    }

    public function test_plugin_codes_that_escape_the_plugin_directory_are_rejected(): void
    {
        foreach (['epay', 'coin_payments', 'btcpay'] as $valid) {
            $this->assertTrue(PluginManager::isValidPluginCode($valid), $valid);
        }

        foreach (['../../storage', 'Epay', 'a b', '', 'a-b', 'a/b'] as $invalid) {
            $this->assertFalse(PluginManager::isValidPluginCode($invalid), $invalid);
        }
    }

    public function test_theme_service_refuses_to_delete_outside_the_theme_directory(): void
    {
        $this->expectExceptionMessage('Invalid theme name');

        app(ThemeService::class)->delete('../../app');
    }

    public function test_plugin_manager_refuses_paths_outside_the_plugin_directory(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        app(PluginManager::class)->getUserPluginPath('../../storage');
    }

    public function test_audit_log_redacts_nested_gateway_credentials(): void
    {
        $redact = new ReflectionMethod(RequestLog::class, 'redact');
        $redact->setAccessible(true);

        $result = $redact->invoke(null, [
            'code' => 'epay',
            'name' => '支付宝',
            'keyword' => '搜索词',
            'password' => 'p@ss',
            'email_code' => '123456',
            'config' => [
                'pid' => '1001',
                'key' => 'SUPERSECRET',
                'private_key' => '-----BEGIN',
                'btcpay_webhook_key' => 'whk_live',
                'app_secret' => 'as_x',
                'notify_domain' => 'https://a.b',
            ],
        ]);

        // 敏感项被打码
        foreach (['key', 'private_key', 'btcpay_webhook_key', 'app_secret'] as $secret) {
            $this->assertSame('******', $result['config'][$secret], $secret);
        }
        $this->assertSame('******', $result['password']);
        $this->assertSame('******', $result['email_code']);

        // 审计日志仍需能看出操作对象与非敏感参数
        $this->assertSame('epay', $result['code']);
        $this->assertSame('支付宝', $result['name']);
        $this->assertSame('搜索词', $result['keyword']);
        $this->assertSame('1001', $result['config']['pid']);
        $this->assertSame('https://a.b', $result['config']['notify_domain']);
    }

    public function test_legacy_password_algorithms_still_verify(): void
    {
        $cases = [
            ['md5', null, md5('secret123')],
            ['sha256', null, hash('sha256', 'secret123')],
            ['md5salt', 'NaCl', md5('secret123' . 'NaCl')],
            ['sha256salt', 'NaCl', hash('sha256', 'secret123' . 'NaCl')],
        ];

        foreach ($cases as [$algo, $salt, $hash]) {
            $this->assertTrue(Helper::multiPasswordVerify($algo, $salt, 'secret123', $hash), $algo);
            $this->assertFalse(Helper::multiPasswordVerify($algo, $salt, 'wrong', $hash), $algo);
        }

        $bcrypt = password_hash('secret123', PASSWORD_DEFAULT);
        $this->assertTrue(Helper::multiPasswordVerify(null, null, 'secret123', $bcrypt));
        $this->assertFalse(Helper::multiPasswordVerify(null, null, 'wrong', $bcrypt));
    }

    public function test_generated_codes_are_not_predictable_and_keep_their_format(): void
    {
        $codes = [];
        for ($i = 0; $i < 500; $i++) {
            $codes[] = Helper::randomChar(8);
        }

        $this->assertCount(500, array_unique($codes));
        foreach (array_slice($codes, 0, 20) as $code) {
            $this->assertMatchesRegularExpression('/^[A-Za-z0-9]{8}$/', $code);
        }
    }
}
