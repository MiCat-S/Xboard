<?php

namespace Tests\Feature\Payment;

use App\Exceptions\ApiException;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Epusdt / GM Pay 支付插件。
 *
 * 重点是签名与回调判定：签错了会收不到钱，判松了会被人白嫖订单。
 * 文档：https://github.com/GMWalletApp/epusdt/blob/master/wiki/API.md
 */
class EpusdtPluginTest extends TestCase
{
    private const SECRET = 'epusdt_secret_key';
    private const PID = '1000';

    private object $plugin;

    protected function setUp(): void
    {
        parent::setUp();

        // 插件不走 composer 自动加载，PluginManager 是 require_once 进来的
        require_once base_path('plugins-core/Epusdt/Plugin.php');

        $this->plugin = new \Plugin\Epusdt\Plugin('epusdt');
        $this->plugin->setConfig([
            'enabled' => true,
            'epusdt_url' => 'https://pay.example.com/',
            'epusdt_pid' => self::PID,
            'epusdt_secret_key' => self::SECRET,
            'epusdt_currency' => 'cny',
            'epusdt_token' => 'usdt',
            'epusdt_network' => 'tron',
        ]);
    }

    /** 按文档规则算签名：排除 signature，非空参数 ASCII 升序，HMAC-SHA256 */
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

    private function paidCallback(array $overrides = []): array
    {
        $payload = array_merge([
            'pid' => self::PID,
            'trade_id' => '20260523171652123456001',
            'order_id' => '2026092020092804738668778',
            'amount' => 17.75,
            'actual_amount' => 2.54,
            'receive_address' => 'TTestTronAddress001',
            'token' => 'USDT',
            'block_transaction_id' => '0xabc123',
            'status' => 2,
        ], $overrides);

        $payload['signature'] = $this->sign($payload);

        return $payload;
    }

    /**
     * 文档给出的示例向量。这条用例锁住的是「我们的签名和网关的签名一致」，
     * 一旦有人改动拼接规则就会在这里断掉。
     */
    public function test_signature_matches_the_documented_vector(): void
    {
        $params = [
            'pid' => '1000',
            'order_id' => 'ORD202605230001',
            'currency' => 'cny',
            'token' => 'usdt',
            'network' => 'tron',
            'amount' => 100,
            'notify_url' => 'https://merchant.example/notify',
            'redirect_url' => 'https://merchant.example/return',
            'name' => 'VIP',
        ];

        $this->assertSame(
            '6f874b1919d95081835e2809b620e354a5866f5a6dbb2e432d1627f1eb10059d',
            $this->sign($params),
        );
    }

    public function test_notify_accepts_a_correctly_signed_paid_callback(): void
    {
        $result = $this->plugin->notify($this->paidCallback());

        $this->assertSame('2026092020092804738668778', $result['trade_no']);
        $this->assertSame('0xabc123', $result['callback_no']);
        // 金额要换算成分，供 PaymentController 与订单应付金额比对
        $this->assertSame(1775, $result['paid_amount']);
        // 网关要求响应体是 ok/success，否则会一直重推
        $this->assertSame('success', $result['custom_result']);
    }

    public function test_notify_rejects_a_tampered_signature(): void
    {
        $payload = $this->paidCallback();
        $payload['signature'] = str_repeat('0', 64);

        $this->expectException(ApiException::class);
        $this->plugin->notify($payload);
    }

    /** 改了金额但没重算签名——最典型的篡改 */
    public function test_notify_rejects_a_tampered_amount(): void
    {
        $payload = $this->paidCallback();
        $payload['amount'] = 0.01;

        $this->expectException(ApiException::class);
        $this->plugin->notify($payload);
    }

    public function test_notify_rejects_a_missing_signature(): void
    {
        $payload = $this->paidCallback();
        unset($payload['signature']);

        $this->expectException(ApiException::class);
        $this->plugin->notify($payload);
    }

    public function test_notify_rejects_a_foreign_pid(): void
    {
        // 签名自洽但 PID 不是本站的，说明配置串了，不能认
        $payload = $this->paidCallback(['pid' => '2000']);

        $this->expectException(ApiException::class);
        $this->plugin->notify($payload);
    }

    /** 目前网关只在成功时回调，但不能假设对端永远不变 */
    public function test_notify_ignores_a_non_paid_status(): void
    {
        $result = $this->plugin->notify($this->paidCallback(['status' => 1]));

        $this->assertTrue($result['ignore']);
        $this->assertArrayNotHasKey('trade_no', $result);
    }

    public function test_notify_rejects_an_empty_payload(): void
    {
        $this->assertFalse($this->plugin->notify([]));
    }

    public function test_pay_returns_the_checkout_url(): void
    {
        Http::fake([
            '*/payments/gmpay/v1/order/create-transaction' => Http::response([
                'status_code' => 200,
                'message' => 'success',
                'data' => [
                    'trade_id' => '20260523171652123456001',
                    'payment_url' => 'https://pay.example.com/cashier/20260523171652123456001',
                ],
            ]),
        ]);

        $result = $this->plugin->pay([
            'trade_no' => '2026092020092804738668778',
            'total_amount' => 1775,
            'notify_url' => 'https://panel.example.com/api/v1/guest/payment/notify/Epusdt/abc',
            'return_url' => 'https://panel.example.com/#/order/2026092020092804738668778',
            'user_id' => 1,
            'stripe_token' => null,
        ]);

        $this->assertSame(1, $result['type']);
        $this->assertSame('https://pay.example.com/cashier/20260523171652123456001', $result['data']);

        Http::assertSent(function ($request) {
            $body = [];
            parse_str($request->body(), $body);

            // 金额换成法币并保留两位；签名必须覆盖实际发出的每个非空字段
            $this->assertSame('17.75', $body['amount']);
            $this->assertSame('cny', $body['currency']);
            $this->assertSame('usdt', $body['token']);
            $this->assertSame('tron', $body['network']);
            $this->assertSame($this->sign($body), $body['signature']);

            return true;
        });
    }

    /** token 与 network 必须同填或同空，只给一个网关会判参数错误 */
    public function test_pay_omits_token_and_network_when_only_one_is_configured(): void
    {
        $this->plugin->setConfig([
            'epusdt_url' => 'https://pay.example.com',
            'epusdt_pid' => self::PID,
            'epusdt_secret_key' => self::SECRET,
            'epusdt_token' => 'usdt',
            'epusdt_network' => '',
        ]);

        Http::fake([
            '*' => Http::response([
                'status_code' => 200,
                'data' => ['payment_url' => 'https://pay.example.com/cashier/1'],
            ]),
        ]);

        $this->plugin->pay([
            'trade_no' => 'T1',
            'total_amount' => 1000,
            'notify_url' => 'https://panel.example.com/notify',
            'return_url' => 'https://panel.example.com/return',
            'user_id' => 1,
            'stripe_token' => null,
        ]);

        Http::assertSent(function ($request) {
            $body = [];
            parse_str($request->body(), $body);

            $this->assertArrayNotHasKey('token', $body);
            $this->assertArrayNotHasKey('network', $body);

            return true;
        });
    }

    public function test_pay_surfaces_a_gateway_error(): void
    {
        Http::fake([
            '*' => Http::response(['status_code' => 10003, 'message' => '无可用钱包地址'], 400),
        ]);

        $this->expectException(ApiException::class);

        $this->plugin->pay([
            'trade_no' => 'T1',
            'total_amount' => 1000,
            'notify_url' => 'https://panel.example.com/notify',
            'return_url' => 'https://panel.example.com/return',
            'user_id' => 1,
            'stripe_token' => null,
        ]);
    }
}
