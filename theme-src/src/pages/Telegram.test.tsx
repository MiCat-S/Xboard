import { beforeEach, describe, expect, it, vi } from 'vitest'
import { api, type Subscribe, type UserConfig, type UserInfo } from '../api'
import { renderPage, screen } from '../test/render'
import Telegram from './Telegram'

const config: UserConfig = {
  is_telegram: 1,
  telegram_discuss_link: null,
  withdraw_methods: [],
  withdraw_close: 0,
  currency: 'CNY',
  currency_symbol: '¥',
}

const info = {
  email: 'demo@example.com', transfer_enable: 0, last_login_at: null, created_at: 0,
  banned: 0, expired_at: null, balance: 0, commission_balance: 0, plan_id: null,
  telegram_id: null, uuid: 'u', avatar_url: '',
} satisfies UserInfo

const subscribe = {
  plan_id: null, token: 'abc', expired_at: null, u: 0, d: 0, transfer_enable: 0,
  email: 'demo@example.com', uuid: 'u', device_limit: null, speed_limit: null,
  reset_day: null, subscribe_url: 'https://panel.example.com/s/abc',
} satisfies Subscribe

describe('Telegram', () => {
  beforeEach(() => {
    vi.restoreAllMocks()
    vi.spyOn(api, 'info').mockResolvedValue(info)
    vi.spyOn(api, 'subscribe').mockResolvedValue(subscribe)
  })

  it('says the feature is off when the site has no bot enabled', async () => {
    vi.spyOn(api, 'userConfig').mockResolvedValue({ ...config, is_telegram: 0 })
    const botInfo = vi.spyOn(api, 'botInfo')

    renderPage(<Telegram />)

    expect(await screen.findByText('站点未启用 Telegram 机器人')).toBeInTheDocument()
    // 没启用就不该去问 Telegram，那个调用会直接抛原始 cURL 错误
    expect(botInfo).not.toHaveBeenCalled()
  })

  /**
   * getBotInfo 直接打 api.telegram.org，未配置时抛的是原始 cURL/TLS 错误。
   * 那串东西不能出现在用户面前。
   */
  it('degrades to a friendly message when the bot cannot be reached', async () => {
    vi.spyOn(api, 'userConfig').mockResolvedValue(config)
    vi.spyOn(api, 'botInfo').mockRejectedValue(
      new Error('cURL error 35: TLS connect error ... https://api.telegram.org/bot/getMe'),
    )

    renderPage(<Telegram />)

    expect(await screen.findByText('暂时无法获取机器人信息，请稍后再试')).toBeInTheDocument()
    expect(screen.queryByText(/cURL/)).not.toBeInTheDocument()
    expect(screen.queryByText(/api\.telegram\.org/)).not.toBeInTheDocument()
  })

  it('shows the bind command with the user own subscription link', async () => {
    vi.spyOn(api, 'userConfig').mockResolvedValue(config)
    vi.spyOn(api, 'botInfo').mockResolvedValue({ username: 'my_xboard_bot' })

    const { container } = renderPage(<Telegram />)

    expect(await screen.findByText(/@my_xboard_bot/)).toBeInTheDocument()

    const input = container.querySelector('input[readonly]') as HTMLInputElement
    expect(input.value).toBe('/bind https://panel.example.com/s/abc')

    expect(screen.getByText('未绑定')).toBeInTheDocument()
  })

  it('marks the account as linked and explains how to unlink', async () => {
    vi.spyOn(api, 'userConfig').mockResolvedValue(config)
    vi.spyOn(api, 'botInfo').mockResolvedValue({ username: 'my_xboard_bot' })
    vi.spyOn(api, 'info').mockResolvedValue({ ...info, telegram_id: 123456789 })

    renderPage(<Telegram />)

    expect(await screen.findByText('已绑定')).toBeInTheDocument()
    expect(screen.getByText('如需解绑，在机器人里发送 /unbind')).toBeInTheDocument()
  })
})
