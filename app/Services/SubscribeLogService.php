<?php

namespace App\Services;

use App\Models\SubscribeLog;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * 记录订阅拉取来源。
 *
 * 按 (user_id, ip) 去重累加，走一条 upsert，不产生明细行。
 */
class SubscribeLogService
{
    public function record(
        int $userId,
        string $ip,
        ?string $country,
        ?string $source,
        ?string $userAgent,
        bool $blocked = false
    ): void {
        $now = time();
        $key = ['user_id' => $userId, 'ip' => $ip];

        try {
            // 先保证行存在。insertOrIgnore 在并发下也只会成功一次，
            // 剩下的靠下面那条自增语句累加，不会丢计数。
            // （不用 upsert + VALUES()：那是 MySQL 专用语法，SQLite 上的测试会挂。）
            SubscribeLog::insertOrIgnore($key + [
                'country' => $country,
                'country_source' => $source,
                'user_agent' => $this->truncate($userAgent),
                'request_count' => 0,
                'blocked_count' => 0,
                'first_seen_at' => $now,
                'last_seen_at' => $now,
            ]);

            SubscribeLog::query()->where($key)->update([
                'country' => $country,
                'country_source' => $source,
                'user_agent' => $this->truncate($userAgent),
                'request_count' => DB::raw('request_count + ' . ($blocked ? 0 : 1)),
                'blocked_count' => DB::raw('blocked_count + ' . ($blocked ? 1 : 0)),
                'last_seen_at' => $now,
            ]);
        } catch (\Throwable $e) {
            // 记日志失败绝不能连累订阅本身
            Log::warning('subscribe log failed', [
                'user_id' => $userId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function truncate(?string $value): ?string
    {
        return $value === null ? null : mb_substr($value, 0, 255);
    }

    /** 清理超过 $days 天没再出现的记录，返回删除行数 */
    public function prune(int $days): int
    {
        return SubscribeLog::query()
            ->where('last_seen_at', '<', time() - $days * 86400)
            ->delete();
    }
}
