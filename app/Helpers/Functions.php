<?php
use App\Support\Setting;

if (!function_exists('admin_setting')) {
    /**
     * 获取或保存配置参数.
     *
     * @param  string|array  $key
     * @param  mixed  $default
     * @return App\Support\Setting|mixed
     */
    function admin_setting($key = null, $default = null)
    {
        $setting = app(Setting::class);

        if ($key === null) {
            return $setting->toArray();
        }

        if (is_array($key)) {
            $setting->save($key);
            return '';
        }

        $default = config('v2board.' . $key) ?? $default;
        return $setting->get($key) ?? $default;
    }
}

if (!function_exists('subscribe_template')) {
    /**
     * Get subscribe template content by protocol name.
     */
    function subscribe_template(string $name): ?string
    {
        return \App\Models\SubscribeTemplate::getContent($name);
    }
}

if (!function_exists('admin_settings_batch')) {
    /**
     * 批量获取配置参数，性能优化版本
     *
     * @param array $keys 配置键名数组
     * @return array 返回键值对数组
     */
    function admin_settings_batch(array $keys): array
    {
        return app(Setting::class)->getBatch($keys);
    }
}

if (!function_exists('source_base_url')) {
    /**
     * 获取来源基础URL，优先Referer，其次Host。
     *
     * 该结果会作为支付网关的 return_url，Referer 完全由客户端控制，
     * 因此只在其主机名与站点自身域名一致时才采用，否则回落到请求域名 / app_url，
     * 避免变成一个把用户带去外站的开放重定向。
     *
     * @param string $path
     * @return string
     */
    function source_base_url(string $path = ''): string
    {
        $path = ltrim($path, '/');
        $requestBase = rtrim(request()->getSchemeAndHttpHost(), '/');
        $appUrl = rtrim((string) admin_setting('app_url', ''), '/');

        $allowedHosts = [];
        foreach ([$appUrl, $requestBase] as $candidate) {
            $host = $candidate === '' ? null : parse_url($candidate, PHP_URL_HOST);
            if ($host) {
                $allowedHosts[] = strtolower($host);
            }
        }

        $baseUrl = '';
        $referer = request()->header('Referer');

        if ($referer) {
            $parsedUrl = parse_url($referer);
            if (
                isset($parsedUrl['scheme'], $parsedUrl['host'])
                && in_array(strtolower($parsedUrl['scheme']), ['http', 'https'], true)
                && in_array(strtolower($parsedUrl['host']), $allowedHosts, true)
            ) {
                $baseUrl = $parsedUrl['scheme'] . '://' . $parsedUrl['host'];
                if (isset($parsedUrl['port'])) {
                    $baseUrl .= ':' . $parsedUrl['port'];
                }
            }
        }

        if (!$baseUrl) {
            $baseUrl = $appUrl !== '' ? $appUrl : $requestBase;
        }

        return rtrim($baseUrl, '/') . '/' . $path;
    }
}
