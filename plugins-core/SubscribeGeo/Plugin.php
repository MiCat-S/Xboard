<?php

namespace Plugin\SubscribeGeo;

use App\Services\Plugin\AbstractPlugin;
use App\Services\SubscribeAccessService;

/**
 * 订阅地区限制。
 *
 * 做成插件是为了后台有开关：系统设置页的字段是写死在预构建后台里的，新加的
 * 配置项渲染不出来；插件的配置表单则是按 config.json 通用渲染的，下拉框、
 * 输入框都支持。
 *
 * 判定逻辑在 SubscribeAccessService 里，这里只负责把后台配置接到
 * client.subscribe.geo_allowed 这个钩子上。插件没启用时钩子不存在，
 * 中间件拿到的就是默认值 true——也就是不限制。
 */
class Plugin extends AbstractPlugin
{
    public function boot(): void
    {
        $this->filter('client.subscribe.geo_allowed', function ($allowed, ?string $country = null) {
            // 前面若有别的规则已经拦下，就不再放行
            if ($allowed === false) {
                return false;
            }

            return app(SubscribeAccessService::class)->evaluate(
                (string) $this->getConfig('mode', SubscribeAccessService::MODE_OFF),
                (string) $this->getConfig('countries', ''),
                $country
            );
        });
    }
}
