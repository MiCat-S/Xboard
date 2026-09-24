<?php

namespace App\Services;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use MaxMind\Db\Reader;

/**
 * 把 IP 解析成 ISO 3166-1 alpha-2 国家码。
 *
 * 三个来源，按可靠性排序，前一个拿不到才问下一个：
 *
 * 1. Cloudflare 的 CF-IPCountry 请求头。站点在 CF 后面时这是最准的，IPv4/IPv6
 *    都支持，不需要本地数据库。前提是 CF 面板里开了 IP Geolocation。
 * 2. MaxMind 格式的 mmdb（GeoLite2-Country / GeoIP2-Country / 兼容格式）。
 *    离线、IPv4/IPv6 都支持、全球精度均衡。没放文件就自动跳过。
 * 3. zoujingli/ip2region 离线库兜底。只支持 IPv4，而且对非中国大陆的地址
 *    相当不准（实测把新加坡的地址判成了美国）。
 *
 * 结果里带着来源（cf / mmdb / ip2region），方便事后分辨哪些数据可信。
 */
class GeoIpService
{
    private const CACHE_TTL = 86400;

    /** CF 对无法定位的地址会回 XX，Tor 出口回 T1，都不是真国家 */
    private const CF_PLACEHOLDERS = ['XX', 'T1'];

    /**
     * ip2region 把港澳台都放在「中国」下面（中国|香港、中国|台湾省、中国|澳门），
     * 只看第一段会把这三地的用户当成中国大陆。做 CN 过滤时这个区别很要命，
     * 所以先按第二段判，再回落到第一段。
     */
    private const SUBDIVISION_TO_ISO = [
        '香港' => 'HK',
        '澳门' => 'MO',
        '台湾省' => 'TW',
        '台湾' => 'TW',
    ];

    private const COUNTRY_TO_ISO = [
        '中国' => 'CN',
        '香港' => 'HK',
        '澳门' => 'MO',
        '台湾' => 'TW',
        '日本' => 'JP',
        '韩国' => 'KR',
        '新加坡' => 'SG',
        '美国' => 'US',
        '加拿大' => 'CA',
        '英国' => 'GB',
        '德国' => 'DE',
        '法国' => 'FR',
        '荷兰' => 'NL',
        '俄罗斯' => 'RU',
        '澳大利亚' => 'AU',
        '印度' => 'IN',
        '越南' => 'VN',
        '泰国' => 'TH',
        '马来西亚' => 'MY',
        '印度尼西亚' => 'ID',
        '菲律宾' => 'PH',
        '土耳其' => 'TR',
        '巴西' => 'BR',
    ];

    private ?\Ip2Region $searcher = null;

    /**
     * mmdb 读取器。打开后常驻（Octane 下跨请求复用），打开失败记一次就不再重试，
     * 免得每个请求都去撞一个坏文件。
     */
    private ?Reader $mmdb = null;
    private bool $mmdbFailed = false;

    /**
     * @return array{country: ?string, source: ?string}
     */
    public function resolve(string $ip, ?Request $request = null): array
    {
        $fromHeader = $this->fromCloudflare($request);
        if ($fromHeader !== null) {
            return ['country' => $fromHeader, 'source' => 'cf'];
        }

        $fromMmdb = $this->fromMmdb($ip);
        if ($fromMmdb !== null) {
            return ['country' => $fromMmdb, 'source' => 'mmdb'];
        }

        $fromDatabase = $this->fromDatabase($ip);
        if ($fromDatabase !== null) {
            return ['country' => $fromDatabase, 'source' => 'ip2region'];
        }

        return ['country' => null, 'source' => null];
    }

    /**
     * mmdb 文件路径。默认 storage/geoip/GeoLite2-Country.mmdb，放进去就生效，
     * 不需要任何配置；想换位置可以设 geoip_mmdb_path。
     */
    public function mmdbPath(): string
    {
        $configured = trim((string) admin_setting('geoip_mmdb_path', ''));

        return $configured !== '' ? $configured : storage_path('geoip/GeoLite2-Country.mmdb');
    }

