<?php

namespace App\Console\Commands;

use App\Models\SubscribeLog;
use App\Services\SubscribeLogService;
use Illuminate\Console\Command;

class SubscribeLogCommand extends Command
{
    protected $signature = 'subscribe:log
        {--user= : 只看某个用户（ID 或邮箱）}
        {--limit=30 : 显示条数}
        {--blocked : 只看被地区规则挡下的}
        {--prune= : 改为清理：删除超过 N 天没再出现的记录}';

    protected $description = '查看订阅拉取来源记录';

    public function handle(SubscribeLogService $service): int
    {
        $prune = $this->option('prune');
        if ($prune !== null) {
            $days = (int) $prune;
            if ($days < 1) {
                $this->error('--prune 要是一个正整数（天）');
                return self::FAILURE;
            }
            $this->info("已删除 {$service->prune($days)} 条超过 {$days} 天未出现的记录。");
            return self::SUCCESS;
        }

        $query = SubscribeLog::query()->with('user:id,email');

        if ($user = $this->option('user')) {
            $query->when(
                is_numeric($user),
                fn($q) => $q->where('user_id', (int) $user),
                fn($q) => $q->whereIn('user_id', \App\Models\User::where('email', $user)->pluck('id'))
            );
        }

        if ($this->option('blocked')) {
            $query->where('blocked_count', '>', 0);
        }

        $rows = $query->orderByDesc('last_seen_at')->limit((int) $this->option('limit'))->get();

        if ($rows->isEmpty()) {
            $this->line('没有记录。');
            return self::SUCCESS;
        }

        $this->table(
            ['用户', 'IP', '国家', '来源', '拉取', '被挡', '最后一次', 'User-Agent'],
            $rows->map(fn(SubscribeLog $r) => [
                $r->user->email ?? $r->user_id,
                $r->ip,
                $r->country ?? '-',
                $r->country_source ?? '-',
                $r->request_count,
                $r->blocked_count ?: '-',
                date('m-d H:i', $r->last_seen_at),
                mb_substr((string) $r->user_agent, 0, 28),
            ])->all()
        );

        // 同一账号出现多个国家是订阅被分享的信号，直接点出来
        $suspicious = $rows->whereNotNull('country')
            ->groupBy('user_id')
            ->filter(fn($g) => $g->pluck('country')->unique()->count() > 1);

        foreach ($suspicious as $userId => $group) {
            $this->warn(sprintf(
                '用户 %s 的订阅被 %d 个国家拉取过：%s',
                $group->first()->user->email ?? $userId,
                $group->pluck('country')->unique()->count(),
                $group->pluck('country')->unique()->implode(', ')
            ));
        }

        return self::SUCCESS;
    }
}
