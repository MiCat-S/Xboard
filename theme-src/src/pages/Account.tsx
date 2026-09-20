import { Button, Card, Descriptions, Form, Input, Popconfirm, Space, Typography, App as AntdApp } from 'antd'
import { useState } from 'react'
import { api } from '../api'
import { useAuth } from '../AuthContext'
import { useRequest } from '../hooks/useRequest'
import Loadable from '../components/Loadable'
import { formatDateTime } from '../utils/format'
import { t } from '../i18n'

function ChangePassword() {
  const { message } = AntdApp.useApp()
  const { signOut } = useAuth()
  const [loading, setLoading] = useState(false)
  const [form] = Form.useForm()

  const onFinish = async (values: Record<string, string>) => {
    if (values.new_password !== values.confirm) {
      message.error(t('passwordMismatch'))
      return
    }

    setLoading(true)
    try {
      await api.changePassword(values.old_password, values.new_password)
      message.success(t('passwordChanged'))
      form.resetFields()
      // 后端会吊销其它会话，这里也退出，让用户用新密码重新登录
      signOut()
    } catch (error) {
      message.error(error instanceof Error ? error.message : String(error))
    } finally {
      setLoading(false)
    }
  }

  return (
    <Card title={t('changePassword')} style={{ marginTop: 16 }}>
      <Form layout="vertical" form={form} onFinish={onFinish} requiredMark={false} style={{ maxWidth: 380 }}>
        <Form.Item name="old_password" label={t('oldPassword')} rules={[{ required: true }]}>
          <Input.Password autoComplete="current-password" />
        </Form.Item>
        <Form.Item name="new_password" label={t('newPassword')} rules={[{ required: true, min: 8 }]}>
          <Input.Password autoComplete="new-password" />
        </Form.Item>
        <Form.Item name="confirm" label={t('confirmPassword')} rules={[{ required: true }]}>
          <Input.Password autoComplete="new-password" />
        </Form.Item>
        <Button type="primary" htmlType="submit" loading={loading}>
          {t('submit')}
        </Button>
      </Form>
    </Card>
  )
}

function ResetSubscribe() {
  const { message } = AntdApp.useApp()
  const [loading, setLoading] = useState(false)

  const reset = async () => {
    setLoading(true)
    try {
      await api.resetSecurity()
      message.success(t('resetSubscribeDone'))
    } catch (error) {
      message.error(error instanceof Error ? error.message : String(error))
    } finally {
      setLoading(false)
    }
  }

  return (
    <Card title={t('resetSubscribe')} style={{ marginTop: 16 }}>
      <Space direction="vertical" size={12}>
        <Typography.Text type="secondary">{t('resetSubscribeHint')}</Typography.Text>
        <Popconfirm
          title={t('resetSubscribeConfirm')}
          okText={t('confirm')}
          cancelText={t('cancel')}
          onConfirm={reset}
        >
          <Button danger loading={loading}>
            {t('resetSubscribe')}
          </Button>
        </Popconfirm>
      </Space>
    </Card>
  )
}

export default function Account() {
  const request = useRequest(() => api.info())

  return (
    <>
      <Loadable {...request}>
        {(info) => (
          <Card title={t('accountTitle')}>
            <Descriptions column={{ xs: 1, md: 2 }} size="middle">
              <Descriptions.Item label={t('accountEmail')}>{info.email}</Descriptions.Item>
              <Descriptions.Item label={t('accountUuid')}>
                <span className="break-all">{info.uuid}</span>
              </Descriptions.Item>
              <Descriptions.Item label={t('accountCreatedAt')}>
                {formatDateTime(info.created_at)}
              </Descriptions.Item>
              <Descriptions.Item label={t('accountLastLogin')}>
                {formatDateTime(info.last_login_at)}
              </Descriptions.Item>
              <Descriptions.Item label={t('accountBalance')}>
                ¥{((info.balance ?? 0) / 100).toFixed(2)}
              </Descriptions.Item>
              <Descriptions.Item label={t('accountCommission')}>
                ¥{((info.commission_balance ?? 0) / 100).toFixed(2)}
              </Descriptions.Item>
            </Descriptions>
          </Card>
        )}
      </Loadable>
      <ChangePassword />
      <ResetSubscribe />
    </>
  )
}