    /**
     * 给诊断命令用：每个离线来源各自对这个 IP 怎么判。
     * CF 头只在真实请求里才有，命令行下看不到，所以不在这里。
     *
     * @return array{mmdb: ?string, ip2region: ?string}
     */
    public function explain(string $ip): array
    {
        return [
            'mmdb' => $this->fromMmdb($ip),
            'ip2region' => $this->fromDatabase($ip),
        ];
    }

    /** 给诊断命令用：mmdb 这一环当前能不能用、为什么 */
    public function mmdbStatus(): array
    {
        $path = $this->mmdbPath();

        if (!is_file($path)) {
            return ['available' => false, 'path' => $path, 'reason' => '文件不存在'];
        }
        if (!is_readable($path)) {
            return ['available' => false, 'path' => $path, 'reason' => '文件不可读（检查属主，运行用户是 www）'];
        }

        $reader = $this->mmdbReader();
        if ($reader === null) {
            return ['available' => false, 'path' => $path, 'reason' => '文件无法解析，可能不是 mmdb 格式或已损坏'];
        }

        $meta = $reader->metadata();

        return [
            'available' => true,
            'path' => $path,
            'type' => $meta->databaseType,
            'built_at' => $meta->buildEpoch,
            'ip_version' => $meta->ipVersion,
        ];
    }

    private function mmdbReader(): ?Reader
    {
        if ($this->mmdb !== null) {
            return $this->mmdb;
        }
        if ($this->mmdbFailed) {
            return null;
        }

        $path = $this->mmdbPath();
        if (!is_file($path) || !is_readable($path)) {
            // 没放文件是正常情况，不算失败，也不记日志——下次放了文件还能用上
            return null;
        }

        try {
            return $this->mmdb = new Reader($path);
        } catch (\Throwable $e) {
            $this->mmdbFailed = true;
            Log::warning('geoip mmdb unreadable', ['path' => $path, 'error' => $e->getMessage()]);
            return null;
        }
    }

    private function fromMmdb(string $ip): ?string
    {
        if (!filter_var($ip, FILTER_VALIDATE_IP)) {
            return null;
        }

        $reader = $this->mmdbReader();
        if ($reader === null) {
            return null;
        }

        try {
            $record = $reader->get($ip);
        } catch (\Throwable $e) {
            Log::warning('geoip mmdb lookup failed', ['ip' => $ip, 'error' => $e->getMessage()]);
            return null;
        }

        // 只认 country（地址实际所在地），不回落 registered_country（地址段的注册地）。
        // 两者会不一样，比如外国公司的地址段在中国使用。这里是拿来做访问限制的，
        // 用注册地判会挡错人；查不出来就交给下一个来源，再不行就放行。
        $code = $record['country']['iso_code'] ?? null;

        return is_string($code) && strlen($code) === 2 ? strtoupper($code) : null;
    }

    private function fromCloudflare(?Request $request): ?string
    {
        if ($request === null) {
            return null;
        }

        $code = strtoupper(trim((string) $request->header('CF-IPCountry')));

        if (strlen($code) !== 2 || in_array($code, self::CF_PLACEHOLDERS, true)) {
            return null;
        }

        return $code;
    }

    private function fromDatabase(string $ip): ?string
    {
        if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return null;
        }

        return Cache::remember(
            'geoip_country:' . $ip,
            self::CACHE_TTL,
            fn(): ?string => $this->search($ip)
        );
    }

    private function search(string $ip): ?string
    {
        try {
            $this->searcher ??= new \Ip2Region();
            $region = $this->searcher->memorySearch($ip)['region'] ?? null;
        } catch (\Throwable $e) {
            // 查不出来不该影响订阅本身
            Log::warning('geoip lookup failed', ['ip' => $ip, 'error' => $e->getMessage()]);
            return null;
        }

        if (!is_string($region) || $region === '') {
            return null;
        }

        // 格式是 国家|省份|城市|运营商，缺失的段是字符串 "0"
        $parts = explode('|', $region);
        $country = trim($parts[0]);
        $subdivision = trim($parts[1] ?? '');

        if (isset(self::SUBDIVISION_TO_ISO[$subdivision])) {
            return self::SUBDIVISION_TO_ISO[$subdivision];
        }

        return self::COUNTRY_TO_ISO[$country] ?? null;
    }
}
