<?php

namespace Tests\Feature\Subscribe;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use MaxMind\Db\Reader;
use Tests\TestCase;

/**
 * 归属地库的自动更新。核心约束：新库没通过校验就绝不替换旧库。
 * 全程用 Http::fake，不碰网络。
 */
class GeoIpUpdateTest extends TestCase
{
    use RefreshDatabase;

    private const FIXTURE = __DIR__ . '/../../fixtures/GeoIP2-Country-Test.mmdb';

    private string $dir;
    private string $target;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir() . '/geoip-test-' . uniqid();
        mkdir($this->dir);
        $this->target = $this->dir . '/GeoLite2-Country.mmdb';
        admin_setting(['geoip_mmdb_path' => $this->target]);
    }

    protected function tearDown(): void
    {
        foreach (array_diff(scandir($this->dir) ?: [], ['.', '..']) as $f) {
            @unlink($this->dir . '/' . $f);
        }
        @rmdir($this->dir);
        parent::tearDown();
    }

    private function gzipped(): string
    {
        return gzencode(file_get_contents(self::FIXTURE));
    }

    public function test_it_downloads_unpacks_and_installs_the_database(): void
    {
        Http::fake(['download.db-ip.com/*' => Http::response($this->gzipped())]);

        $this->artisan('geoip:update')->assertSuccessful();

        $this->assertFileExists($this->target);
        $this->assertSame('GB', (new Reader($this->target))->get('81.2.69.160')['country']['iso_code']);
    }

    /** 月初新版可能还没挂出来，要回落到上个月，而不是直接失败 */
    public function test_it_falls_back_to_last_month_when_this_month_is_not_out(): void
    {
        $thisMonth = date('Y-m');
        Http::fake([
            "download.db-ip.com/free/dbip-country-lite-{$thisMonth}.mmdb.gz" => Http::response('', 404),
            'download.db-ip.com/*' => Http::response($this->gzipped()),
        ]);

        $this->artisan('geoip:update')->assertSuccessful();

        $this->assertFileExists($this->target);
    }

    /** 下载下来的不是 mmdb：旧库必须原封不动 */
    public function test_a_corrupt_download_keeps_the_old_database(): void
    {
        file_put_contents($this->target, 'OLD-DATABASE');
        Http::fake(['download.db-ip.com/*' => Http::response(gzencode('not a database'))]);

        $this->artisan('geoip:update')->assertFailed();

        $this->assertSame('OLD-DATABASE', file_get_contents($this->target));
    }

    public function test_a_failed_download_keeps_the_old_database(): void
    {
        file_put_contents($this->target, 'OLD-DATABASE');
        Http::fake(['download.db-ip.com/*' => Http::response('', 503)]);

        $this->artisan('geoip:update')->assertFailed();

        $this->assertSame('OLD-DATABASE', file_get_contents($this->target));
    }

    /** 失败也不能在目录里留下临时文件，否则每月积一批 */
    public function test_no_temporary_files_are_left_behind(): void
    {
        // 先一次失败、再一次成功。Http::fake 按注册顺序匹配，重复 fake 不会覆盖，所以用序列
        Http::fake(['download.db-ip.com/*' => Http::sequence()
            ->push(gzencode('garbage'))
            ->push($this->gzipped())]);

        $this->artisan('geoip:update')->assertFailed();
        $this->artisan('geoip:update')->assertSuccessful();

        $left = array_values(array_diff(scandir($this->dir), ['.', '..']));
        $this->assertSame(['GeoLite2-Country.mmdb'], $left);
    }

    /** --url 给的是没压缩的 .mmdb 也要能处理：按文件头判断，不信后缀 */
    public function test_a_custom_url_serving_a_plain_mmdb_works(): void
    {
        Http::fake(['example.com/*' => Http::response(file_get_contents(self::FIXTURE))]);

        $this->artisan('geoip:update', ['--url' => 'https://example.com/my.mmdb'])->assertSuccessful();

        $this->assertSame('US', (new Reader($this->target))->get('216.160.83.56')['country']['iso_code']);
    }

    /** 装好的文件要让 php-fpm 的 www 用户读得到——调度可能是以 root 跑的 */
    public function test_the_installed_file_is_world_readable(): void
    {
        Http::fake(['download.db-ip.com/*' => Http::response($this->gzipped())]);

        $this->artisan('geoip:update')->assertSuccessful();

        $this->assertSame('0644', substr(sprintf('%o', fileperms($this->target)), -4));
    }
}
