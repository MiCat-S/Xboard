<?php

namespace Plugin\Epusdt;

use App\Contracts\PaymentInterface;
use App\Exceptions\ApiException;
use App\Services\Plugin\AbstractPlugin;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Epusdt / GM Pay 自建加密货币收款网关。
 *
 * 走 GMPay 接口（HMAC-SHA256），不用 EPay 兼容层——后者是 MD5，而且旧版
 * /api/v1/order/create-transaction 路由已经下线。
 *
 * 文档：https://github.com/GMWalletApp/epusdt/blob/master/wiki/API.md
 */
class Plugin extends AbstractPlugin implements PaymentInterface
{
    private const CREATE_PATH = '/payments/gmpay/v1/order/create-transaction';

    /** 回调里只有支付成功才会推送，status 固定为 2 */
    private const STATUS_PAID = 2;

    public function boot(): void
    {
        $this->filter('available_payment_methods', function ($methods) {
            if ($this->getConfig('enabled', true)) {
                $methods['Epusdt'] = [
                    'name' => $this->getConfig('display_name', 'USDT'),
                    'icon' => $this->getConfig('icon', '₮'),
                    'plugin_code' => $this->getPluginCode(),
                    'type' => 'plugin',
                ];
            }
            return $methods;
        });
    }

    public function form(): array
    {
        return [
            'epusdt_url' => [
                'label' => '网关地址',
                'type' => 'string',
                'required' => true,
                'description' => 'Epusdt / GM Pay 的部署地址，含协议，例如 https://pay.example.com',
            ],
            'epusdt_pid' => [
                'label' => '商户 PID',
                'type' => 'string',
                'required' => true,
                'description' => '默认安装会创建 PID 为 1000 的密钥',
            ],
            'epusdt_secret_key' => [
                'label' => '密钥 (secret_key)',
                'type' => 'string',
                'required' => true,
                'description' => 'PID 对应的 secret_key，用于 HMAC-SHA256 签名',
            ],
            'epusdt_currency' => [
                'label' => '法币币种',
                'type' => 'string',
                'description' => '默认 cny，可填 usd 等',
            ],
            'epusdt_token' => [
                'label' => '收款币种',
                'type' => 'string',
                'description' => '如 usdt、trx、usdc。与「收款网络」必须同填或同空；同空时由收银台让用户自己选',
            ],
            'epusdt_network' => [
                'label' => '收款网络',
                'type' => 'string',
                'description' => '如 tron、solana、ethereum、bsc、polygon',
            ],
        ];
    }

    public function pay($order): array
    {
        $params = [
            'pid' => (string) $this->getConfig('epusdt_pid'),
            'order_id' => (string) $order['trade_no'],
            // 网关要的是法币金额，Xboard 内部一律用分
            'amount' => $this->formatAmount((int) $order['total_amount']),
            'currency' => $this->fiatCurrency(),
            'notify_url' => (string) $order['notify_url'],
            'redirect_url' => (string) $order['return_url'],
            'name' => (string) admin_setting('app_name', 'XBoard'),
        ];

        // token 与 network 必须同填或同空，只给一个会被判为参数错误
        $token = $this->trimmed('epusdt_token');
        $network = $this->trimmed('epusdt_network');
        if ($token !== '' && $network !== '') {
            $params['token'] = $token;
            $params['network'] = $network;
        }

        $params['signature'] = $this->sign($params);

        try {
            // 用 form 而不是 JSON：文档说明 JSON 数字会被服务端重新解析
            // （100.00 会变成 100），表单能保证签名字符串与实际传值逐字节一致
            $response = Http::asForm()
                ->timeout(20)
                ->post($this->gatewayUrl() . self::CREATE_PATH, $params);
        } catch (\Throwable $e) {
            Log::error('Epusdt create transaction failed', ['error' => $e->getMessage()]);
            throw new ApiException('支付网关无法访问，请稍后再试');
        }

        $body = $response->json();

        if (!is_array($body) || ($body['status_code'] ?? null) !== 200) {
            Log::error('Epusdt create transaction rejected', [
                'http' => $response->status(),
                'body' => $body,
            ]);
            throw new ApiException('创建支付订单失败：' . ($body['message'] ?? 'unknown error'));
        }

        $paymentUrl = $body['data']['payment_url'] ?? null;
        if (!is_string($paymentUrl) || $paymentUrl === '') {
            throw new ApiException('支付网关未返回收银台地址');
        }

        return [
            'type' => 1,
            'data' => $paymentUrl,
        ];
    }

    public function notify($params): array|bool
    {
        // 回调是 POST JSON，$params 来自 $request->input()
        if (!is_array($params) || empty($params)) {
            return false;
        }

        $signature = (string) ($params['signature'] ?? '');
        if ($signature === '' || !hash_equals($this->sign($params), $signature)) {
            throw new ApiException('signature error', 400);
        }

        // 签名用的就是本地 secret_key，PID 不符说明配置串了
        if ((string) ($params['pid'] ?? '') !== (string) $this->getConfig('epusdt_pid')) {
            throw new ApiException('pid mismatch', 400);
        }

        // 目前只有支付成功会回调，但别指望对端永远不变
        if ((int) ($params['status'] ?? 0) !== self::STATUS_PAID) {
            return ['ignore' => true, 'custom_result' => 'success'];
        }

        $outTradeNo = (string) ($params['order_id'] ?? '');
        if ($outTradeNo === '') {
            throw new ApiException('missing order_id', 400);
        }

        // amount 是下单时提交的法币金额，与订单应付金额同一口径
        $amount = $params['amount'] ?? null;

        return [
            'trade_no' => $outTradeNo,
            // 链上哈希，便于对账
            'callback_no' => (string) ($params['block_transaction_id'] ?? $params['trade_id'] ?? ''),
            'paid_amount' => $amount === null ? null : (int) round(((float) $amount) * 100),
            // 网关要求响应体是 ok / success，否则会按指数退避重推
            'custom_result' => 'success',
        ];
    }

    /**
     * GMPay 签名：排除 signature，非空参数按键名 ASCII 升序拼成 k=v&k=v，
     * 用 secret_key 做 HMAC-SHA256，输出小写十六进制。
     */
    private function sign(array $params): string
    {
        unset($params['signature']);
        ksort($params, SORT_STRING);

        $pairs = [];
        foreach ($params as $key => $value) {
            if ($value === '' || $value === null || is_array($value)) {
                continue;
            }
            $pairs[] = $key . '=' . $this->stringify($value);
        }

        return hash_hmac('sha256', implode('&', $pairs), (string) $this->getConfig('epusdt_secret_key'));
    }

    /**
     * 回调里的数字是 JSON 解析后的 int/float，要还原成服务端签名时用的字面量。
     * PHP 的 float 转字符串会自然丢掉尾随的 .0，与文档描述的行为一致。
     */
    private function stringify(mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        return (string) $value;
    }

    private function formatAmount(int $cents): string
    {
        return number_format($cents / 100, 2, '.', '');
    }

    private function fiatCurrency(): string
    {
        $currency = $this->trimmed('epusdt_currency');

        return $currency === '' ? 'cny' : strtolower($currency);
    }

    private function gatewayUrl(): string
    {
        return rtrim((string) $this->getConfig('epusdt_url'), '/');
    }

    private function trimmed(string $key): string
    {
        return trim((string) $this->getConfig($key, ''));
    }
}
