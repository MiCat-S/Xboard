import { describe, expect, it } from 'vitest'
import type { PlanDetail } from '../api'
import { PERIODS } from '../api'
import { availablePeriods, formatMoney, handlingFeeOf, isPayable, planPrice, statusColor, statusLabel } from './order'

const plan = {
  id: 1,
  name: 'test',
  content: '',
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

describe('formatMoney', () => {
  it('renders cents as yuan with two decimals', () => {
    expect(formatMoney(0)).toBe('¥0.00')
    expect(formatMoney(1)).toBe('¥0.01')
    expect(formatMoney(1775)).toBe('¥17.75')
    expect(formatMoney(10000)).toBe('¥100.00')
  })

  it('treats missing amounts as zero', () => {
    expect(formatMoney(null)).toBe('¥0.00')
    expect(formatMoney(undefined)).toBe('¥0.00')
  })
})

describe('plan periods', () => {
  it('only offers periods the plan actually prices', () => {
    expect(availablePeriods(plan, PERIODS)).toEqual(['month_price', 'quarter_price', 'year_price'])
  })

  it('reads the price for a period', () => {
    expect(planPrice(plan, 'month_price')).toBe(1000)
    expect(planPrice(plan, 'half_year_price')).toBeNull()
  })
})

/**
 * 必须与后端一致：
 *   (int) round($order->total_amount * ($percent / 100) + $fixed)
 * 对不上的话页面显示的金额会和实际扣款不同。
 */
describe('handlingFeeOf', () => {
  it('matches the backend formula for a percentage fee', () => {
    // 1740 * 2% = 34.8 -> 35
    expect(handlingFeeOf(1740, { handling_fee_percent: 2, handling_fee_fixed: null })).toBe(35)
  })

  it('adds a fixed fee', () => {
    expect(handlingFeeOf(1000, { handling_fee_percent: null, handling_fee_fixed: 50 })).toBe(50)
  })

  it('combines percentage and fixed fees', () => {
    // 10000 * 3% = 300, + 100 = 400
    expect(handlingFeeOf(10000, { handling_fee_percent: 3, handling_fee_fixed: 100 })).toBe(400)
  })

  it('rounds half up like PHP round()', () => {
    // 100 * 0.5% = 0.5 -> 1
    expect(handlingFeeOf(100, { handling_fee_percent: 0.5, handling_fee_fixed: null })).toBe(1)
  })

  it('is zero when no method is selected or no fee is configured', () => {
    expect(handlingFeeOf(1000, undefined)).toBe(0)
    expect(handlingFeeOf(1000, { handling_fee_percent: null, handling_fee_fixed: null })).toBe(0)
    expect(handlingFeeOf(1000, { handling_fee_percent: 0, handling_fee_fixed: 0 })).toBe(0)
  })
})

describe('order status', () => {
  it('labels every status the backend can return', () => {
    // 0 待支付 / 1 开通中 / 2 已取消 / 3 已完成 / 4 已折抵
    for (const status of [0, 1, 2, 3, 4] as const) {
      expect(statusLabel(status)).toBeTruthy()
      expect(statusColor(status)).toBeTruthy()
    }
  })

  it('only allows paying a pending order', () => {
    expect(isPayable({ status: 0 } as never)).toBe(true)
    for (const status of [1, 2, 3, 4] as const) {
      expect(isPayable({ status } as never)).toBe(false)
    }
  })
})
