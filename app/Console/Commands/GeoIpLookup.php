<?php

namespace App\Console\Commands;

use App\Services\GeoIpService;
use Illuminate\Console\Command;

class GeoIpLookup extends Command
{
    protected $signature = 'geoip:lookup {ip?* : 要查的 IP，可以一次给多个；不给就只看数据源状态}';

    protected $description = '查看归属地数据源状态，或看某个 IP 被各来源判成哪国';

    public function handle(GeoIpService $geo): int
    {
        $status = $geo->mmdbStatus();

        $this->line('<options=bold>数据源（按优先级）</>');
        $this->line('  1. Cloudflare CF-IPCountry  只在真实请求里有，命令行下看不到');

        if ($status['available']) {
            $this->line(sprintf(
                '  2. mmdb  <info>可用</info>  %s  构建于 %s  %s',
                $status['type'],
                date('Y-m-d', $status['built_at']),
                $status['ip_version'] === 6 ? 'IPv4+IPv6' : 'IPv4'
            ));
            $this->line('           <fg=gray>' . $status['path'] . '</>');
            if (time() - $status['built_at'] > 60 * 86400) {
                $this->warn('           库已超过 60 天没更新，归属地会逐渐失准，建议换新');
            }
        } else {
            $this->line('  2. mmdb  <comment>未启用</comment>  ' . $status['reason']);
            $this->line('           <fg=gray>' . $status['path'] . '</>');
        }

        $this->line('  3. ip2region  <info>可用</info>  仅 IPv4，对大陆以外的地址不准');

        $ips = $this->argument('ip');
        if (empty($ips)) {
            return self::SUCCESS;
        }

        $this->newLine();
        $rows = [];
        foreach ($ips as $ip) {
            if (!filter_var($ip, FILTER_VALIDATE_IP)) {
                $rows[] = [$ip, '<error>不是合法 IP</error>', '', ''];
                continue;
            }
            $each = $geo->explain($ip);
            $final = $geo->resolve($ip);
            $rows[] = [
                $ip,
                $each['mmdb'] ?? '-',
                $each['ip2region'] ?? '-',
                $final['country'] === null ? '<comment>未知 → 放行</comment>' : "{$final['country']}（{$final['source']}）",
            ];
        }

        $this->table(['IP', 'mmdb', 'ip2region', '最终结果'], $rows);

        return self::SUCCESS;
    }
}
