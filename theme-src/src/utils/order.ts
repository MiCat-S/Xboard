import type { Order, OrderStatus, Period, PlanDetail } from '../api'
import { t } from '../i18n'
import type { MessageKey } from '../i18n'

/** 周期键 → 文案键。后端用的是旧版键名（month_price 之类） */
const PERIOD_LABEL: Record<string, MessageKey> = {
  month_price: 'periodMonth',
  quarter_price: 'periodQuarter',
  half_year_price: 'periodHalfYear',
  year_price: 'periodYear',
  two_year_price: 'periodTwoYear',
  three_year_price: 'periodThreeYear',
  onetime_price: 'periodOnetime',
  reset_price: 'periodReset',
}

export function periodLabel(period: string): string {
  const key = PERIOD_LABEL[period]
  return key ? t(key) : period
}

const STATUS_LABEL: Record<OrderStatus, MessageKey> = {
  0: 'statusPending',
  1: 'statusProcessing',
  2: 'statusCancelled',
  3: 'statusCompleted',
  4: 'statusDiscounted',
}

export function statusLabel(status: OrderStatus): string {
  return t(STATUS_LABEL[status] ?? 'statusPending')
}

export function statusColor(status: OrderStatus): string {
  switch (status) {
    case 0:
      return 'warning'
    case 1:
      return 'processing'
    case 3:
      return 'success'
    case 4:
      return 'default'
    default:
      return 'default'
  }
}

/** 所有金额字段都是分 */
export function formatMoney(cents: number | null | undefined): string {
  return `¥${((cents ?? 0) / 100).toFixed(2)}`
}

export function planPrice(plan: PlanDetail, period: Period): number | null {
  const value = plan[period]
  return typeof value === 'number' ? value : null
}

/** 该套餐实际开放售卖的周期 */
export function availablePeriods(plan: PlanDetail, periods: readonly Period[]): Period[] {
  return periods.filter((period) => planPrice(plan, period) !== null)
}

export function isPayable(order: Order): boolean {
  return order.status === 0
}

/**
 * 手续费只有在 checkout 时才会写进订单，下单后的详情页拿不到。
 * 这里按后端同一公式预估，避免用户看到的金额和实际扣款对不上。
 * 后端：(int) round(total_amount * percent / 100 + fixed)
 */
export function handlingFeeOf(
  totalAmount: number,
  method: { handling_fee_percent: number | null; handling_fee_fixed: number | null } | undefined,
): number {
  if (!method) return 0

  const percent = Number(method.handling_fee_percent ?? 0)
  const fixed = Number(method.handling_fee_fixed ?? 0)
  if (!percent && !fixed) return 0

  return Math.round((totalAmount * percent) / 100 + fixed)
}
