import { Button, Card, Form, Input, Typography, App as AntdApp, Space } from 'antd'
import { Link, useNavigate } from 'react-router-dom'
import { useState } from 'react'
import { api } from '../api'
import { t } from '../i18n'
import EmailCodeButton from '../components/EmailCodeButton'

export default function Forget() {
  const { message } = AntdApp.useApp()
  const navigate = useNavigate()
  const [loading, setLoading] = useState(false)
  const [form] = Form.useForm()

  const onFinish = async (values: Record<string, string>) => {
    if (values.password !== values.password_confirm) {
      message.error(t('passwordMismatch'))
      return
    }

    setLoading(true)
    try {
      await api.forget(values.email, values.email_code, values.password)
      message.success(t('resetSuccess'))
      navigate('/login')
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
          {t('forgotPassword')}
        </Typography.Title>

        <Form layout="vertical" form={form} onFinish={onFinish} requiredMark={false}>
          <Form.Item name="email" label={t('email')} rules={[{ required: true, type: 'email' }]}>
            <Input size="large" autoComplete="username" />
          </Form.Item>
          <Form.Item name="email_code" label={t('emailCode')} rules={[{ required: true }]}>
            <Space.Compact style={{ width: '100%' }}>
              <Input size="large" />
              <EmailCodeButton getEmail={() => form.getFieldValue('email')} />
            </Space.Compact>
          </Form.Item>
          <Form.Item name="password" label={t('newPassword')} rules={[{ required: true, min: 8 }]}>
            <Input.Password size="large" autoComplete="new-password" />
          </Form.Item>
          <Form.Item name="password_confirm" label={t('confirmPassword')} rules={[{ required: true }]}>
            <Input.Password size="large" autoComplete="new-password" />
          </Form.Item>

          <Button type="primary" size="large" htmlType="submit" loading={loading} block>
            {t('submit')}
          </Button>
        </Form>

        <div style={{ textAlign: 'center', marginTop: 16 }}>
          <Link to="/login">{t('backToSignIn')}</Link>
        </div>
      </Card>
    </div>
  )
}
