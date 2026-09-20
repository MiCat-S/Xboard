<?php

namespace App\Http\Middleware;

use App\Models\AdminAuditLog;
use Closure;

class RequestLog
{
    /**
     * 命中即整体打码的键名片段（不区分大小写、按子串匹配）。
     * 支付网关密钥都藏在 config[...] 这类嵌套结构里，所以必须递归处理，
     * 否则 private_key / app_secret / webhook_key 会明文落到审计表。
     */
    private const SENSITIVE_KEY_FRAGMENTS = [
        'password', 'secret', 'token', '_key', 'credential', 'auth_data', 'authorization',
    ];

    /**
     * 完全等于这些键名时也打码。
     * 注意不要把 'code' 整体列进来——插件/优惠券的 code 正是审计日志要记录的操作对象。
     */
    private const SENSITIVE_KEYS = ['key', 'apikey', 'email_code', 'session_id'];

    private const REDACTED = '******';

    public function handle($request, Closure $next)
    {
        if ($request->method() !== 'POST') {
            return $next($request);
        }

        $response = $next($request);

        try {
            $admin = $request->user();
            if (!$admin || !$admin->is_admin) {
                return $response;
            }

            $action = $this->resolveAction($request->path());
            $data = self::redact($request->all());

            AdminAuditLog::insert([
                'admin_id' => $admin->id,
                'action' => $action,
                'method' => $request->method(),
                'uri' => $request->getRequestUri(),
                'request_data' => json_encode($data, JSON_UNESCAPED_UNICODE),
                'ip' => $request->getClientIp(),
                'created_at' => time(),
                'updated_at' => time(),
            ]);
        } catch (\Throwable $e) {
            \Log::warning('Audit log write failed: ' . $e->getMessage());
        }

        return $response;
    }

    /**
     * 递归打码请求体中的敏感字段
     */
    private static function redact(array $data): array
    {
        foreach ($data as $key => $value) {
            if (is_string($key) && self::isSensitiveKey($key)) {
                $data[$key] = self::REDACTED;
                continue;
            }

            if (is_array($value)) {
                $data[$key] = self::redact($value);
            }
        }

        return $data;
    }

    private static function isSensitiveKey(string $key): bool
    {
        $key = strtolower($key);

        if (in_array($key, self::SENSITIVE_KEYS, true)) {
            return true;
        }

        foreach (self::SENSITIVE_KEY_FRAGMENTS as $fragment) {
            if (str_contains($key, $fragment)) {
                return true;
            }
        }

        return false;
    }

    private function resolveAction(string $path): string
    {
        // api/v2/{secure_path}/user/update → user.update
        $path = preg_replace('#^api/v[12]/[^/]+/#', '', $path);
        // gift-card/create-template → gift_card.create_template
        $path = str_replace('-', '_', $path);
        // user/update → user.update, server/manage/sort → server_manage.sort
        $segments = explode('/', $path);
        $method = array_pop($segments);
        $resource = implode('_', $segments);

        return $resource . '.' . $method;
    }
}

