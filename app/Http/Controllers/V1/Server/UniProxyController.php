<?php

namespace App\Http\Controllers\V1\Server;

use App\Http\Controllers\Controller;
use App\Services\DeviceStateService;
use App\Services\ServerService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Http\JsonResponse;

class UniProxyController extends Controller
{
    public function __construct(
        private readonly DeviceStateService $deviceStateService
    ) {
    }

    private function getNodeInfo(Request $request)
    {
        return $request->attributes->get('node_info');
    }

    /** 大节点用户列表需要额外内存，但不能像原来那样直接解除上限：
     *  Octane 常驻进程下 memory_limit=-1 会永久生效，一次异常就能把整台机器 OOM。 */
    private const USER_LIST_MEMORY_LIMIT = '1024M';

    public function user(Request $request)
    {
        $this->raiseMemoryLimit(self::USER_LIST_MEMORY_LIMIT);
        $node = $this->getNodeInfo($request);

        ServerService::touchNode($node);

        $response['users'] = ServerService::getAvailableUsers($node);

        $eTag = sha1(json_encode($response));
        if (str_contains($request->header('If-None-Match', ''), $eTag)) {
            return response(null, 304);
        }

        return response($response)->header('ETag', "\"{$eTag}\"");
    }

    /**
     * 只在当前上限低于目标值时上调，绝不降低运维已配置的更高上限
     */
    private function raiseMemoryLimit(string $target): void
    {
        $current = trim((string) ini_get('memory_limit'));

        if ($current === '' || $current === '-1') {
            return;
        }

        $toBytes = static function (string $value): int {
            $value = trim($value);
            $unit = strtolower(substr($value, -1));
            $number = (int) $value;

            return match ($unit) {
                'g' => $number * 1024 * 1024 * 1024,
                'm' => $number * 1024 * 1024,
                'k' => $number * 1024,
                default => $number,
            };
        };

        if ($toBytes($current) < $toBytes($target)) {
            ini_set('memory_limit', $target);
        }
    }

    public function push(Request $request)
    {
        $res = json_decode(request()->getContent(), true);
        if (!is_array($res)) {
            return $this->fail([422, 'Invalid data format']);
        }

        $node = $this->getNodeInfo($request);

        ServerService::processTraffic($node, $res);

        return $this->success(true);
    }

    public function config(Request $request)
    {
        $node = $this->getNodeInfo($request);
        $response = ServerService::buildNodeConfig($node);

        $response['base_config'] = [
            'push_interval' => (int) admin_setting('server_push_interval', 60),
            'pull_interval' => (int) admin_setting('server_pull_interval', 60)
        ];

        $eTag = sha1(json_encode($response));
        if (str_contains($request->header('If-None-Match', ''), $eTag)) {
            return response(null, 304);
        }
        return response($response)->header('ETag', "\"{$eTag}\"");
    }

    public function alivelist(Request $request): JsonResponse
    {
        $node = $this->getNodeInfo($request);
        $deviceLimitUsers = ServerService::getAvailableUsers($node)
            ->where('device_limit', '>', 0);

        $alive = $this->deviceStateService->getAliveList(collect($deviceLimitUsers));

        return response()->json(['alive' => (object) $alive]);
    }

    public function alive(Request $request): JsonResponse
    {
        $node = $this->getNodeInfo($request);
        $data = json_decode(request()->getContent(), true);
        if ($data === null) {
            return response()->json(['error' => 'Invalid online data'], 400);
        }

        ServerService::processAlive($node->id, $data);

        return response()->json(['data' => true]);
    }

    public function status(Request $request): JsonResponse
    {
        $node = $this->getNodeInfo($request);

        $data = $request->validate([
            'cpu' => 'required|numeric|min:0|max:100',
            'mem.total' => 'required|integer|min:0',
            'mem.used' => 'required|integer|min:0',
            'swap.total' => 'required|integer|min:0',
            'swap.used' => 'required|integer|min:0',
            'disk.total' => 'required|integer|min:0',
            'disk.used' => 'required|integer|min:0',
        ]);

        ServerService::processStatus($node, $data);

        return response()->json(['data' => true, 'code' => 0, 'message' => 'success']);
    }
}
