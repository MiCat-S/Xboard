<?php

namespace App\Services;

/**
 * 订阅拉取的地区规则。
 *
 * 两个后台配置项：
 *   subscribe_geo_mode       off（默认，不限制）| allow（只放行名单内）| deny（拦掉名单内）
 *   subscribe_geo_countries  逗号分隔的 ISO 3166-1 alpha-2，例如 "CN" 或 "CN,HK"
 *
 * 注意 allow 模式下国家未知（IPv6 且没有 CF 头时会这样）一律放行。宁可漏挡也
 * 不能因为查不出归属地就把正常用户锁在外面——订阅拉不到，客户端就直接没网了。
 */
class SubscribeAccessService
{
    public const MODE_OFF = 'off';
    public const MODE_ALLOW = 'allow';
    public const MODE_DENY = 'deny';

    public function mode(): string
    {
        $mode = strtolower(trim((string) admin_setting('subscribe_geo_mode', self::MODE_OFF)));

        return in_array($mode, [self::MODE_ALLOW, self::MODE_DENY], true) ? $mode : self::MODE_OFF;
    }

    /** @return string[] */
    public function countries(): array
    {
        $raw = (string) admin_setting('subscribe_geo_countries', '');

        return collect(explode(',', $raw))
            ->map(fn($c) => strtoupper(trim($c)))
            ->filter(fn($c) => strlen($c) === 2)
            ->unique()
            ->values()
            ->all();
    }

    public function isAllowed(?string $country): bool
    {
        $mode = $this->mode();
        if ($mode === self::MODE_OFF) {
            return true;
        }

        $list = $this->countries();
        if (empty($list)) {
            // 模式开着但名单是空的，等于没配，别把所有人都挡在外面
            return true;
        }

        if ($country === null) {
            // 归属地未知：allow 模式放行（见类注释），deny 模式也放行——
            // 两种模式下都不该因为「查不出来」而拒绝服务
            return true;
        }

        $inList = in_array(strtoupper($country), $list, true);

        return $mode === self::MODE_ALLOW ? $inList : !$inList;
    }
}
