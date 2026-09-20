import { Card, Typography, theme } from 'antd'
import type { ReactNode } from 'react'
import { siteSettings } from '../settings'

const { useToken } = theme

/** 登录/注册/找回密码共用的居中卡片外壳，间距全部取自 design token */
export default function AuthShell({
  title,
  subtitle,
  footer,
  children,
}: {
  title?: string
  subtitle?: string
  footer?: ReactNode
  children: ReactNode
}) {
  const { token } = useToken()

  return (
    <div
      style={{
        minHeight: '100vh',
        display: 'flex',
        alignItems: 'center',
        justifyContent: 'center',
        padding: `${token.paddingLG}px ${token.padding}px`,
      }}
    >
      <Card style={{ width: '100%', maxWidth: 380 }}>
        <Typography.Title level={4} style={{ marginTop: 0, textAlign: 'center' }}>
          {title ?? siteSettings.title}
        </Typography.Title>
        {subtitle && (
          <Typography.Paragraph type="secondary" style={{ textAlign: 'center' }}>
            {subtitle}
          </Typography.Paragraph>
        )}
        {children}
        {footer && <div style={{ marginTop: token.margin }}>{footer}</div>}
      </Card>
    </div>
  )
}
