import { beforeEach, describe, expect, it, vi } from 'vitest'
import userEvent from '@testing-library/user-event'
import { api, type InviteData, type UserConfig } from '../api'
import { renderPage, screen, waitFor } from '../test/render'
import Invite from './Invite'

const config: UserConfig = {
  is_telegram: 0,
  telegram_discuss_link: null,
  withdraw_methods: ['支付宝', 'USDT'],
  withdraw_close: 0,
  currency: 'CNY',
  currency_symbol: '¥',
}

/** stat 是定长数组 [已注册, 已确认佣金, 确认中佣金, 佣金比例%, 可用佣金]，金额单位分 */
const invites: InviteData = {
  codes: [{ code: 'ABCD1234', pv: 17, status: 0, created_at: 1789948800 }],
  stat: [3, 3470, 1200, 15, 12500],
}

describe('Invite', () => {
  beforeEach(() => {
    vi.restoreAllMocks()
    vi.spyOn(api, 'invites').mockResolvedValue(invites)
    vi.spyOn(api, 'userConfig').mockResolvedValue(config)
    vi.spyOn(api, 'commissionLogs').mockResolvedValue({ data: [], total: 0 })
  })

  /**
   * 位置搞错不会报错，只会把金额显示到别的格子里，所以逐个钉死。
   */
  it('maps every slot of the stat array to the right figure', async () => {
    renderPage(<Invite />)

    await screen.findByText('已注册用户')

    const valueOf = (label: string) =>
      screen.getByText(label).parentElement?.querySelector('.ant-statistic-content')?.textContent

    expect(valueOf('已注册用户')).toContain('3')
    expect(valueOf('确认的佣金')).toContain('¥34.70')
    expect(valueOf('确认中的佣金')).toContain('¥12.00')
    expect(valueOf('佣金比例')).toContain('15%')
    expect(valueOf('可用佣金')).toContain('¥125.00')
  })

  it('builds a copyable invite link from the code', async () => {
    const { container } = renderPage(<Invite />)

    await screen.findByText('ABCD1234')

    const link = [...container.querySelectorAll('input[readonly]')]
      .map((input) => (input as HTMLInputElement).value)
      .find((value) => value.includes('ABCD1234'))

    expect(link).toContain('#/register?code=ABCD1234')
  })

  it('disables withdrawal when the panel has it switched off', async () => {
    vi.spyOn(api, 'userConfig').mockResolvedValue({ ...config, withdraw_close: 1 })

    renderPage(<Invite />)

    expect(await screen.findByText('当前未开放提现')).toBeInTheDocument()
    expect(screen.getByRole('button', { name: /申请提现/ })).toBeDisabled()
  })

  it('disables withdrawal when there is nothing to withdraw', async () => {
    vi.spyOn(api, 'invites').mockResolvedValue({ ...invites, stat: [3, 3470, 1200, 15, 0] })

    renderPage(<Invite />)

    await screen.findByText('可用佣金')
    expect(screen.getByRole('button', { name: /申请提现/ })).toBeDisabled()
  })

  it('offers the withdrawal methods the panel configured', async () => {
    renderPage(<Invite />)

    await userEvent.click(await screen.findByRole('button', { name: /申请提现/ }))

    expect(await screen.findByText('提现方式')).toBeInTheDocument()
    // 提现走的是开工单，文案要说清楚
    expect(screen.getByText('提现将以工单形式提交，客服处理后到账')).toBeInTheDocument()
  })

  it('reports the empty state for commission history', async () => {
    renderPage(<Invite />)

    await waitFor(() => expect(screen.getByText('还没有佣金记录')).toBeInTheDocument())
  })
})
