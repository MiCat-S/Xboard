<?php

namespace Tests\Feature\Payment;

use App\Models\Payment;
use App\Models\Plan;
use App\Models\User;
use App\Services\Plugin\PluginManager;
use App\Utils\Helper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 支付回调端点回给网关的东西。网关是靠 HTTP 状态和响应体决定要不要重推的，
 * 报错报成「成功」比报错本身更糟。
 */
class NotifyResponseTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'epusdt_secret_key';

    private string $uuid;

    protected function setUp(): void
    {
        parent::setUp();

        $manager = app(PluginManager::class);
        $manager->install('epusdt');
        $manager->enable('epusdt');
        $manager->initializeEnabledPlugins();

        $this->uuid = Helper::randomChar(8);

        Payment::create([
            'uuid' => $this->uuid,
            'payment' => 'Epusdt',
            'name' => 'USDT',
            'icon' => '',
            'config' => [
                'epusdt_url' => 'https://pay.example.com',
                'epusdt_pid' => '1000',
                'epusdt_secret_key' => self::SECRET,
            ],
            'enable' => 1,
            'sort' => 1,
        ]);
    }

    private function sign(array $params): string
    {
        unset($params['signature']);
        ksort($params, SORT_STRING);

        $pairs = [];
        foreach ($params as $key => $value) {
            if ($value === '' || $value === null) {
                continue;
            }
            $pairs[] = $key . '=' . $value;
        }

        return hash_hmac('sha256', implode('&', $pairs), self::SECRET);
    }

    private function payload(array $overrides = []): array
    {
        $payload = array_merge([
            'pid' => '1000',
            'trade_id' => '20260523171652123456001',
            'order_id' => 'UNKNOWN-ORDER',
            'amount' => 17.75,
            'actual_amount' => 2.54,
            'token' => 'USDT',
            'block_transaction_id' => '0xabc',
            'status' => 2,
        ], $overrides);

        $payload['signature'] = $this->sign($payload);

        return $payload;
    }

    private function notify(array $payload)
    {
        return $this->postJson("/api/v1/guest/payment/notify/Epusdt/{$this->uuid}", $payload);
    }

    /**
     * 原先这里 return $this->fail(...)，而那是个 truthy 的 JsonResponse，
     * 调用方判不出失败，网关会收到 success —— 一笔查无此单的回调被当成入账。
     */
    public function test_an_unknown_order_is_reported_as_a_failure(): void
    {
        $response = $this->notify($this->payload(['order_id' => 'NOPE']));

        $response->assertStatus(400);
        $this->assertStringNotContainsStringIgnoringCase('success', $response->getContent());
    }

    /**
     * 插件抛的 ApiException 自带状态码，不该被兜底的 catch 压成 500。
     */
    public function test_a_bad_signature_is_a_400_not_a_500(): void
    {
        $payload = $this->payload();
        $payload['signature'] = str_repeat('0', 64);

        $this->notify($payload)->assertStatus(400);
    }

    public function test_a_foreign_pid_is_a_400(): void
    {
        $this->notify($this->payload(['pid' => '2000']))->assertStatus(400);
    }

    public function test_a_legitimate_callback_is_acknowledged(): void
    {
        $plan = Plan::create([
            'name' => 'test', 'group_id' => 1, 'transfer_enable' => 100,
            'prices' => ['monthly' => 17.75],
        ]);

        $user = new User();
        $user->email = 'payer@example.com';
        $user->password = bcrypt('secret123');
        $user->uuid = Helper::guid(true);
        $user->token = Helper::guid();
        $user->save();

        $order = \App\Models\Order::create([
            'user_id' => $user->id,
            'plan_id' => $plan->id,
            'period' => 'month_price',
            'trade_no' => Helper::generateOrderNo(),
            'total_amount' => 1775,
            'type' => \App\Models\Order::TYPE_NEW_PURCHASE,
            'status' => \App\Models\Order::STATUS_PENDING,
        ]);

        $response = $this->notify($this->payload(['order_id' => $order->trade_no]));

        $response->assertStatus(200);
        // 网关要求响应体是 ok / success，否则会按指数退避重推
        $this->assertSame('success', $response->getContent());
        $this->assertNotSame(\App\Models\Order::STATUS_PENDING, (int) $order->refresh()->status);
    }

    /** 非支付完成的事件：确认收到即可，但不能开通订单 */
    public function test_a_non_paid_status_is_acknowledged_without_activating(): void
    {
        $response = $this->notify($this->payload(['status' => 1]));

        $response->assertStatus(200);
        $this->assertSame('success', $response->getContent());
    }
}
