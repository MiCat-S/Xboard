<?php

namespace Tests\Feature\Subscribe;

use App\Models\SubscribeLog;
use App\Models\User;
use App\Services\Plugin\PluginConfigService;
use App\Services\Plugin\PluginManager;
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

    // ---- 纯判定逻辑 ----

    public function test_off_mode_never_restricts(): void
    {
        $this->assertTrue($this->access->evaluate('off', 'CN', 'CN'));
        $this->assertTrue($this->access->evaluate('off', 'CN', 'US'));
    }

    public function test_allow_mode_lets_only_the_listed_countries_through(): void
    {
        $this->assertTrue($this->access->evaluate('allow', 'CN', 'CN'));
        $this->assertFalse($this->access->evaluate('allow', 'CN', 'US'));
        $this->assertFalse($this->access->evaluate('allow', 'CN', 'HK'));
    }

    public function test_deny_mode_blocks_only_the_listed_countries(): void
    {
        $this->assertFalse($this->access->evaluate('deny', 'CN', 'CN'));
        $this->assertTrue($this->access->evaluate('deny', 'CN', 'US'));
    }

    /** 港澳台是独立的国家码，不该被 CN 规则牵连 */
    public function test_hong_kong_macau_and_taiwan_are_not_china(): void
    {
        foreach (['HK', 'TW', 'MO'] as $code) {
            $this->assertTrue($this->access->evaluate('deny', 'CN', $code), $code);
        }
    }

    /** 归属地查不出来时必须放行——订阅拉不到，客户端就直接没网 */
    public function test_an_unknown_country_is_always_allowed(): void
    {
        $this->assertTrue($this->access->evaluate('allow', 'CN', null));
        $this->assertTrue($this->access->evaluate('deny', 'CN', null));
    }

    /** 模式开着但名单是空的，等于没配，不能把所有人都挡掉 */
    public function test_an_empty_list_disables_the_rule(): void
    {
        $this->assertTrue($this->access->evaluate('allow', '', 'US'));
        $this->assertTrue($this->access->evaluate('allow', ' , ,', 'US'));
    }

    public function test_the_country_list_is_normalised(): void
    {
        $this->assertSame(['CN', 'HK', 'JP'], $this->access->normalizeCountries(' cn , hk ,,x, JP ,cn'));
        $this->assertFalse($this->access->evaluate('allow', ' cn , hk ', 'US'));
        $this->assertTrue($this->access->evaluate('allow', ' cn , hk ', 'HK'));
    }

    public function test_an_unrecognised_mode_falls_back_to_off(): void
    {
        $this->assertSame('off', $this->access->normalizeMode('nonsense'));
        $this->assertTrue($this->access->evaluate('nonsense', 'CN', 'CN'));
    }

    // ---- 通过插件接到订阅上（端到端） ----

    private function installPlugin(array $config, bool $enable): void
    {
        $manager = app(PluginManager::class);
        $manager->install(SubscribeAccessService::PLUGIN_CODE);
        app(PluginConfigService::class)->updateConfig(SubscribeAccessService::PLUGIN_CODE, $config);
        if ($enable) {
            $manager->enable(SubscribeAccessService::PLUGIN_CODE);
        }
    }

    public function test_the_plugin_blocks_a_denied_country_and_records_the_attempt(): void
    {
        $this->installPlugin(['mode' => 'deny', 'countries' => 'CN'], enable: true);
        $user = $this->makeUser();

        $this->get($this->subscribeUrl($user), ['CF-IPCountry' => 'CN'])->assertStatus(403);

        $log = SubscribeLog::where('user_id', $user->id)->firstOrFail();
        $this->assertSame('CN', $log->country);
        $this->assertSame('cf', $log->country_source);
        $this->assertSame(1, $log->blocked_count);
        $this->assertSame(0, $log->request_count);
    }

    public function test_the_plugin_lets_other_countries_through(): void
    {
        $this->installPlugin(['mode' => 'deny', 'countries' => 'CN'], enable: true);
        $user = $this->makeUser();

        $this->get($this->subscribeUrl($user), ['CF-IPCountry' => 'JP'])->assertStatus(200);

        $log = SubscribeLog::where('user_id', $user->id)->firstOrFail();
        $this->assertSame(1, $log->request_count);
        $this->assertSame(0, $log->blocked_count);
    }

    /** 「装了但没启用」必须等于不限制——后台默认就是这个状态 */
    public function test_an_installed_but_disabled_plugin_restricts_nothing(): void
    {
        $this->installPlugin(['mode' => 'deny', 'countries' => 'CN'], enable: false);
        $user = $this->makeUser();

        $this->get($this->subscribeUrl($user), ['CF-IPCountry' => 'CN'])->assertStatus(200);
    }

    /** 没装插件时照常记录，只是不拦 */
    public function test_fetches_are_recorded_even_without_the_plugin(): void
    {
        $user = $this->makeUser();

        $this->get($this->subscribeUrl($user), ['CF-IPCountry' => 'CN'])->assertStatus(200);

        $this->assertSame(1, SubscribeLog::where('user_id', $user->id)->value('request_count'));
    }

    public function test_status_reflects_what_is_actually_in_effect(): void
    {
        $this->assertFalse($this->access->status()['installed']);

        $this->installPlugin(['mode' => 'deny', 'countries' => 'CN'], enable: false);
        $s = $this->access->status();
        $this->assertTrue($s['installed']);
        $this->assertFalse($s['enabled']);
        $this->assertFalse($s['effective']);

        app(PluginManager::class)->enable(SubscribeAccessService::PLUGIN_CODE);
        $s = $this->access->status();
        $this->assertTrue($s['effective']);
        $this->assertSame(['CN'], $s['countries']);
    }

    /** 安装后的默认值：不限制、名单预填 CN——后台打开就能直接选模式 */
    public function test_installing_the_plugin_defaults_to_no_restriction(): void
    {
        app(PluginManager::class)->install(SubscribeAccessService::PLUGIN_CODE);

        $s = $this->access->status();
        $this->assertSame('off', $s['mode']);
        $this->assertSame(['CN'], $s['countries']);
        $this->assertFalse($s['effective']);
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
