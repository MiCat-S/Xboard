<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 订阅拉取记录。
 *
 * 每个 (user_id, ip) 只有一行，重复拉取累加 request_count —— 客户端会自动轮询
 * 订阅（实测三个用户一天 456 次），逐条存明细没有意义，能回答「这个账号被哪些
 * 地址拉过、各拉了多少次、最后一次什么时候」就够了。
 *
 * @property int $id
 * @property int $user_id
 * @property string $ip
 * @property string|null $country ISO 3166-1 alpha-2，查不出来为 null
 * @property string|null $country_source cf | ip2region
 * @property string|null $user_agent
 * @property int $request_count
 * @property int $blocked_count 被地区规则拦下的次数
 * @property int $first_seen_at
 * @property int $last_seen_at
 * @property-read \App\Models\User|null $user
 */
class SubscribeLog extends Model
{
    protected $table = 'v2_subscribe_log';
    protected $guarded = ['id'];
    public $timestamps = false;

    protected $casts = [
        'user_id' => 'integer',
        'request_count' => 'integer',
        'blocked_count' => 'integer',
        'first_seen_at' => 'integer',
        'last_seen_at' => 'integer',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
