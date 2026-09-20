import { Button, Card, Form, Input, Typography, App as AntdApp } from 'antd'
import { Link } from 'react-router-dom'
import { useState } from 'react'
import { api } from '../api'
import { useAuth } from '../AuthContext'
import { t } from '../i18n'
import { siteSettings } from '../settings'

export default function SignIn() {
  const { signIn } = useAuth()
  const { message } = AntdApp.useApp()
  const [loading, setLoading] = useState(false)

  const onFinish = async (values: { email: string; password: string }) => {
    setLoading(true)
    try {
      const data = await api.login(values.email, values.password)
      message.success(t('signInSuccess'))
      signIn(data.auth_data)
    } catch (error) {
      message.error(error instanceof Error ? error.message : String(error))
    } finally {
      setLoading(false)
    }
  }

  return (
    <div className="auth-page">
      <Card className="auth-card">
        <Typography.Title level={4} style={{ marginTop: 0, textAlign: 'center' }}>
          {siteSettings.title}
        </Typography.Title>
        {siteSettings.description && (
          <Typography.Paragraph type="secondary" style={{ textAlign: 'center' }}>
            {siteSettings.description}
          </Typography.Paragraph>
        )}

        <Form layout="vertical" onFinish={onFinish} requiredMark={false}>
          <Form.Item name="email" label={t('email')} rules={[{ required: true, type: 'email' }]}>
            <Input size="large" autoComplete="username" />
          </Form.Item>
          <Form.Item name="password" label={t('password')} rules={[{ required: true }]}>
            <Input.Password size="large" autoComplete="current-password" />
          </Form.Item>
          <Button type="primary" size="large" htmlType="submit" loading={loading} block>
            {t('signIn')}
          </Button>
        </Form>

        <div style={{ display: 'flex', justifyContent: 'space-between', marginTop: 16 }}>
          <Link to="/register">{t('signUp')}</Link>
          <Link to="/forgetpassword">{t('forgotPassword')}</Link>
        </div>
      </Card>
    </div>
  )
}
