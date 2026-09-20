import { beforeEach, describe, expect, it, vi } from 'vitest'
import userEvent from '@testing-library/user-event'
import { api, type PlanDetail } from '../api'
import { renderPage, screen, waitFor } from '../test/render'
import Plans from './Plans'

const plan = {
  id: 1,
  name: '入门套餐',
  content: '<p>适合轻度使用</p>',
  tags: null,
  transfer_enable: 100,
  speed_limit: null,
  device_limit: 3,
  capacity_limit: null,
  sell: true,
  renew: true,
  month_price: 1000,
  quarter_price: 2800,
  half_year_price: null,
  year_price: 10000,
  two_year_price: null,
  three_year_price: null,
  onetime_price: null,
  reset_price: null,
} satisfies PlanDetail

describe('Plans', () => {
  beforeEach(() => {
    vi.restoreAllMocks()
    vi.spyOn(api, 'plans').mockResolvedValue([plan])
  })

  it('only offers the periods the plan prices, and shows the first one', async () => {
    renderPage(<Plans />)

    await screen.findByText('入门套餐')

    expect(screen.getByText('月付')).toBeInTheDocument()
    expect(screen.getByText('季付')).toBeInTheDocument()
    expect(screen.getByText('年付')).toBeInTheDocument()
    // 套餐没定半年价，就不该出现这个选项
    expect(screen.queryByText('半年付')).not.toBeInTheDocument()

    expect(screen.getByText('¥10.00')).toBeInTheDocument()
  })

  it('updates the price when another period is picked', async () => {
    renderPage(<Plans />)

    await userEvent.click(await screen.findByText('季付'))

    await waitFor(() => expect(screen.getByText('¥28.00')).toBeInTheDocument())
  })

  /**
   * capacity_limit 是三态：null 不限、正整数是余量、后端把 <=0 渲染成
   * "Sold out" 字符串。字符串就意味着卖光了。
   */
  it('marks a plan as sold out when the backend says so', async () => {
    vi.spyOn(api, 'plans').mockResolvedValue([{ ...plan, capacity_limit: 'Sold out' }])

    renderPage(<Plans />)

    const button = await screen.findByRole('button', { name: /已售罄/ })
    expect(button).toBeDisabled()
  })

  it('shows the remaining capacity when it is a number', async () => {
    vi.spyOn(api, 'plans').mockResolvedValue([{ ...plan, capacity_limit: 5 }])

    renderPage(<Plans />)

    expect(await screen.findByText('剩余 5 个名额')).toBeInTheDocument()
  })

  it('blocks buying a plan that is renewal-only', async () => {
    vi.spyOn(api, 'plans').mockResolvedValue([{ ...plan, sell: false }])

    renderPage(<Plans />)

    expect(await screen.findByRole('button', { name: /仅限续费/ })).toBeDisabled()
  })

  it('creates the order with the selected period and the coupon', async () => {
    const createOrder = vi.spyOn(api, 'createOrder').mockResolvedValue('TRADE123')
    vi.spyOn(api, 'checkCoupon').mockResolvedValue({ id: 1, code: 'SAVE20', name: '八折券', type: 2, value: 20 })

    renderPage(<Plans />)

    await userEvent.click(await screen.findByText('季付'))
    await userEvent.click(screen.getByRole('button', { name: /购\s*买/ }))

    const couponInput = await screen.findByPlaceholderText('优惠码')
    await userEvent.type(couponInput, 'SAVE20')
    await userEvent.click(screen.getByRole('button', { name: /校\s*验/ }))

    // 内联状态和 toast 各出现一次，都是有意的
    expect(await screen.findAllByText('优惠码可用：八折券')).not.toHaveLength(0)

    await userEvent.click(screen.getByRole('button', { name: /创建订单/ }))

    // 优惠码必须在下单时带上——后端是在创建订单时应用它的，不是支付时
    await waitFor(() => expect(createOrder).toHaveBeenCalledWith(1, 'quarter_price', 'SAVE20'))
  })

  it('creates the order without a coupon when none is entered', async () => {
    const createOrder = vi.spyOn(api, 'createOrder').mockResolvedValue('TRADE123')

    renderPage(<Plans />)

    await userEvent.click(await screen.findByRole('button', { name: /购\s*买/ }))
    await userEvent.click(await screen.findByRole('button', { name: /创建订单/ }))

    await waitFor(() => expect(createOrder).toHaveBeenCalledWith(1, 'month_price', undefined))
  })

  it('reports the empty state when nothing is for sale', async () => {
    vi.spyOn(api, 'plans').mockResolvedValue([])

    renderPage(<Plans />)

    expect(await screen.findByText('暂无可购买的套餐')).toBeInTheDocument()
  })
})
