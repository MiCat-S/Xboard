import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import userEvent from '@testing-library/user-event'
import { Route, Routes } from 'react-router-dom'
import { setToken } from '../api/client'
import { renderPage, screen, waitFor, within } from '../test/render'
import { MOBILE_WIDTH, setViewportWidth } from '../test/setup'
import AppLayout from './AppLayout'

/** 每个页面都放一个可识别的标记，用来确认 Outlet 换了内容 */
function Page({ name }: { name: string }) {
  return <div>page:{name}</div>
}

function renderLayout(initialPath = '/dashboard') {
  return renderPage(
    <Routes>
      <Route element={<AppLayout />}>
        <Route path="/dashboard" element={<Page name="dashboard" />} />
        <Route path="/plans" element={<Page name="plans" />} />
        <Route path="/orders" element={<Page name="orders" />} />
        <Route path="/account" element={<Page name="account" />} />
        <Route path="/order/:tradeNo" element={<Page name="order-detail" />} />
      </Route>
    </Routes>,
    { initialEntries: [initialPath] },
  )
}

/**
 * 菜单项的可访问名称里还混着图标的 aria-label（比如 "dashboard 仪表板"），
 * 所以按子串匹配；侧栏和抽屉可能同时存在同名项，取第一个即可。
 */
function menuItem(label: string) {
  return screen.getAllByRole('menuitem', { name: new RegExp(label) })[0]
}

describe('AppLayout', () => {
  const realLocation = window.location

  beforeEach(() => {
    vi.restoreAllMocks()
  })

  // 替换过 window.location 的用例必须还原，否则后面的用例会连带挂掉
  afterEach(() => {
    Object.defineProperty(window, 'location', { configurable: true, value: realLocation })
  })

  it('renders every navigation entry', async () => {
    renderLayout()

    for (const label of [
      '仪表板', '购买订阅', '我的订单', '节点状态', '在线设备',
      '流量明细', '使用文档', '我的工单', '邀请返利', 'Telegram', '我的账户',
    ]) {
      expect(menuItem(label)).toBeInTheDocument()
    }
  })

  it('renders the routed page through the outlet', async () => {
    renderLayout('/plans')
    expect(screen.getByText('page:plans')).toBeInTheDocument()
  })

  it('marks the current page as selected', async () => {
    renderLayout('/orders')

    await waitFor(() => expect(menuItem('我的订单')).toHaveClass('ant-menu-item-selected'))
    expect(menuItem('仪表板')).not.toHaveClass('ant-menu-item-selected')
  })

  it('navigates when a menu entry is clicked', async () => {
    renderLayout('/dashboard')

    expect(screen.getByText('page:dashboard')).toBeInTheDocument()

    await userEvent.click(menuItem('我的账户'))

    expect(await screen.findByText('page:account')).toBeInTheDocument()
    expect(screen.queryByText('page:dashboard')).not.toBeInTheDocument()
  })

  it('shows the current page name in the header', async () => {
    renderLayout('/plans')

    const header = document.querySelector('.ant-layout-header') as HTMLElement
    expect(within(header).getByText('购买订阅')).toBeInTheDocument()
  })

  /**
   * 订单详情不在 NAV 里，如果只按 pathname 去 NAV 里找，标题会回落成「仪表板」。
   */
  it('titles the order detail page even though it has no nav entry', async () => {
    renderLayout('/order/2026092020092804738668778')

    const header = document.querySelector('.ant-layout-header') as HTMLElement
    expect(within(header).getByText('订单详情')).toBeInTheDocument()
    expect(within(header).queryByText('仪表板')).not.toBeInTheDocument()
  })

  it('signs the user out and drops the token', async () => {
    setToken('Bearer abc')
    renderLayout()

    await userEvent.click(screen.getByRole('button', { name: /退出登录/ }))

    await waitFor(() => expect(localStorage.getItem('xboard_access_token')).toBeNull())
  })

  it('switches language and reloads so the dictionary is re-read', async () => {
    // i18n 是模块级一次性读取的，换语言只能靠整页重载
    const reload = vi.fn()
    Object.defineProperty(window, 'location', {
      configurable: true,
      value: { ...window.location, reload },
    })

    renderLayout()

    await userEvent.click(document.querySelector('.anticon-global')!.closest('button')!)
    await userEvent.click(await screen.findByText('English'))

    await waitFor(() => expect(localStorage.getItem('xboard_locale')).toBe('en-US'))
    expect(reload).toHaveBeenCalled()
  })

  describe('on a phone', () => {
    beforeEach(() => {
      setViewportWidth(MOBILE_WIDTH)
    })

    it('hides the sider and opens the navigation in a drawer', async () => {
      renderLayout()

      // 手机上没有侧栏，菜单只存在于抽屉里，所以此刻页面上不该有任何菜单项
      expect(document.querySelector('.ant-layout-sider')).toBeNull()
      expect(screen.queryByText('我的订单')).not.toBeInTheDocument()

      await userEvent.click(document.querySelector('.anticon-menu')!.closest('button')!)

      await waitFor(() => expect(document.querySelector('.ant-drawer-open')).toBeTruthy())
      expect(await screen.findByText('我的订单')).toBeInTheDocument()
    })

    it('navigates and closes the drawer when an entry is tapped', async () => {
      renderLayout('/dashboard')

      await userEvent.click(document.querySelector('.anticon-menu')!.closest('button')!)
      await userEvent.click(await screen.findByText('我的订单'))

      expect(await screen.findByText('page:orders')).toBeInTheDocument()
      await waitFor(() => expect(document.querySelector('.ant-drawer-open')).toBeNull())
    })
  })
})
