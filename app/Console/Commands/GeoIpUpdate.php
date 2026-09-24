<?php

namespace App\Console\Commands;

use App\Services\GeoIpService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use MaxMind\Db\Reader;

/**
 * 下载/更新 mmdb 归属地库。
 *
 * 默认源是 DB-IP 的 IP to Country Lite：免注册、CC BY 4.0、每月初发布一版，
 * 格式和 MaxMind 兼容。调度每月跑一次。
 *
 * 新库必须完整通过校验才会替换旧库，任何一步失败都保留旧文件——
 * 更新失败的代价只是库旧一个月，替换成一个坏文件的代价是归属地全部失效。
 */
class GeoIpUpdate extends Command
{
    protected $signature = 'geoip:update
        {--url= : 自定义下载地址（.mmdb 或 .mmdb.gz），不给就用 DB-IP Lite}
        {--force : 即使当前库已经是本月的也重新下载}';

    protected $description = '下载或更新 mmdb 归属地库';

    private const DBIP_URL = 'https://download.db-ip.com/free/dbip-country-lite-%s.mmdb.gz';

    /**
     * 校验用的已知地址。选的是 MaxMind 官方测试库里有、真实库（DB-IP、GeoLite2）
     * 里结果也一致的地址，这样同一套校验在测试和线上都成立。
     */
    private const SANITY = [
        '81.2.69.160' => 'GB',
        '216.160.83.56' => 'US',
        '89.160.20.112' => 'SE',
    ];

    public function handle(GeoIpService $geo): int
    {
        $target = $geo->mmdbPath();
        $dir = dirname($target);

        if (!is_dir($dir) && !@mkdir($dir, 0755, true)) {
            $this->error("目录不存在且无法创建：{$dir}");
            return self::FAILURE;
        }

        if (!$this->option('url') && !$this->option('force') && $this->isCurrent($target)) {
            $this->info('当前库已经是本月的，不需要更新。（--force 可强制重新下载）');
            return self::SUCCESS;
        }

        // 临时文件放在同一目录，保证最后的 rename 是同一文件系统上的原子替换
        $download = tempnam($dir, '.dl-');
        $unpacked = tempnam($dir, '.mmdb-');

        try {
            $url = $this->download($download);
            if ($url === null) {
                return self::FAILURE;
            }

            if (!$this->unpack($download, $unpacked, $url)) {
                return self::FAILURE;
            }

            $meta = $this->verify($unpacked);
            if ($meta === null) {
                return self::FAILURE;
            }

            chmod($unpacked, 0644);
            if (!rename($unpacked, $target)) {
                $this->error("替换失败：无法写入 {$target}");
                return self::FAILURE;
            }

            $this->info(sprintf(
                '已更新：%s，构建于 %s，%s',
                $meta->databaseType,
                date('Y-m-d', $meta->buildEpoch),
                $this->humanSize(filesize($target))
            ));

            return self::SUCCESS;
        } finally {
            @unlink($download);
            @unlink($unpacked);
        }
    }

    /** 当前库的构建时间已经在本月之内，就算最新 */
    private function isCurrent(string $path): bool
    {
        if (!is_file($path)) {
            return false;
        }

        try {
            $reader = new Reader($path);
            $built = $reader->metadata()->buildEpoch;
            $reader->close();
        } catch (\Throwable) {
            return false;
        }

        return $built >= strtotime(date('Y-m-01 00:00:00'));
    }

    /** 返回实际下载成功的 URL，失败返回 null */
    private function download(string $to): ?string
    {
        if ($url = $this->option('url')) {
            return $this->fetch($url, $to) ? $url : null;
        }

        // DB-IP 每月 1 号前后发布新版，月初那几天可能还没挂出来，回落到上个月
        $months = [date('Y-m'), date('Y-m', strtotime('first day of last month'))];
        foreach ($months as $month) {
            $url = sprintf(self::DBIP_URL, $month);
            if ($this->fetch($url, $to, quiet: true)) {
                return $url;
            }
            $this->line("<fg=gray>{$month} 的版本还取不到，试上一个月</>");
        }

        $this->error('DB-IP 本月和上月的版本都下载失败，保留现有库。');
        return null;
    }

    private function fetch(string $url, string $to, bool $quiet = false): bool
    {
        $this->line("下载 {$url}");

        try {
            $response = Http::timeout(180)->get($url);
        } catch (\Throwable $e) {
            $quiet || $this->error('下载失败：' . $e->getMessage());
            return false;
        }

        if (!$response->successful()) {
            $quiet || $this->error("下载失败：HTTP {$response->status()}");
            return false;
        }

        // 压缩包只有几 MB，直接进内存比 sink 简单，而且 Http::fake 能测
        return file_put_contents($to, $response->body()) > 0;
    }

    private function unpack(string $from, string $to, string $url): bool
    {
        // 看文件头判断是不是 gzip，不信后缀名
        $head = (string) file_get_contents($from, false, null, 0, 2);
        if ($head !== "\x1f\x8b") {
            return copy($from, $to);
        }

        $in = gzopen($from, 'rb');
        $out = fopen($to, 'wb');
        if ($in === false || $out === false) {
            $this->error('解压失败：无法打开文件');
            return false;
        }

        while (!gzeof($in)) {
            $chunk = gzread($in, 1 << 20);
            if ($chunk === false) {
                $this->error("解压失败：{$url} 可能下载不完整");
                gzclose($in);
                fclose($out);
                return false;
            }
            fwrite($out, $chunk);
        }

        gzclose($in);
        fclose($out);

        return true;
    }

    /** 能打开、是国家库、查已知地址结果正确，三关都过才算合格 */
    private function verify(string $path): ?object
    {
        try {
            $reader = new Reader($path);
            $meta = $reader->metadata();
        } catch (\Throwable $e) {
            $this->error('新库无法解析，保留现有库：' . $e->getMessage());
            return null;
        }

        if (stripos($meta->databaseType, 'country') === false) {
            $this->error("新库类型是 {$meta->databaseType}，不是国家库，保留现有库。");
            return null;
        }

        foreach (self::SANITY as $ip => $expected) {
            $got = $reader->get($ip)['country']['iso_code'] ?? null;
            if ($got !== $expected) {
                $this->error("新库校验不通过：{$ip} 应为 {$expected}，实际 " . ($got ?? '空') . '，保留现有库。');
                return null;
            }
        }

        $reader->close();

        return $meta;
    }

    private function humanSize(int|false $bytes): string
    {
        return $bytes === false ? '?' : round($bytes / 1048576, 1) . ' MB';
    }
}
