import dayjs from 'dayjs'

const GB = 1024 ** 3
const MB = 1024 ** 2
const KB = 1024

export function formatBytes(bytes: number | null | undefined): string {
  const value = Number(bytes ?? 0)
  if (!Number.isFinite(value) || value <= 0) return '0 B'
  if (value >= GB) return `${(value / GB).toFixed(2)} GB`
  if (value >= MB) return `${(value / MB).toFixed(2)} MB`
  if (value >= KB) return `${(value / KB).toFixed(2)} KB`
  return `${value} B`
}

export function formatDate(unix: number | null | undefined, fallback = '—'): string {
  if (!unix) return fallback
  return dayjs.unix(unix).format('YYYY-MM-DD')
}

export function formatDateTime(unix: number | null | undefined, fallback = '—'): string {
  if (!unix) return fallback
  return dayjs.unix(unix).format('YYYY-MM-DD HH:mm:ss')
}

/**
 * Xboard 的到期语义有三种，别只判 null：
 *   null      长期有效
 *   0         无套餐 / 已过期（数据库默认值就是 0）
 *   timestamp 到期时间，早于当前即已过期
 */
export type ExpiryState =
  | { kind: 'never' }
  | { kind: 'expired' }
  | { kind: 'active'; days: number }

export function expiryState(expiredAt: number | null | undefined): ExpiryState {
  if (expiredAt === null || expiredAt === undefined) return { kind: 'never' }
  if (expiredAt <= 0) return { kind: 'expired' }

  const days = dayjs.unix(expiredAt).diff(dayjs(), 'day')
  return days < 0 ? { kind: 'expired' } : { kind: 'active', days }
}

export async function copyText(text: string): Promise<boolean> {
  try {
    if (navigator.clipboard?.writeText) {
      await navigator.clipboard.writeText(text)
      return true
    }
  } catch {
    // 非 https 或权限不足时退回 execCommand
  }

  try {
    const area = document.createElement('textarea')
    area.value = text
    area.style.position = 'fixed'
    area.style.opacity = '0'
    document.body.appendChild(area)
    area.select()
    const ok = document.execCommand('copy')
    document.body.removeChild(area)
    return ok
  } catch {
    return false
  }
}
