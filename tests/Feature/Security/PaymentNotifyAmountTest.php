<?php

namespace Tests\Feature\Security;

use App\Http\Controllers\V1\Guest\PaymentController;
use App\Models\Order;
use App\Models\Plan;
use App\Models\User;
use App\Utils\Helper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use ReflectionMethod;
use Tests\TestCase;

/**
 * 支付回调必须比对金额：网关回调里报的实付金额低于订单应付金额时不得开通订单。
 */
class PaymentNotifyAmountTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private Plan $plan;

    protected function setUp(): void
    {
        parent::setUp();

        $this->plan = Plan::create([
            'name' => 'test',
            'group_id' => 1,
            'transfer_enable' => 100,
            'prices' => ['month_price' => 100],
        ]);

        $this->user = new User();
        $this->user->email = 'payer@example.com';
        $this->user->password = bcrypt('secret123');
        $this->user->uuid = Helper::guid(true);
        $this->user->token = Helper::guid();
        $this->user->save();
    }

    private function makePendingOrder(): Order
    {
        // 应付 = total_amount + handling_amount = 10000 + 200 = 10200
        return Order::create([
            'user_id' => $this->user->id,
            'plan_id' => $this->plan->id,
            'period' => 'month_price',
            'trade_no' => Helper::generateOrderNo(),
            'total_amount' => 10000,
            'handling_amount' => 200,
            'type' => Order::TYPE_NEW_PURCHASE,
            'status' => Order::STATUS_PENDING,
        ]);
    }

    private function handle(Order $order, ?int $paidAmount): bool
    {
        $method = new ReflectionMethod(PaymentController::class, 'handle');
        $method->setAccessible(true);

        return (bool) $method->invoke(new PaymentController(), $order->trade_no, 'cb-' . uniqid(), $paidAmount);
    }

    public function test_underpaid_callback_does_not_complete_the_order(): void
    {
        $order = $this->makePendingOrder();

        $this->assertFalse($this->handle($order, 1));
        $this->assertSame(Order::STATUS_PENDING, (int) $order->refresh()->status);
    }

    public function test_exact_amount_completes_the_order(): void
    {
        $order = $this->makePendingOrder();

        $this->assertTrue($this->handle($order, 10200));
        $this->assertNotSame(Order::STATUS_PENDING, (int) $order->refresh()->status);
    }

    public function test_overpaid_callback_completes_the_order(): void
    {
        $order = $this->makePendingOrder();

        $this->assertTrue($this->handle($order, 20000));
        $this->assertNotSame(Order::STATUS_PENDING, (int) $order->refresh()->status);
    }

    public function test_one_cent_rounding_difference_is_tolerated(): void
    {
        $order = $this->makePendingOrder();

        $this->assertTrue($this->handle($order, 10199));
        $this->assertNotSame(Order::STATUS_PENDING, (int) $order->refresh()->status);
    }

    /**
     * 第三方支付插件可能无法提供金额，此时只能跳过校验（并记警告），
     * 不能因为拿不到金额就把正常支付拒之门外。
     */
    public function test_missing_amount_falls_back_to_previous_behaviour(): void
    {
        $order = $this->makePendingOrder();

        $this->assertTrue($this->handle($order, null));
        $this->assertNotSame(Order::STATUS_PENDING, (int) $order->refresh()->status);
    }
}
