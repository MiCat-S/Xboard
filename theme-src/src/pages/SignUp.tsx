import { Button, Card, Form, Input, Typography, App as AntdApp, Space } from 'antd'
import { Link } from 'react-router-dom'
import { useState } from 'react'
import { api } from '../api'
import { useAuth } from '../AuthContext'
import { useRequest } from '../hooks/useRequest'
import { t } from '../i18n'
import { siteSettings } from '../settings'
import EmailCodeButton from '../components/EmailCodeButton'

export default function SignUp() {
  const { signIn } = useAuth()
  const { message } = AntdApp.useApp()
  const [loading, setLoading] = useState(false)
  const [form] = Form.useForm()
  const { data: config } = useRequest(() => api.guestConfig())

  const onFinish = async (values: Record<string, string>) => {
    if (values.password !== values.password_confirm) {
      message.error(t('passwordMismatch'))
      return
    }

    setLoading(true)
    try {
      const payload: Record<string, unknown> = {
        email: values.email,
        password: values.password,
      }
      if (values.email_code) payload.email_code = values.email_code
      if (values.invite_code) payload.invite_code = values.invite_code

      const data = await api.register(payload)
      message.success(t('signUpSuccess'))
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

        <Form layout="vertical" form={form} onFinish={onFinish} requiredMark={false}>
          <Form.Item name="email" label={t('email')} rules={[{ required: true, type: 'email' }]}>
            <Input size="large" autoComplete="username" />
          </Form.Item>

          {config?.is_email_verify === 1 && (
            <Form.Item name="email_code" label={t('emailCode')} rules={[{ required: true }]}>
              <Space.Compact style={{ width: '100%' }}>
                <Input size="large" />
                <EmailCodeButton getEmail={() => form.getFieldValue('email')} />
              </Space.Compact>
            </Form.Item>
          )}

          <Form.Item name="password" label={t('password')} rules={[{ required: true, min: 8 }]}>
            <Input.Password size="large" autoComplete="new-password" />
          </Form.Item>
          <Form.Item name="password_confirm" label={t('confirmPassword')} rules={[{ required: true }]}>
            <Input.Password size="large" autoComplete="new-password" />
          </Form.Item>
          <Form.Item
            name="invite_code"
            label={config?.is_invite_force === 1 ? t('inviteCode') : t('inviteCodeOptional')}
            rules={[{ required: config?.is_invite_force === 1 }]}
          >
            <Input size="large" />
          </Form.Item>

          <Button type="primary" size="large" htmlType="submit" loading={loading} block>
            {t('signUp')}
          </Button>
        </Form>

        <div style={{ textAlign: 'center', marginTop: 16 }}>
          {t('hasAccount')} <Link to="/login">{t('signIn')}</Link>
        </div>
      </Card>
    </div>
  )
}
