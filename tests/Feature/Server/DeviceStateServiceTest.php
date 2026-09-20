<?php

namespace Tests\Feature\Server;

use App\Services\DeviceStateService;
use App\Services\ServerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Redis;
use Tests\TestCase;

/**
 * 设备状态存放在 Redis 里，这层只能靠真实 Redis 验证。
 * 本机没有 Redis 时整类跳过（CI 里应当提供）。
 */
class DeviceStateServiceTest extends TestCase
{
    use RefreshDatabase;

    private const USER_ID = 90001;
    private const NODE_A = 8001;
    private const NODE_B = 8002;

    private DeviceStateService $service;

    protected function setUp(): void
    {
        parent::setUp();

        try {
            Redis::connection()->ping();
        } catch (\Throwable $e) {
            $this->markTestSkipped('需要可用的 Redis：设备状态存放在 Redis 中');
        }

        Redis::del('user_devices:' . self::USER_ID);
        $this->service = app(DeviceStateService::class);
    }

    protected function tearDown(): void
    {
        try {
            Redis::del('user_devices:' . self::USER_ID);
        } catch (\Throwable) {
            // Redis 不可用时 setUp 已跳过
        }

        parent::tearDown();
    }

    public function test_strips_ports_and_deduplicates_ips(): void
    {
        $this->service->setDevices(self::USER_ID, self::NODE_A, [
            '203.0.113.7:51820',
            '203.0.113.7:51821',
            '[2001:db8::1]:443',
        ]);

        $ips = collect($this->service->getUserDevices(self::USER_ID))->pluck('ip')->sort()->values()->all();

        $this->assertSame(['2001:db8::1', '203.0.113.7'], $ips);
        $this->assertSame(2, $this->service->getDeviceCount(self::USER_ID));
    }

    public function test_keeps_node_id_and_timestamp(): void
    {
        $this->service->setDevices(self::USER_ID, self::NODE_A, ['203.0.113.7']);

        $devices = $this->service->getUserDevices(self::USER_ID);

        $this->assertCount(1, $devices);
        $this->assertSame(self::NODE_A, $devices[0]['node_id']);
        $this->assertSame('203.0.113.7', $devices[0]['ip']);
        $this->assertGreaterThan(0, $devices[0]['last_seen_at']);
    }

    public function test_tracks_the_same_ip_across_multiple_nodes(): void
    {
        $this->service->setDevices(self::USER_ID, self::NODE_A, ['203.0.113.7']);
        $this->service->setDevices(self::USER_ID, self::NODE_B, ['203.0.113.7']);

        $devices = $this->service->getUserDevices(self::USER_ID);
        $nodeIds = collect($devices)->pluck('node_id')->sort()->values()->all();

        $this->assertSame([self::NODE_A, self::NODE_B], $nodeIds);
        // 设备数按去重 IP 计
        $this->assertSame(1, $this->service->getDeviceCount(self::USER_ID));
    }

    public function test_reporting_again_replaces_the_previous_state_of_that_node(): void
    {
        $this->service->setDevices(self::USER_ID, self::NODE_A, ['203.0.113.7']);
        $this->service->setDevices(self::USER_ID, self::NODE_A, ['198.51.100.9']);

        $ips = collect($this->service->getUserDevices(self::USER_ID))->pluck('ip')->all();

        $this->assertSame(['198.51.100.9'], $ips);
    }

    public function test_process_alive_feeds_the_same_store(): void
    {
        ServerService::processAlive(self::NODE_A, [self::USER_ID => ['203.0.113.7:1234']]);

        $ips = collect($this->service->getUserDevices(self::USER_ID))->pluck('ip')->all();

        $this->assertSame(['203.0.113.7'], $ips);
    }
}
