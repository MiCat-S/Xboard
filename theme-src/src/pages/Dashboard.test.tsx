import { beforeEach, describe, expect, it, vi } from 'vitest'
import { api, type Subscribe } from '../api'
import { renderPage, screen, waitFor } from '../test/render'
import Dashboard from './Dashboard'

const base: Subscribe = {
  plan_id: 1,
  token: 'abc123',
  expired_at: null,
  u: 12 * 1024 ** 3,
  d: 45 * 1024 ** 3,
  transfer_enable: 200 * 1024 ** 3,
  email: 'demo@example.com',
  uuid: 'uuid',
  device_limit: 3,
  speed_limit: null,
  reset_day: null,
  subscribe_url: 'https://panel.example.com/s/abc123',
  plan: { id: 1, name: '标准套餐', content: null },
}

function mockSubscribe(overrides: Partial<Subscribe>) {
  vi.spyOn(api, 'subscribe').mockResolvedValue({ ...base, ...overrides })
}

describe('Dashboard', () => {
  beforeEach(() => {
    vi.restoreAllMocks()
  })

  it('shows traffic used, remaining and total', async () => {
    mockSubscribe({})
    renderPage(<Dashboard />)

    expect(await screen.findByText('57.00 GB')).toBeInTheDocument() // 已用
    expect(screen.getByText('143.00 GB')).toBeInTheDocument() // 剩余
    expect(screen.getByText('200.00 GB')).toBeInTheDocument() // 总量
  })

  /**
   * expired_at 是三态。只判 null 会让「无套餐」(0) 显示成「剩余 0 天」——
   * 这是实际踩过的 bug。
   */
  it('shows a plan with no expiry as never expiring', async () => {
    mockSubscribe({ expired_at: null })
    renderPage(<Dashboard />)

    expect(await screen.findAllByText('长期有效')).not.toHaveLength(0)
    expect(screen.queryByText(/剩余 0 天/)).not.toBeInTheDocument()
  })

  /**
   * 带套餐但 expired_at = 0 才是真正暴露 bug 的组合：只判 null 的话
   * 这里会渲染成「剩余 0 天」而不是「已过期」。
   */
  it('shows a plan whose expired_at is 0 as expired, not as "0 days left"', async () => {
    mockSubscribe({ expired_at: 0 })
    renderPage(<Dashboard />)

    expect(await screen.findAllByText('已过期')).not.toHaveLength(0)
    expect(screen.queryByText(/剩余 0 天/)).not.toBeInTheDocument()
    expect(screen.queryByText('长期有效')).not.toBeInTheDocument()
  })

  it('shows the no-plan state when the user has no subscription', async () => {
    mockSubscribe({ expired_at: 0, plan_id: null, plan: undefined })
    renderPage(<Dashboard />)

    expect(await screen.findByText('暂无订阅')).toBeInTheDocument()
    expect(screen.getByText('当前没有生效中的套餐')).toBeInTheDocument()
  })

  it('shows the remaining days for a future expiry', async () => {
    const inTwentyDays = Math.floor(Date.now() / 1000) + 86400 * 20
    mockSubscribe({ expired_at: inTwentyDays })
    renderPage(<Dashboard />)

    expect(await screen.findByText(/剩余 (19|20) 天/)).toBeInTheDocument()
  })

  it('masks the subscription link until it is revealed', async () => {
    mockSubscribe({})
    const { container } = renderPage(<Dashboard />)

    await waitFor(() => {
      const input = container.querySelector('input[readonly]') as HTMLInputElement
      expect(input.value).toContain('••••')
      expect(input.value).not.toContain('abc123')
    })
  })

  it('reports unlimited when there is no device or speed cap', async () => {
    mockSubscribe({ device_limit: null, speed_limit: null })
    renderPage(<Dashboard />)

    await waitFor(() => expect(screen.getAllByText('不限')).toHaveLength(2))
  })

  it('surfaces a load failure with a retry', async () => {
    vi.spyOn(api, 'subscribe').mockRejectedValue(new Error('套餐不存在'))
    renderPage(<Dashboard />)

    expect(await screen.findByText('加载失败')).toBeInTheDocument()
    expect(screen.getByText('套餐不存在')).toBeInTheDocument()
    expect(screen.getByRole('button', { name: /重\s*试/ })).toBeInTheDocument()
  })
})
