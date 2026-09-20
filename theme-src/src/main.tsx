import { StrictMode } from 'react'
import { createRoot } from 'react-dom/client'
import { ConfigProvider, App as AntdApp, theme } from 'antd'
import zhCN from 'antd/locale/zh_CN'
import enUS from 'antd/locale/en_US'
import 'antd/dist/reset.css'
import App from './App'
import { AuthProvider } from './AuthContext'
import { getLocale } from './i18n'
import './styles.css'

const prefersDark = window.matchMedia?.('(prefers-color-scheme: dark)').matches ?? false

createRoot(document.getElementById('root')!).render(
  <StrictMode>
    <ConfigProvider
      locale={getLocale() === 'zh-CN' ? zhCN : enUS}
      theme={{
        algorithm: prefersDark ? theme.darkAlgorithm : theme.defaultAlgorithm,
        token: { colorPrimary: '#2f6fed', borderRadius: 8 },
      }}
    >
      <AntdApp>
        <AuthProvider>
          <App />
        </AuthProvider>
      </AntdApp>
    </ConfigProvider>
  </StrictMode>,
)
