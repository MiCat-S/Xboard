<?php

namespace App\Services;

use App\Models\Plugin;

/**
 * 订阅拉取的地区规则。
 *
 * 规则的开关和名单在后台「插件 → 订阅地区限制」（plugins-core/SubscribeGeo）里配。
 * 这里只放纯判定逻辑，插件和命令行共用，也方便单独测试。
 *
 * 模式：
 *   off    不限制（默认）
 *   allow  只放行名单内的国家
 *   deny   拦掉名单内的国家
 *
 * 归属地查不出来（IPv6 且没有 CF 头、也没放 mmdb 时会这样）一律放行。宁可漏挡
 * 也不能因为查不出来就把正常用户锁在外面——订阅拉不到，客户端就直接没网了。
 */
class SubscribeAccessService
{
    public const PLUGIN_CODE = 'subscribe_geo';

    public const MODE_OFF = 'off';
    public const MODE_ALLOW = 'allow';
    public const MODE_DENY = 'deny';

    public function evaluate(string $mode, string|array $countries, ?string $country): bool
    {
        $mode = $this->normalizeMode($mode);
        if ($mode === self::MODE_OFF) {
            return true;
        }

        $list = is_array($countries) ? $this->normalizeCountries(implode(',', $countries)) : $this->normalizeCountries($countries);
        if (empty($list)) {
            // 模式开着但名单是空的，等于没配，别把所有人都挡在外面
            return true;
        }

        if ($country === null) {
            return true;
        }

        $inList = in_array(strtoupper($country), $list, true);

        return $mode === self::MODE_ALLOW ? $inList : !$inList;
    }

    public function normalizeMode(string $mode): string
    {
        $mode = strtolower(trim($mode));

        return in_array($mode, [self::MODE_ALLOW, self::MODE_DENY], true) ? $mode : self::MODE_OFF;
    }

    /** @return string[] */
    public function normalizeCountries(string $raw): array
    {
        return collect(explode(',', $raw))
            ->map(fn($c) => strtoupper(trim($c)))
            ->filter(fn($c) => strlen($c) === 2)
            ->unique()
            ->values()
            ->all();
    }

    /**
     * 当前实际生效的规则，给命令行展示用。插件没装或没启用时，规则就是不生效的。
     *
     * @return array{installed: bool, enabled: bool, mode: string, countries: string[], effective: bool}
     */
    public function status(): array
    {
        $plugin = Plugin::query()->where('code', self::PLUGIN_CODE)->first();
        $config = $plugin ? (json_decode((string) $plugin->config, true) ?: []) : [];

        $mode = $this->normalizeMode((string) ($config['mode'] ?? self::MODE_OFF));
        $countries = $this->normalizeCountries((string) ($config['countries'] ?? ''));
        $enabled = (bool) ($plugin->is_enabled ?? false);

        return [
            'installed' => $plugin !== null,
            'enabled' => $enabled,
            'mode' => $mode,
            'countries' => $countries,
            'effective' => $enabled && $mode !== self::MODE_OFF && !empty($countries),
        ];
    }
}
