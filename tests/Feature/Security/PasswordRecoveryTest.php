<?php

namespace Tests\Feature\Security;

use App\Jobs\SendEmailJob;
use App\Models\User;
use App\Utils\CacheKey;
use App\Utils\Helper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionProperty;
use Tests\TestCase;

class PasswordRecoveryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        Queue::fake();
        admin_setting([
            'captcha_enable' => 0,
            'email_whitelist_enable' => 0,
            'email_verify' => 1,
            'email_gmail_limit_enable' => 0,
            'stop_register' => 0,
            'invite_force' => 0,
            'register_limit_by_ip_enable' => 0,
        ]);
    }

    public static function apiVersions(): array
    {
        return ['v1' => ['v1'], 'v2' => ['v2']];
    }

    public static function collidingEmails(): array
    {
        $cases = [];
        foreach (['v1', 'v2'] as $version) {
            foreach ([
                'accented domain' => ['admin@example.com', 'admin@éxample.com'],
                'accented local part' => ['admin@example.com', 'ádmin@example.com'],
                'stored internationalized domain' => ['admin@éxample.com', 'admin@example.com'],
            ] as $name => [$storedEmail, $requestEmail]) {
                $cases["{$version} {$name}"] = [$version, $storedEmail, $requestEmail];
            }
        }

        return $cases;
    }

    #[DataProvider('apiVersions')]
    public function test_account_recovery_uses_the_stored_mailbox_across_email_casing(string $version): void
    {
        $user = $this->createUser('admin@example.com');

        $this->postJson("/api/{$version}/passport/comm/sendEmailVerify", [
            'email' => 'ADMIN@EXAMPLE.COM',
        ])->assertOk();

        $code = $this->cachedCodeFor($user->email);
        $this->assertCodeSentTo($user->email, $code);

        $this->postJson("/api/{$version}/passport/auth/forget", [
            'email' => 'Admin@Example.Com',
            'email_code' => (string) $code,
            'password' => 'new-password',
        ])->assertOk()->assertJsonPath('data', true);

        $this->assertTrue(password_verify('new-password', $user->fresh()->password));
        $this->assertNull(Cache::get(CacheKey::get('EMAIL_VERIFY_CODE', $user->email)));
    }

    #[DataProvider('apiVersions')]
    public function test_unregistered_mailbox_can_still_receive_a_code_and_register(string $version): void
    {
        $email = 'new-user@example.com';
        $this->postJson("/api/{$version}/passport/comm/sendEmailVerify", [
            'email' => $email,
        ])->assertOk();

        $code = $this->cachedCodeFor($email);
        $this->assertCodeSentTo($email, $code);

        $this->postJson("/api/{$version}/passport/auth/register", [
            'email' => $email,
            'email_code' => (string) $code,
            'password' => 'new-password',
        ])->assertOk();

        $this->assertDatabaseHas('v2_user', ['email' => $email]);
        $this->assertNull(Cache::get(CacheKey::get('EMAIL_VERIFY_CODE', $email)));
    }

    #[DataProvider('collidingEmails')]
    public function test_collating_email_cannot_redirect_the_account_verification_code(
        string $version,
        string $storedEmail,
        string $requestEmail
    ): void {
        $user = $this->createCollidingUser($storedEmail, $requestEmail);

        $this->postJson("/api/{$version}/passport/comm/sendEmailVerify", [
            'email' => $requestEmail,
        ])->assertOk();

        $code = $this->cachedCodeFor($user->email);
        $this->assertCodeSentTo($user->email, $code);
        $this->assertNull(Cache::get(CacheKey::get('EMAIL_VERIFY_CODE', $requestEmail)));
    }

    #[DataProvider('collidingEmails')]
    public function test_cached_code_for_another_mailbox_cannot_reset_the_account(
        string $version,
        string $storedEmail,
        string $requestEmail
    ): void {
        $user = $this->createCollidingUser($storedEmail, $requestEmail);
        Cache::put(CacheKey::get('EMAIL_VERIFY_CODE', $requestEmail), [
            'email' => $requestEmail,
            'code' => 123456,
        ], 300);

        $this->postJson("/api/{$version}/passport/auth/forget", [
            'email' => $requestEmail,
            'email_code' => '123456',
            'password' => 'attacker-password',
        ])->assertStatus(400)->assertJsonPath('status', 'fail');

        $this->assertTrue(password_verify('old-password', $user->fresh()->password));
    }

    #[DataProvider('apiVersions')]
    public function test_database_cache_key_collision_cannot_reuse_another_mailbox_code(string $version): void
    {
        $user = $this->createCollidingUser('admin@example.com', 'admin@éxample.com');
        $driver = Cache::getDefaultDriver();
        // 临时表不提交当前测试事务，并使用项目的重音不敏感 MySQL 排序规则。
        DB::statement('CREATE TEMPORARY TABLE cache (`key` VARCHAR(255) PRIMARY KEY, `value` MEDIUMTEXT NOT NULL,
            expiration INT NOT NULL) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');

        try {
            Cache::setDefaultDriver('database');
            foreach ([123456, ['email' => 'admin@éxample.com', 'code' => 123456]] as $record) {
                Cache::put(CacheKey::get('EMAIL_VERIFY_CODE', 'admin@éxample.com'), $record, 300);
                $this->assertSame($record, Cache::get(CacheKey::get('EMAIL_VERIFY_CODE', $user->email)));

                $this->postJson("/api/{$version}/passport/auth/forget", [
                    'email' => 'admin@éxample.com',
                    'email_code' => '123456',
                    'password' => 'attacker-password',
                ])->assertStatus(400)->assertJsonPath('status', 'fail');
            }

            $this->assertTrue(password_verify('old-password', $user->fresh()->password));
        } finally {
            Cache::setDefaultDriver($driver);
            DB::statement('DROP TEMPORARY TABLE cache');
        }
    }

    private function createCollidingUser(string $storedEmail, string $requestEmail): User
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped('Requires MySQL accent-insensitive email comparisons; covered by the MySQL CI job.');
        }

        $user = $this->createUser($storedEmail);
        // 确认测试确实触发了数据库的邮箱碰撞，避免在 SQLite 上得到没有意义的通过结果。
        $this->assertSame($user->id, User::byEmail($requestEmail)->value('id'));

        return $user;
    }

    private function createUser(string $email): User
    {
        return User::create([
            'email' => $email,
            'password' => password_hash('old-password', PASSWORD_DEFAULT),
            'uuid' => Helper::guid(true),
            'token' => Helper::guid(),
        ]);
    }

    private function assertCodeSentTo(string $email, int $code): void
    {
        Queue::assertPushed(SendEmailJob::class, function (SendEmailJob $job) use ($email, $code) {
            $params = (new ReflectionProperty($job, 'params'))->getValue($job);

            return $params['email'] === $email && $params['template_value']['code'] === $code;
        });
        Queue::assertPushed(SendEmailJob::class, 1);
    }

    private function cachedCodeFor(string $email): int
    {
        $record = Cache::get(CacheKey::get('EMAIL_VERIFY_CODE', $email));
        $this->assertIsArray($record);
        $this->assertSame($email, $record['email']);

        return $record['code'];
    }
}
