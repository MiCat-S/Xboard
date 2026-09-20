import { describe, expect, it } from 'vitest'
import { expiryState, formatBytes, formatDate, formatDateTime } from './format'

describe('formatBytes', () => {
  it('scales to the largest unit that fits', () => {
    expect(formatBytes(0)).toBe('0 B')
    expect(formatBytes(512)).toBe('512 B')
    expect(formatBytes(1024)).toBe('1.00 KB')
    expect(formatBytes(1024 ** 2)).toBe('1.00 MB')
    expect(formatBytes(1024 ** 3)).toBe('1.00 GB')
    expect(formatBytes(107374182400)).toBe('100.00 GB')
  })

  it('treats missing and negative values as zero', () => {
    expect(formatBytes(null)).toBe('0 B')
    expect(formatBytes(undefined)).toBe('0 B')
    expect(formatBytes(-1)).toBe('0 B')
  })
})

/**
 * Xboard 的到期时间是三态，不是两态。只判 null 会把「无套餐」显示成
 * 「剩余 0 天」——这是实际踩过的 bug，用例锁住它。
 */
describe('expiryState', () => {
  it('treats null as never expiring', () => {
    expect(expiryState(null)).toEqual({ kind: 'never' })
    expect(expiryState(undefined)).toEqual({ kind: 'never' })
  })

  it('treats 0 as expired, not as never expiring', () => {
    // 数据库默认值就是 0，无套餐的用户拿到的就是它
    expect(expiryState(0)).toEqual({ kind: 'expired' })
  })

  it('treats a past timestamp as expired', () => {
    const yesterday = Math.floor(Date.now() / 1000) - 86400
    expect(expiryState(yesterday)).toEqual({ kind: 'expired' })
  })

  it('reports the remaining days for a future timestamp', () => {
    const inTenDays = Math.floor(Date.now() / 1000) + 86400 * 10
    const state = expiryState(inTenDays)

    expect(state.kind).toBe('active')
    if (state.kind === 'active') {
      expect(state.days).toBeGreaterThanOrEqual(9)
      expect(state.days).toBeLessThanOrEqual(10)
    }
  })
})

describe('date formatting', () => {
  it('falls back for empty timestamps', () => {
    expect(formatDate(null)).toBe('—')
    expect(formatDate(0)).toBe('—')
    expect(formatDateTime(undefined)).toBe('—')
  })

  it('formats a unix timestamp', () => {
    // 2026-09-20T00:00:00Z
    expect(formatDate(1789948800)).toMatch(/^\d{4}-\d{2}-\d{2}$/)
    expect(formatDateTime(1789948800)).toMatch(/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/)
  })
})
