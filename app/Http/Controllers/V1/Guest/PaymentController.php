<?php

namespace App\Http\Controllers\V1\Guest;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Services\OrderService;
use App\Services\PaymentService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use App\Services\Plugin\HookManager;

class PaymentController extends Controller
{
    public function notify($method, $uuid, Request $request)
    {
        HookManager::call('payment.notify.before', [$method, $uuid, $request]);
        try {
            $paymentService = new PaymentService($method, null, $uuid);
            $verify = $paymentService->notify($request->input());
            if (!$verify) {
                HookManager::call('payment.notify.failed', [$method, $uuid, $request]);
                return $this->fail([422, 'verify error']);
            }
            HookManager::call('payment.notify.verified', $verify);

            // 网关要求原样回显的纯文本响应（例如 CoinPayments 的 pending IPN）
            if (is_string($verify)) {
                return $verify;
            }

            // 签名有效但并非“支付完成”事件（例如 charge:created、InvoiceExpired），
            // 需要向网关返回成功以停止重推，但不能开通订单。
            if (!empty($verify['ignore'])) {
                return $verify['custom_result'] ?? 'success';
            }

            if (!$this->handle($verify['trade_no'], $verify['callback_no'], $verify['paid_amount'] ?? null)) {
                return $this->fail([400, 'handle error']);
            }
            return (isset($verify['custom_result']) ? $verify['custom_result'] : 'success');
        } catch (ApiException $e) {
            // 插件主动抛的异常自带状态码和可安全外泄的文案（签名不符是 400，
            // 不是 500）。兜底的 catch 会把它压成 500，丢掉这个区分。
            Log::warning('payment notify rejected', [
                'method' => $method,
                'reason' => $e->getMessage(),
            ]);
            HookManager::call('payment.notify.failed', [$method, $uuid, $request]);
            throw $e;
        } catch (\Exception $e) {
            Log::error($e);
            return $this->fail([500, 'fail']);
        }
    }

    private function handle($tradeNo, $callbackNo, ?int $paidAmount = null)
    {
        $order = Order::where('trade_no', $tradeNo)->first();
        if (!$order) {
            // 这里原本 return $this->fail(...)，而 fail() 返回的 JsonResponse 是
            // truthy，调用方的 if (!$this->handle(...)) 判不出失败，网关反而会
            // 收到 success。订单号对不上必须如实报失败。
            Log::warning('payment notify for an unknown order', ['trade_no' => $tradeNo]);
            return false;
        }
        if ($order->status !== Order::STATUS_PENDING)
            return true;
        if (!$this->verifyPaidAmount($order, $paidAmount)) {
            return false;
        }
        $orderService = new OrderService($order);
        if (!$orderService->paid($callbackNo)) {
            return false;
        }

        HookManager::call('payment.notify.success', $order);
        return true;
    }

    /**
     * 校验网关回调金额与订单应付金额是否一致。
     *
     * $paidAmount 为网关实际收款金额（单位：分）。支付插件无法提供该字段时返回 null，
     * 此时只能跳过校验并记录告警——新插件都应当回传该字段。
     */
    private function verifyPaidAmount(Order $order, ?int $paidAmount): bool
    {
        $expected = (int) $order->total_amount + (int) $order->handling_amount;

        if ($paidAmount === null) {
            Log::warning('payment notify without amount, skip amount verification', [
                'trade_no' => $order->trade_no,
                'expected' => $expected,
            ]);
            return true;
        }

        // 容许 1 分的取整误差，多付放行，少付拒绝
        if ($paidAmount + 1 < $expected) {
            Log::warning('payment notify amount mismatch', [
                'trade_no' => $order->trade_no,
                'expected' => $expected,
                'paid' => $paidAmount,
            ]);
            return false;
        }

        return true;
    }
}
