<?php

namespace App\Http\Middleware;

use App\Exceptions\ApiException;
use App\Services\GeoIpService;
use App\Services\Plugin\HookManager;
use App\Services\SubscribeLogService;
use Closure;
use App\Models\User;
use Illuminate\Support\Facades\Auth;

class Client
{
    /**
     * Handle an incoming request.
     *
     * @param \Illuminate\Http\Request $request
     * @param \Closure $next
     * @return mixed
     */
    public function handle($request, Closure $next)
    {
        $token = $request->input('token', $request->route('token'));
        if (empty($token)) {
            throw new ApiException('token is null',403);
        }
        $user = User::where('token', $token)->first();
        if (!$user) {
            throw new ApiException('token is error',403);
        }

        Auth::setUser($user);

        // 这个中间件同时管着 app/getConfig、app/getVersion，那些不是订阅拉取，
        // 记进来会把来源统计搞脏，所以按路由名收窄。
        if ($this->isSubscribeRequest($request)) {
            $response = $this->guardAndLog($request, $user);
            if ($response !== null) {
                return $response;
            }
        }

        return $next($request);
    }

    private function isSubscribeRequest($request): bool
    {
        $name = $request->route()?->getName();

        return is_string($name) && str_starts_with($name, 'client.subscribe');
    }

    /**
     * 记录这次拉取；被地区规则挡下时返回 403，否则返回 null 表示放行。
     */
    private function guardAndLog($request, User $user)
    {
        // TrustProxies 已经配了 Cloudflare 全段，所以这里拿到的是真实客户端 IP
        $ip = $request->ip();
        $geo = app(GeoIpService::class)->resolve($ip, $request);

        // 地区规则由「订阅地区限制」插件挂在这个钩子上。插件没启用时没人处理，
        // 拿到的就是默认值 true，也就是不限制。
        $allowed = HookManager::filter('client.subscribe.geo_allowed', true, $geo['country'], $request) !== false;

        app(SubscribeLogService::class)->record(
            $user->id,
            $ip,
            $geo['country'],
            $geo['source'],
            $request->userAgent(),
            !$allowed
        );

        if ($allowed) {
            return null;
        }

        // 和订阅不可用时的响应保持一致：空 body + 403，客户端只会显示拉取失败
        return response('', 403, ['Content-Type' => 'text/plain']);
    }
}
