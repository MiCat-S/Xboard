<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * IP 归属地查询。
 *
 * 用 composer 里已有的 zoujingli/ip2region 离线库，不发外部请求。
 * 该库目前只支持 IPv4，IPv6 与查询失败一律返回 null，由调用方决定如何展示。
 */
class IpLocationService
{
    private const CACHE_TTL = 86400;

    private ?\Ip2Region $searcher = null;

    public function lookup(string $ip): ?string
    {
        if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return null;
        }

        return Cache::remember(
            'ip_region:' . $ip,
            self::CACHE_TTL,
            fn(): ?string => $this->search($ip)
        );
    }

    /**
     * 批量查询，返回 [ip => region|null]
     */
    public function lookupMany(array $ips): array
    {
        $result = [];

        foreach (array_unique($ips) as $ip) {
            $result[$ip] = $this->lookup($ip);
        }

        return $result;
    }

    private function search(string $ip): ?string
    {
        try {
            $region = $this->searcher()->simple($ip);
        } catch (\Throwable $e) {
            Log::debug('ip2region lookup failed', ['ip' => $ip, 'error' => $e->getMessage()]);
            return null;
        }

        $region = trim($region);

        return $region === '' ? null : $region;
    }

    private function searcher(): \Ip2Region
    {
        return $this->searcher ??= new \Ip2Region();
    }
}
