import { beforeEach, describe, expect, it, vi } from 'vitest'
import userEvent from '@testing-library/user-event'
import { Routes, Route } from 'react-router-dom'
import { api, type Order, type PaymentMethod } from '../api'
import { renderPage, screen, waitFor } from '../test/render'
import OrderDetail from './OrderDetail'

const order: Order = {
  trade_no: '2026092020092804738668778',
  plan_id: 1,
  period: 'quarter_price',
  status: 0,
  type: 1,
  total_amount: 1740,
  discount_amount: 560,
  balance_amount: 500,
  handling_amount: null,
  surplus_amount: null,
  created_at: 1789948800,
  plan: { id: 1, name: '入门套餐' } as never,
}

const methods: PaymentMethod[] = [
  { id: 7, name: '在线支付', payment: 'EPay', icon: null, handling_fee_fixed: null, handling_fee_percent: 2 },
]

function renderOrder() {
  return renderPage(
    <Routes>
      <Route path="/order/:tradeNo" element={<OrderDetail />} />
    </Routes>,
    { initialEntries: [`/order/${order.trade_no}`] },
  )
}

describe('OrderDetail', () => {
  beforeEach(() => {
    vi.restoreAllMocks()
    vi.spyOn(api, 'orderDetail').mockResolvedValue(order)
    vi.spyOn(api, 'paymentMethods').mockResolvedValue(methods)
  })

  /**
   * antd 的 Descriptions 不会穿透 Fragment 去收集子项。最初把
   * Descriptions.Item 包在自定义组件里返回，优惠和余额抵扣两行就静默消失了。
   */
  it('lists the discount and balance rows', async () => {
    renderOrder()

    expect(await screen.findByText('优惠')).toBeInTheDocument()
    expect(screen.getByText('-¥5.60')).toBeInTheDocument()
    expect(screen.getByText('余额抵扣')).toBeInTheDocument()
    expect(screen.getByText('-¥5.00')).toBeInTheDocument()
  })

  it('omits amount rows that are zero', async () => {
    vi.spyOn(api, 'orderDetail').mockResolvedValue({ ...order, discount_amount: 0, balance_amount: null })
    renderOrder()

    await screen.findByText('订单详情')
    expect(screen.queryByText('优惠')).not.toBeInTheDocument()
    expect(screen.queryByText('余额抵扣')).not.toBeInTheDocument()
  })

  /**
   * handling_amount 要到 checkout 才会写进订单，未支付时是空的。
   * 页面必须按选中的支付方式现算，否则显示的金额比实际扣款少。
   */
  it('includes the estimated handling fee in the payable total', async () => {
    renderOrder()

    // 1740 + round(1740 * 2%) = 1740 + 35 = 1775
    expect(await screen.findByRole('button', { name: /立即支付\s*¥17\.75/ })).toBeInTheDocument()
  })

  it('uses the stored fee once the order already has one', async () => {
    vi.spyOn(api, 'orderDetail').mockResolvedValue({ ...order, handling_amount: 99 })
    renderOrder()

    expect(await screen.findByRole('button', { name: /立即支付\s*¥18\.39/ })).toBeInTheDocument()
  })

  it('redirects when checkout returns a payment url', async () => {
    const checkout = vi.spyOn(api, 'checkout').mockResolvedValue({ type: 1, data: 'https://pay.example.com/x' })
    vi.spyOn(api, 'orderStatus').mockResolvedValue(0)

    // jsdom 不允许真的导航，拦下 href 赋值来断言
    const assign = vi.fn()
    Object.defineProperty(window, 'location', {
      configurable: true,
      value: { set href(value: string) { assign(value) } },
    })

    renderOrder()
    await userEvent.click(await screen.findByRole('button', { name: /立即支付/ }))

    await waitFor(() => expect(checkout).toHaveBeenCalledWith(order.trade_no, 7))
    await waitFor(() => expect(assign).toHaveBeenCalledWith('https://pay.example.com/x'))
  })

  it('renders a QR code when checkout returns one', async () => {
    vi.spyOn(api, 'checkout').mockResolvedValue({ type: 0, data: 'weixin://wxpay/bizpayurl?pr=abc' })
    vi.spyOn(api, 'orderStatus').mockResolvedValue(0)

    const { container } = renderOrder()
    await userEvent.click(await screen.findByRole('button', { name: /立即支付/ }))

    expect(await screen.findByText('请使用对应 App 扫码支付')).toBeInTheDocument()
    expect(container.querySelector('canvas, svg')).toBeTruthy()
  })

  it('goes straight to success when the balance covers the order', async () => {
    vi.spyOn(api, 'checkout').mockResolvedValue({ type: -1, data: true })

    renderOrder()
    await userEvent.click(await screen.findByRole('button', { name: /立即支付/ }))

    expect(await screen.findByText('支付成功，套餐已开通')).toBeInTheDocument()
  })

  it('refuses to offer payment for an order that is not pending', async () => {
    vi.spyOn(api, 'orderDetail').mockResolvedValue({ ...order, status: 3 })
    renderOrder()

    expect(await screen.findByText('该订单当前状态不可支付')).toBeInTheDocument()
    expect(screen.queryByRole('button', { name: /立即支付/ })).not.toBeInTheDocument()
  })

  it('tells the user when the site has no payment method', async () => {
    vi.spyOn(api, 'paymentMethods').mockResolvedValue([])
    renderOrder()

    expect(await screen.findByText('站点未配置支付方式')).toBeInTheDocument()
  })
})
