import { useState } from 'react'
import { Layout, Menu, Button, Dropdown, Grid, Drawer, Typography, Space, theme } from 'antd'
import {
  DashboardOutlined,
  CloudServerOutlined,
  ShoppingOutlined,
  FileTextOutlined,
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
import { layout } from '../theme'

const { Header, Sider, Content } = Layout
const { useToken } = theme
const { useBreakpoint } = Grid

const NAV = [
  { key: '/dashboard', icon: <DashboardOutlined />, labelKey: 'navDashboard' as const },
  { key: '/plans', icon: <ShoppingOutlined />, labelKey: 'navPlans' as const },
  { key: '/orders', icon: <FileTextOutlined />, labelKey: 'navOrders' as const },
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
  const { token } = useToken()
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
        <Sider theme="light" width={layout.siderWidth} breakpoint="md" collapsedWidth={0} trigger={null}>
          <div
            style={{
              height: layout.headerHeight,
              display: 'flex',
              alignItems: 'center',
              paddingInline: token.paddingLG,
              fontWeight: 600,
              fontSize: token.fontSizeLG,
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
            display: 'flex',
            alignItems: 'center',
            gap: token.marginSM,
            paddingInline: isMobile ? token.paddingSM : token.paddingLG,
          }}
        >
          {isMobile && (
            <Button type="text" icon={<MenuOutlined />} onClick={() => setDrawerOpen(true)} />
          )}
          <Typography.Text strong style={{ fontSize: token.fontSizeLG }}>
            {location.pathname.startsWith('/order/')
              ? t('orderDetail')
              : t(NAV.find((n) => n.key === location.pathname)?.labelKey ?? 'navDashboard')}
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

        <Content style={{ padding: isMobile ? token.paddingSM : token.paddingLG, paddingTop: 0 }}>
          <div style={{ maxWidth: layout.contentMaxWidth, margin: '0 auto' }}>
            <Outlet />
          </div>
        </Content>
      </Layout>

      <Drawer
        open={drawerOpen}
        placement="left"
        width={layout.siderWidth + 24}
        onClose={() => setDrawerOpen(false)}
        title={siteSettings.title}
        styles={{ body: { padding: 0 } }}
      >
        {menu}
      </Drawer>
    </Layout>
  )
}
