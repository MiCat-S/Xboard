<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('v2_subscribe_log', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('user_id');
            // IPv6 最长 45 字符
            $table->string('ip', 45);
            // ISO 3166-1 alpha-2。查不出来时为 null，不要猜
            $table->char('country', 2)->nullable();
            // cf | ip2region —— 用来判断线上到底哪个源在起作用
            $table->string('country_source', 16)->nullable();
            $table->string('user_agent', 255)->nullable();
            // 同一个 (user, ip) 只存一行，来一次加一次
            $table->unsignedInteger('request_count')->default(0);
            // 被地区规则拦下的次数，单独计，便于看是谁在被挡
            $table->unsignedInteger('blocked_count')->default(0);
            $table->unsignedInteger('first_seen_at');
            $table->unsignedInteger('last_seen_at');

            $table->unique(['user_id', 'ip']);
            // 清理旧记录时按这个扫
            $table->index('last_seen_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('v2_subscribe_log');
    }
};
