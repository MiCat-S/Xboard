<?php

namespace Tests\Feature\Subscribe;

use App\Services\GeoIpService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

/**
 * mmdb 这一环。样本库是 MaxMind 官方仓库的 GeoIP2-Country-Test.mmdb，
 * 专门给测试用的，里面的 IP → 国家都是固定的。
 */
class GeoIpMmdbTest extends TestCase
{
    use RefreshDatabase;

    private const FIXTURE = __DIR__ . '/../../fixtures/GeoIP2-Country-Test.mmdb';

    private function withMmdb(): GeoIpService
    {
        admin_setting(['geoip_mmdb_path' => realpath(self::FIXTURE)]);

        return new GeoIpService();
    }

    private function request(array $headers = []): Request
    {
        $server = [];
        foreach ($headers as $k => $v) {
            $server['HTTP_' . strtoupper(str_replace('-', '_', $k))] = $v;
        }

        return Request::create('/', 'GET', [], [], [], $server);
    }

    public function test_it_resolves_from_the_mmdb(): void
    {
        $geo = $this->withMmdb();

        $this->assertSame(['country' => 'GB', 'source' => 'mmdb'], $geo->resolve('81.2.69.160', $this->request()));
        $this->assertSame(['country' => 'US', 'source' => 'mmdb'], $geo->resolve('216.160.83.56', $this->request()));
    }

    /** 这是加 mmdb 的主要理由：ip2region 查不了 IPv6 */
    public function test_ipv6_resolves_through_the_mmdb(): void
    {
        $geo = $this->withMmdb();

        $this->assertSame(['country' => 'JP', 'source' => 'mmdb'], $geo->resolve('2001:218::', $this->request()));
    }

    /** CF 头仍然最优先，mmdb 只在拿不到头时才上场 */
    public function test_the_cloudflare_header_still_wins(): void
    {
        $geo = $this->withMmdb();

        $this->assertSame(
            ['country' => 'DE', 'source' => 'cf'],
            $geo->resolve('81.2.69.160', $this->request(['CF-IPCountry' => 'DE']))
        );
    }

    /** mmdb 里没有的地址，交给 ip2region，不是直接放弃 */
    public function test_an_address_missing_from_the_mmdb_falls_through_to_ip2region(): void
    {
        $geo = $this->withMmdb();

        // 样本库里没有 114.114.114.114，ip2region 里有
        $this->assertSame(
            ['country' => 'CN', 'source' => 'ip2region'],
            $geo->resolve('114.114.114.114', $this->request())
        );
    }

    /** 没放文件是正常情况：直接跳过这一环，行为和加 mmdb 之前完全一样 */
    public function test_a_missing_file_is_skipped_quietly(): void
    {
        admin_setting(['geoip_mmdb_path' => '/nonexistent/GeoLite2-Country.mmdb']);
        $geo = new GeoIpService();

        $this->assertSame(
            ['country' => 'CN', 'source' => 'ip2region'],
            $geo->resolve('114.114.114.114', $this->request())
        );
        $this->assertFalse($geo->mmdbStatus()['available']);
        $this->assertSame('文件不存在', $geo->mmdbStatus()['reason']);
    }

    /** 坏文件不能连累订阅：跳过，并且报告出原因 */
    public function test_a_corrupt_file_is_skipped_and_reported(): void
    {
        $bad = tempnam(sys_get_temp_dir(), 'mmdb');
        file_put_contents($bad, 'this is not an mmdb file');
        admin_setting(['geoip_mmdb_path' => $bad]);

        try {
            $geo = new GeoIpService();

            $this->assertSame('ip2region', $geo->resolve('114.114.114.114', $this->request())['source']);
            $this->assertFalse($geo->mmdbStatus()['available']);
            $this->assertStringContainsString('无法解析', $geo->mmdbStatus()['reason']);
        } finally {
            @unlink($bad);
        }
    }

    public function test_status_reports_the_database_metadata(): void
    {
        $status = $this->withMmdb()->mmdbStatus();

        $this->assertTrue($status['available']);
        $this->assertSame('GeoIP2-Country', $status['type']);
        $this->assertSame(6, $status['ip_version']);
    }

    /** 默认路径就在 storage/geoip 下，放进去就生效，不需要配置 */
    public function test_the_default_path_needs_no_configuration(): void
    {
        $this->assertSame(
            storage_path('geoip/GeoLite2-Country.mmdb'),
            (new GeoIpService())->mmdbPath()
        );
    }

    public function test_explain_shows_each_source_separately(): void
    {
        $each = $this->withMmdb()->explain('81.2.69.160');

        $this->assertSame('GB', $each['mmdb']);
        // 同一个地址两个库的看法可以不一样，这正是诊断命令要展示的
        $this->assertArrayHasKey('ip2region', $each);
    }
}
