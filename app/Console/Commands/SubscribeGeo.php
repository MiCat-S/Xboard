<?php

namespace App\Console\Commands;

use App\Models\Plugin;
use App\Services\Plugin\PluginConfigService;
use App\Services\SubscribeAccessService;
use Illuminate\Console\Command;

/**
 * 订阅地区规则的命令行入口。
 *
 * 主要入口是后台「插件 → 订阅地区限制」；这里读写的是同一份插件配置，
 * 只是在没法开后台时多一条路。
 */
class SubscribeGeo extends Command
{
    protected $signature = 'subscribe:geo
        {mode? : off（不限制）| allow（只放行名单内）| deny（拦掉名单内）}
        {countries? : 逗号分隔的两位国家码，如 CN 或 CN,HK}';

    protected $description = '查看或设置订阅拉取的地区规则（同后台「订阅地区限制」插件）';

    public function handle(SubscribeAccessService $access, PluginConfigService $configs): int
    {
        $mode = $this->argument('mode');

        if ($mode === null) {
            $this->show($access);
            return self::SUCCESS;
        }

        $plugin = Plugin::query()->where('code', SubscribeAccessService::PLUGIN_CODE)->first();
        if ($plugin === null) {
            $this->error('「订阅地区限制」插件还没安装。先到后台「插件」里安装，再来设置。');
            return self::FAILURE;
        }

        $normalizedMode = $access->normalizeMode($mode);
        if ($normalizedMode !== strtolower(trim($mode))) {
            $this->error('mode 只能是: off | allow | deny');
            return self::FAILURE;
        }

        // updateConfig 会整份覆盖，所以先取出现有配置再改
        $current = json_decode((string) $plugin->config, true) ?: [];
        $current['mode'] = $normalizedMode;

        $countries = $this->argument('countries');
        if ($countries !== null) {
            $list = $access->normalizeCountries($countries);
            if (empty($list)) {
                $this->error('没有解析出任何有效的两位国家码');
                return self::FAILURE;
            }
            $current['countries'] = implode(',', $list);
        }

        $configs->updateConfig(SubscribeAccessService::PLUGIN_CODE, $current);

        $this->info('已更新。');
        $this->show($access);

        return self::SUCCESS;
    }

    private function show(SubscribeAccessService $access): void
    {
        $s = $access->status();

        if (!$s['installed']) {
            $this->line('插件状态: <comment>未安装</comment>');
            $this->line('到后台「插件」里安装「订阅地区限制」即可在界面上配置。当前不做任何地区限制。');
            return;
        }

        $this->line('插件状态: ' . ($s['enabled'] ? '<info>已启用</info>' : '<comment>未启用</comment>'));
        $this->line('限制方式: <comment>' . $s['mode'] . '</comment>');
        $this->line('国家名单: <comment>' . (empty($s['countries']) ? '(空)' : implode(', ', $s['countries'])) . '</comment>');
        $this->newLine();

        if (!$s['effective']) {
            $reason = match (true) {
                !$s['enabled'] => '插件未启用',
                $s['mode'] === SubscribeAccessService::MODE_OFF => '限制方式是「不限制」',
                default => '国家名单为空',
            };
            $this->line("当前效果：<info>不做地区限制</info>（{$reason}）。拉取记录照常写入。");
            return;
        }

        $list = implode(', ', $s['countries']);
        $this->line($s['mode'] === SubscribeAccessService::MODE_ALLOW
            ? "当前效果：<comment>只有 {$list} 的地址能拉取订阅</comment>。"
            : "当前效果：<comment>{$list} 的地址拉不到订阅</comment>，其余放行。");
        $this->line('<fg=gray>归属地查不出来的地址一律放行。</>');
    }
}
