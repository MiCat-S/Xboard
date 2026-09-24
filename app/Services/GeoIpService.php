<?php

namespace App\Services;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * 把 IP 解析成 ISO 3166-1 alpha-2 国家码。
 *
 * 两个来源，按可靠性排序：
 *
 * 1. Cloudflare 的 CF-IPCountry 请求头。站点在 CF 后面时这是最准的，IPv4/IPv6
 *    都支持，不需要本地数据库。前提是 CF 面板里开了 IP Geolocation。
 * 2. zoujingli/ip2region 离线库兜底。它只支持 IPv4，而且对非中国大陆的地址
 *    相当不准（实测把新加坡的地址判成了美国），所以只在拿不到 CF 头时用，
 *    并且结果里会标明来源，方便事后分辨哪些数据可信。
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
     * @return array{country: ?string, source: ?string}
     */
    public function resolve(string $ip, ?Request $request = null): array
    {
        $fromHeader = $this->fromCloudflare($request);
        if ($fromHeader !== null) {
            return ['country' => $fromHeader, 'source' => 'cf'];
        }

        $fromDatabase = $this->fromDatabase($ip);
        if ($fromDatabase !== null) {
            return ['country' => $fromDatabase, 'source' => 'ip2region'];
        }

        return ['country' => null, 'source' => null];
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
