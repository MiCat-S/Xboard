<?php

namespace App\Console\Commands;

use App\Services\SubscribeAccessService;
use Illuminate\Console\Command;

/**
 * 后台面板是预构建的 dist，新加的配置项在界面上渲染不出来，所以用命令行配置。
 */
class SubscribeGeo extends Command
{
    protected $signature = 'subscribe:geo
        {mode? : off（不限制）| allow（只放行名单内）| deny（拦掉名单内）}
        {countries? : 逗号分隔的两位国家码，如 CN 或 CN,HK}';

    protected $description = '查看或设置订阅拉取的地区规则';

    public function handle(SubscribeAccessService $access): int
    {
        $mode = $this->argument('mode');

        if ($mode === null) {
            $this->show($access);
            return self::SUCCESS;
        }

        $mode = strtolower(trim($mode));
        $valid = [SubscribeAccessService::MODE_OFF, SubscribeAccessService::MODE_ALLOW, SubscribeAccessService::MODE_DENY];
        if (!in_array($mode, $valid, true)) {
            $this->error('mode 只能是: ' . implode(' | ', $valid));
            return self::FAILURE;
        }

        $settings = ['subscribe_geo_mode' => $mode];

        $countries = $this->argument('countries');
        if ($countries !== null) {
            $normalized = collect(explode(',', $countries))
                ->map(fn($c) => strtoupper(trim($c)))
                ->filter(fn($c) => strlen($c) === 2)
                ->unique()
                ->values();

            if ($normalized->isEmpty()) {
                $this->error('没有解析出任何有效的两位国家码');
                return self::FAILURE;
            }

            $settings['subscribe_geo_countries'] = $normalized->implode(',');
        }

        admin_setting($settings);

        if ($mode !== SubscribeAccessService::MODE_OFF && empty($access->countries())) {
            $this->warn('模式已开启但国家名单是空的，当前等同于不限制。补一个名单：');
            $this->line("  php artisan subscribe:geo {$mode} CN");
        }

        $this->info('已更新。');
        $this->show($access);

        return self::SUCCESS;
    }

    private function show(SubscribeAccessService $access): void
    {
        $mode = $access->mode();
        $list = $access->countries();

        $this->line('当前模式: <comment>' . $mode . '</comment>');
        $this->line('国家名单: <comment>' . (empty($list) ? '(空)' : implode(', ', $list)) . '</comment>');

        $this->newLine();
        $this->line(match ($mode) {
            SubscribeAccessService::MODE_ALLOW => '效果：只有名单内国家的地址能拉取订阅。',
            SubscribeAccessService::MODE_DENY => '效果：名单内国家的地址拉不到订阅，其余放行。',
            default => '效果：不做地区限制（拉取记录照常写入）。',
        });
        $this->line('<fg=gray>归属地查不出来时一律放行——不能因为查不出来就让用户断网。</>');
    }
}
