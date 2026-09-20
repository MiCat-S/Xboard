import { useState } from 'react'
import { Layout, Menu, Button, Dropdown, Grid, Drawer, Typography, Space } from 'antd'
import {
  DashboardOutlined,
  CloudServerOutlined,
  DesktopOutlined,
  BarChartOutlined,
  UserOutlined,
  MenuOutlined,
  LogoutOutlined,
  GlobalOutlined,
} from '@ant-design/icons'
import { Outlet, useLocation, useNavigate } from 'react-router-dom'
import { useAuth } from '../AuthContext'
import { getLocale, setLocale, t, type Locale } from '../i18n'
import { siteSettings } from '../settings'

const { Header, Sider, Content } = Layout
const { useBreakpoint } = Grid

const NAV = [
  { key: '/dashboard', icon: <DashboardOutlined />, labelKey: 'navDashboard' as const },
  { key: '/nodes', icon: <CloudServerOutlined />, labelKey: 'navNodes' as const },
  { key: '/devices', icon: <DesktopOutlined />, labelKey: 'navDevices' as const },
  { key: '/traffic', icon: <BarChartOutlined />, labelKey: 'navTraffic' as const },
  { key: '/account', icon: <UserOutlined />, labelKey: 'navAccount' as const },
]

export default function AppLayout() {
  const navigate = useNavigate()
  const location = useLocation()
  const { signOut } = useAuth()
  const screens = useBreakpoint()
  const [drawerOpen, setDrawerOpen] = useState(false)

  const isMobile = !screens.md
  const items = NAV.map((item) => ({ key: item.key, icon: item.icon, label: t(item.labelKey) }))

  const go = (key: string) => {
    navigate(key)
    setDrawerOpen(false)
  }

  const menu = (
    <Menu
      mode="inline"
      selectedKeys={[location.pathname]}
      items={items}
      onClick={({ key }) => go(key)}
      style={{ borderInlineEnd: 'none' }}
    />
  )

  const switchLocale = (locale: Locale) => {
    setLocale(locale)
    window.location.reload()
  }

  return (
    <Layout style={{ minHeight: '100vh' }}>
      {!isMobile && (
        <Sider theme="light" width={216} breakpoint="md" collapsedWidth={0} trigger={null}>
          <div
            style={{
              height: 56,
              display: 'flex',
              alignItems: 'center',
              padding: '0 20px',
              fontWeight: 600,
              fontSize: 16,
            }}
          >
            {siteSettings.title}
          </div>
          {menu}
        </Sider>
      )}

      <Layout>
        <Header
          style={{
            background: 'transparent',
            paddingInline: isMobile ? 12 : 24,
            display: 'flex',
            alignItems: 'center',
            gap: 12,
            height: 56,
            lineHeight: '56px',
          }}
        >
          {isMobile && (
            <Button type="text" icon={<MenuOutlined />} onClick={() => setDrawerOpen(true)} />
          )}
          <Typography.Text strong style={{ fontSize: 16 }}>
            {t(NAV.find((n) => n.key === location.pathname)?.labelKey ?? 'navDashboard')}
          </Typography.Text>
          <span style={{ flex: 1 }} />
          <Space size={4}>
            <Dropdown
              menu={{
                items: [
                  { key: 'zh-CN', label: '简体中文' },
                  { key: 'en-US', label: 'English' },
                ],
                selectedKeys: [getLocale()],
                onClick: ({ key }) => switchLocale(key as Locale),
              }}
            >
              <Button type="text" icon={<GlobalOutlined />} />
            </Dropdown>
            <Button type="text" icon={<LogoutOutlined />} onClick={signOut}>
              {!isMobile && t('signOut')}
            </Button>
          </Space>
        </Header>

        <Content style={{ padding: isMobile ? 12 : 24, paddingTop: 0 }}>
          <div style={{ maxWidth: 1080, margin: '0 auto' }}>
            <Outlet />
          </div>
        </Content>
      </Layout>

      <Drawer
        open={drawerOpen}
        placement="left"
        width={240}
        onClose={() => setDrawerOpen(false)}
        title={siteSettings.title}
        styles={{ body: { padding: 0 } }}
      >
        {menu}
      </Drawer>
    </Layout>
  )
}
