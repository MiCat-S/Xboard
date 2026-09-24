<?php

namespace Tests\Feature\Subscribe;

use App\Models\SubscribeLog;
use App\Services\GeoIpService;
use App\Services\SubscribeLogService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

class SubscribeLogTest extends TestCase
{
    use RefreshDatabase;

    private function request(array $headers = []): Request
    {
        $server = [];
        foreach ($headers as $k => $v) {
            $server['HTTP_' . strtoupper(str_replace('-', '_', $k))] = $v;
        }

        return Request::create('/', 'GET', [], [], [], $server);
    }

    // ---- 归属地解析 ----

    public function test_the_cloudflare_header_wins(): void
    {
        $geo = new GeoIpService();

        // 114.114.114.114 在离线库里是中国，但 CF 头说日本就按日本算
        $this->assertSame(
            ['country' => 'JP', 'source' => 'cf'],
            $geo->resolve('114.114.114.114', $this->request(['CF-IPCountry' => 'JP']))
        );
    }

    /** CF 定位不出来时会回 XX，Tor 出口回 T1，这些不是国家 */
    public function test_cloudflare_placeholders_fall_through(): void
    {
        $geo = new GeoIpService();

        $result = $geo->resolve('114.114.114.114', $this->request(['CF-IPCountry' => 'XX']));

        $this->assertSame('CN', $result['country']);
        $this->assertSame('ip2region', $result['source']);
    }

    public function test_it_falls_back_to_the_offline_database(): void
    {
        $geo = new GeoIpService();

        $result = $geo->resolve('114.114.114.114', $this->request());

        $this->assertSame(['country' => 'CN', 'source' => 'ip2region'], $result);
    }

    /**
     * 离线库把港澳台放在「中国」下面（中国|香港、中国|台湾省、中国|澳门），
     * 只看第一段会把这三地判成 CN，做 CN 过滤时会误伤。
     */
    public function test_hong_kong_macau_and_taiwan_get_their_own_codes(): void
    {
        $geo = new GeoIpService();

        $this->assertSame('HK', $geo->resolve('218.188.154.38', $this->request())['country']);
        $this->assertSame('TW', $geo->resolve('1.34.1.1', $this->request())['country']);
        $this->assertSame('MO', $geo->resolve('202.86.160.1', $this->request())['country']);
    }

    /** 离线库只支持 IPv4，IPv6 拿不到就如实返回 null，别瞎猜 */
    public function test_ipv6_without_a_header_is_unknown(): void
    {
        $geo = new GeoIpService();

        $this->assertSame(
            ['country' => null, 'source' => null],
            $geo->resolve('2408:8240:439:39b0:35df:cec6:4935:119f', $this->request())
        );
    }

    public function test_ipv6_still_resolves_from_the_header(): void
    {
        $geo = new GeoIpService();

        $this->assertSame(
            ['country' => 'CN', 'source' => 'cf'],
            $geo->resolve('2408:8240:439:39b0:35df:cec6:4935:119f', $this->request(['CF-IPCountry' => 'cn']))
        );
    }

    // ---- 记录累加 ----

    public function test_repeated_fetches_from_one_ip_stay_a_single_row(): void
    {
        $service = new SubscribeLogService();

        for ($i = 0; $i < 3; $i++) {
            $service->record(7, '1.2.3.4', 'CN', 'cf', 'Clash.Meta');
        }

        $this->assertSame(1, SubscribeLog::count());
        $row = SubscribeLog::first();
        $this->assertSame(3, $row->request_count);
        $this->assertSame(0, $row->blocked_count);
    }

    public function test_different_ips_are_separate_rows(): void
    {
        $service = new SubscribeLogService();
        $service->record(7, '1.2.3.4', 'CN', 'cf', 'a');
        $service->record(7, '5.6.7.8', 'US', 'cf', 'b');

        $this->assertSame(2, SubscribeLog::where('user_id', 7)->count());
    }

    public function test_blocked_attempts_are_counted_separately(): void
    {
        $service = new SubscribeLogService();
        $service->record(7, '1.2.3.4', 'CN', 'cf', 'a', true);
        $service->record(7, '1.2.3.4', 'CN', 'cf', 'a', true);
        $service->record(7, '1.2.3.4', 'CN', 'cf', 'a');

        $row = SubscribeLog::first();
        $this->assertSame(2, $row->blocked_count);
        $this->assertSame(1, $row->request_count);
    }

    /** first_seen_at 记的是第一次，不该被后来的拉取覆盖 */
    public function test_the_first_seen_timestamp_is_preserved(): void
    {
        $service = new SubscribeLogService();
        $service->record(7, '1.2.3.4', 'CN', 'cf', 'a');
        $first = SubscribeLog::first()->first_seen_at;

        SubscribeLog::query()->update(['first_seen_at' => $first - 3600]);
        $service->record(7, '1.2.3.4', 'CN', 'cf', 'a');

        $this->assertSame($first - 3600, SubscribeLog::first()->first_seen_at);
    }

    public function test_an_overlong_user_agent_is_truncated(): void
    {
        (new SubscribeLogService())->record(7, '1.2.3.4', 'CN', 'cf', str_repeat('x', 400));

        $this->assertSame(255, mb_strlen(SubscribeLog::first()->user_agent));
    }

    public function test_prune_only_removes_stale_rows(): void
    {
        $service = new SubscribeLogService();
        $service->record(7, '1.2.3.4', 'CN', 'cf', 'old');
        SubscribeLog::query()->update(['last_seen_at' => time() - 100 * 86400]);
        $service->record(7, '5.6.7.8', 'CN', 'cf', 'fresh');

        $this->assertSame(1, $service->prune(90));
        $this->assertSame(['5.6.7.8'], SubscribeLog::pluck('ip')->all());
    }
}
