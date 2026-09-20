import { StrictMode } from 'react'
import { createRoot } from 'react-dom/client'
import { ConfigProvider, App as AntdApp } from 'antd'
import zhCN from 'antd/locale/zh_CN'
import enUS from 'antd/locale/en_US'
import 'antd/dist/reset.css'
import App from './App'
import { AuthProvider } from './AuthContext'
import { getLocale } from './i18n'
import { buildThemeConfig } from './theme'
import './styles.css'

const prefersDark = window.matchMedia?.('(prefers-color-scheme: dark)').matches ?? false

createRoot(document.getElementById('root')!).render(
  <StrictMode>
    <ConfigProvider
      locale={getLocale() === 'zh-CN' ? zhCN : enUS}
      theme={buildThemeConfig(prefersDark)}
    >
      <AntdApp>
        <AuthProvider>
          <App />
        </AuthProvider>
      </AntdApp>
    </ConfigProvider>
  </StrictMode>,
)
