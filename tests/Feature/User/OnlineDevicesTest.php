<?php

namespace Tests\Feature\User;

use App\Models\Server;
use App\Models\User;
use App\Services\DeviceStateService;
use App\Utils\Helper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 节点上报的连接来源 IP 对用户可见——用户看到陌生 IP 即可察觉订阅链接被盗用。
 *
 * 设备状态本身存在 Redis 里（见 DeviceStateServiceTest），这里把 DeviceStateService
 * 换成桩对象，专注验证接口自身的逻辑：归并、节点名、当前 IP 标记、归属隔离。
 */
class OnlineDevicesTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private Server $node;
    private Server $otherNode;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = $this->makeUser('owner@example.com');
        $this->node = $this->makeNode('香港 01');
        $this->otherNode = $this->makeNode('日本 02');
    }

    private function makeUser(string $email): User
    {
        $user = new User();
        $user->email = $email;
        $user->password = bcrypt('secret123');
        $user->uuid = Helper::guid(true);
        $user->token = Helper::guid();
        $user->device_limit = 3;
        $user->save();

        return $user;
    }

    private function makeNode(string $name): Server
    {
        return Server::create([
            'name' => $name,
            'type' => Server::TYPE_VMESS,
            'host' => '127.0.0.1',
            'port' => 443,
            'server_port' => 443,
            'rate' => '1',
            'group_id' => [1],
            'enabled' => true,
        ]);
    }

    /**
     * @param array<int, array{node_id:int, ip:string, last_seen_at:int}> $devices
     */
    private function fakeDevicesFor(int $userId, array $devices): void
    {
        $this->app->instance(DeviceStateService::class, new class($userId, $devices) extends DeviceStateService {
            public function __construct(private int $ownerId, private array $devices)
            {
            }

            public function getUserDevices(int $userId): array
            {
                // 桩对象同样只认自己那份数据，避免测试掩盖越权问题
                return $userId === $this->ownerId ? $this->devices : [];
            }
        });
    }

    public function test_returns_the_ips_reported_by_nodes(): void
    {
        $this->fakeDevicesFor($this->user->id, [
            ['node_id' => $this->node->id, 'ip' => '203.0.113.7', 'last_seen_at' => 1700000100],
            ['node_id' => $this->node->id, 'ip' => '198.51.100.9', 'last_seen_at' => 1700000200],
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/v1/user/getOnlineDevices')
            ->assertStatus(200);

        $this->assertSame(2, $response->json('data.online_count'));
        $this->assertSame(3, $response->json('data.device_limit'));

        // 按最后上报时间倒序
        $this->assertSame('198.51.100.9', $response->json('data.devices.0.ip'));
        $this->assertSame('203.0.113.7', $response->json('data.devices.1.ip'));
    }

    public function test_same_ip_on_multiple_nodes_is_merged_into_one_device(): void
    {
        $this->fakeDevicesFor($this->user->id, [
            ['node_id' => $this->node->id, 'ip' => '203.0.113.7', 'last_seen_at' => 1700000100],
            ['node_id' => $this->otherNode->id, 'ip' => '203.0.113.7', 'last_seen_at' => 1700000300],
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/v1/user/getOnlineDevices')
            ->assertStatus(200);

        // 设备数按去重 IP 计，与 device_limit 的口径一致
        $this->assertSame(1, $response->json('data.online_count'));
        $this->assertSame(['香港 01', '日本 02'], $response->json('data.devices.0.nodes'));
        $this->assertSame(1700000300, $response->json('data.devices.0.last_seen_at'));
    }

    public function test_marks_the_ip_the_request_came_from(): void
    {
        $this->fakeDevicesFor($this->user->id, [
            ['node_id' => $this->node->id, 'ip' => '203.0.113.7', 'last_seen_at' => 1700000100],
            ['node_id' => $this->node->id, 'ip' => '198.51.100.9', 'last_seen_at' => 1700000200],
        ]);

        $devices = collect(
            $this->actingAs($this->user, 'sanctum')
                ->withServerVariables(['REMOTE_ADDR' => '203.0.113.7'])
                ->getJson('/api/v1/user/getOnlineDevices')
                ->json('data.devices')
        )->keyBy('ip');

        $this->assertTrue($devices['203.0.113.7']['is_current_ip']);
        $this->assertFalse($devices['198.51.100.9']['is_current_ip']);
    }

    public function test_resolves_the_region_of_public_ipv4_addresses(): void
    {
        $this->fakeDevicesFor($this->user->id, [
            ['node_id' => $this->node->id, 'ip' => '114.114.114.114', 'last_seen_at' => 1700000100],
        ]);

        $region = $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/v1/user/getOnlineDevices')
            ->json('data.devices.0.region');

        $this->assertIsString($region);
        $this->assertStringContainsString('中国', $region);
    }

    public function test_ipv6_region_is_null_rather_than_an_error(): void
    {
        $this->fakeDevicesFor($this->user->id, [
            ['node_id' => $this->node->id, 'ip' => '2001:4860:4860::8888', 'last_seen_at' => 1700000100],
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/v1/user/getOnlineDevices')
            ->assertStatus(200);

        $this->assertSame('2001:4860:4860::8888', $response->json('data.devices.0.ip'));
        $this->assertNull($response->json('data.devices.0.region'));
    }

    public function test_never_exposes_another_users_devices(): void
    {
        $other = $this->makeUser('other@example.com');

        // 数据属于 other，当前登录的是 user
        $this->fakeDevicesFor($other->id, [
            ['node_id' => $this->node->id, 'ip' => '192.0.2.55', 'last_seen_at' => 1700000100],
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/v1/user/getOnlineDevices')
            ->assertStatus(200);

        $this->assertSame([], $response->json('data.devices'));
    }

    public function test_requires_authentication(): void
    {
        $this->getJson('/api/v1/user/getOnlineDevices')->assertStatus(403);
    }

    public function test_returns_an_empty_list_when_nothing_is_connected(): void
    {
        $this->fakeDevicesFor($this->user->id, []);

        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/v1/user/getOnlineDevices')
            ->assertStatus(200);

        $this->assertSame([], $response->json('data.devices'));
        $this->assertSame(0, $response->json('data.online_count'));
    }
}
