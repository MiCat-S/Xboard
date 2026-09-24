<?php

namespace Tests\Feature\Subscribe;

use App\Models\SubscribeLog;
use App\Models\User;
use App\Services\SubscribeAccessService;
use App\Utils\Helper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 订阅拉取的地区规则。挡错人的代价是用户直接断网，所以这里把每条分支都钉住。
 */
class SubscribeGeoTest extends TestCase
{
    use RefreshDatabase;

    private SubscribeAccessService $access;

    protected function setUp(): void
    {
        parent::setUp();
        $this->access = new SubscribeAccessService();
    }

    private function configure(string $mode, string $countries = ''): void
    {
        admin_setting([
            'subscribe_geo_mode' => $mode,
            'subscribe_geo_countries' => $countries,
        ]);
    }

    public function test_it_does_not_restrict_by_default(): void
    {
        $this->assertSame('off', $this->access->mode());
        $this->assertTrue($this->access->isAllowed('CN'));
        $this->assertTrue($this->access->isAllowed('US'));
        $this->assertTrue($this->access->isAllowed(null));
    }

    public function test_allow_mode_lets_only_the_listed_countries_through(): void
    {
        $this->configure('allow', 'CN');

        $this->assertTrue($this->access->isAllowed('CN'));
        $this->assertFalse($this->access->isAllowed('US'));
        $this->assertFalse($this->access->isAllowed('HK'));
    }

    public function test_deny_mode_blocks_only_the_listed_countries(): void
    {
        $this->configure('deny', 'CN');

        $this->assertFalse($this->access->isAllowed('CN'));
        $this->assertTrue($this->access->isAllowed('US'));
        $this->assertTrue($this->access->isAllowed('HK'));
    }

    /** 港澳台是独立的国家码，不该被 CN 规则牵连 */
    public function test_hong_kong_is_not_treated_as_china(): void
    {
        $this->configure('deny', 'CN');

        $this->assertTrue($this->access->isAllowed('HK'));
        $this->assertTrue($this->access->isAllowed('TW'));
        $this->assertTrue($this->access->isAllowed('MO'));
    }

    /**
     * 归属地查不出来（IPv6 且没有 CF 头就会这样）时必须放行。
     * 宁可漏挡也不能把正常用户锁在外面——订阅拉不到，客户端就直接没网。
     */
    public function test_an_unknown_country_is_always_allowed(): void
    {
        $this->configure('allow', 'CN');
        $this->assertTrue($this->access->isAllowed(null));

        $this->configure('deny', 'CN');
        $this->assertTrue($this->access->isAllowed(null));
    }

    /** 模式开着但名单是空的，等于没配，不能把所有人都挡掉 */
    public function test_an_empty_list_disables_the_rule(): void
    {
        $this->configure('allow', '');

        $this->assertTrue($this->access->isAllowed('US'));
        $this->assertTrue($this->access->isAllowed('CN'));
    }

    public function test_the_country_list_is_normalised(): void
    {
        $this->configure('allow', ' cn , hk ,,x, JP ');

        $this->assertSame(['CN', 'HK', 'JP'], $this->access->countries());
    }

    public function test_an_unrecognised_mode_falls_back_to_off(): void
    {
        $this->configure('nonsense', 'CN');

        $this->assertSame('off', $this->access->mode());
        $this->assertTrue($this->access->isAllowed('US'));
    }

    /** 端到端：规则生效时订阅返回 403，并且这次尝试被记了下来 */
    public function test_a_blocked_fetch_returns_403_and_is_recorded(): void
    {
        $this->configure('deny', 'CN');
        $user = $this->makeUser();

        $this->withServerVariables(['REMOTE_ADDR' => '1.2.3.4'])
            ->get($this->subscribeUrl($user), ['CF-IPCountry' => 'CN'])
            ->assertStatus(403);

        $log = SubscribeLog::where('user_id', $user->id)->firstOrFail();
        $this->assertSame('CN', $log->country);
        $this->assertSame('cf', $log->country_source);
        $this->assertSame(1, $log->blocked_count);
        $this->assertSame(0, $log->request_count);
    }

    public function test_an_allowed_fetch_is_recorded_without_being_blocked(): void
    {
        $this->configure('deny', 'CN');
        $user = $this->makeUser();

        $this->get($this->subscribeUrl($user), ['CF-IPCountry' => 'JP'])
            ->assertStatus(200);

        $log = SubscribeLog::where('user_id', $user->id)->firstOrFail();
        $this->assertSame('JP', $log->country);
        $this->assertSame(0, $log->blocked_count);
        $this->assertSame(1, $log->request_count);
    }

    private function makeUser(): User
    {
        $user = new User();
        $user->email = 'sub' . uniqid() . '@example.com';
        $user->password = bcrypt('secret123');
        $user->uuid = Helper::guid(true);
        $user->token = Helper::guid();
        $user->transfer_enable = 1000000;
        $user->expired_at = time() + 86400;
        $user->save();

        return $user;
    }

    private function subscribeUrl(User $user): string
    {
        return '/api/v1/client/subscribe?token=' . $user->token;
    }
}
