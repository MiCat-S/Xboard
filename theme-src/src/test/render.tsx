import { render, type RenderOptions } from '@testing-library/react'
import { App as AntdApp, ConfigProvider } from 'antd'
import zhCN from 'antd/locale/zh_CN'
import type { ReactElement, ReactNode } from 'react'
import { MemoryRouter } from 'react-router-dom'
import { AuthProvider } from '../AuthContext'

interface PageOptions extends Omit<RenderOptions, 'wrapper'> {
  /** 需要路由参数的页面（如 /order/:tradeNo）用它指定初始地址 */
  initialEntries?: string[]
}

/** 页面组件都依赖 antd 的 App/ConfigProvider 和路由，统一包一层 */
export function renderPage(ui: ReactElement, { initialEntries, ...options }: PageOptions = {}) {
  function Providers({ children }: { children: ReactNode }) {
    return (
      <ConfigProvider locale={zhCN} theme={{ token: { colorPrimary: '#2f6fed' } }}>
        <AntdApp>
          <AuthProvider>
            <MemoryRouter initialEntries={initialEntries}>{children}</MemoryRouter>
          </AuthProvider>
        </AntdApp>
      </ConfigProvider>
    )
  }

  return render(ui, { wrapper: Providers, ...options })
}

export * from '@testing-library/react'
