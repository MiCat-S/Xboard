import { Card, Col, Row, Statistic, Progress, Typography, Button, Space, Tag, Input, App as AntdApp, theme } from 'antd'
import { CopyOutlined, EyeInvisibleOutlined, EyeOutlined, ReloadOutlined } from '@ant-design/icons'
import { useState } from 'react'
import { api, type Subscribe } from '../api'
import { useRequest } from '../hooks/useRequest'
import Loadable from '../components/Loadable'
import { copyText, expiryState, formatBytes, formatDate } from '../utils/format'
import { t } from '../i18n'

const { useToken } = theme

function SubscribeCard({ data, reload }: { data: Subscribe; reload: () => void }) {
  const { message } = AntdApp.useApp()
  const { token } = useToken()
  const [revealed, setRevealed] = useState(false)

  const used = (data.u ?? 0) + (data.d ?? 0)
  const total = data.transfer_enable ?? 0
  const left = Math.max(0, total - used)
  const percent = total > 0 ? Math.min(100, Math.round((used / total) * 100)) : 0
  const expiry = expiryState(data.expired_at)
  const hasPlan = Boolean(data.plan_id)

  const onCopy = async () => {
    const ok = await copyText(data.subscribe_url)
    if (ok) message.success(t('copied'))
    else message.error(t('copyFailed'))
  }

  return (
    <>
      <Card
        title={t('subscription')}
        extra={<Button size="small" icon={<ReloadOutlined />} onClick={reload} />}
        style={{ marginBottom: token.margin }}
      >
        <Space direction="vertical" size={token.margin} style={{ width: '100%' }}>
          <Space wrap size={token.marginXS}>
            <Typography.Text strong style={{ fontSize: token.fontSizeLG }}>
              {data.plan?.name ?? t('planNone')}
            </Typography.Text>
            {hasPlan && expiry.kind === 'never' && <Tag color="green">{t('neverExpire')}</Tag>}
            {hasPlan && expiry.kind === 'expired' && <Tag color="red">{t('expired')}</Tag>}
            {hasPlan && expiry.kind === 'active' && (
              <Tag color="blue">{t('daysLeft', { n: expiry.days })}</Tag>
            )}
            {!hasPlan && <Tag>{t('planNoneHint')}</Tag>}
            {data.reset_day !== null && data.reset_day !== undefined && (
              <Tag>{t('resetIn', { n: data.reset_day })}</Tag>
            )}
          </Space>

          <div>
            <Progress
              percent={percent}
              status={percent >= 100 ? 'exception' : 'normal'}
              format={(value) => `${value}%`}
            />
            <Row gutter={token.margin} style={{ marginTop: token.marginXS }}>
              <Col span={8}>
                <Statistic title={t('dataUsed')} value={formatBytes(used)} valueStyle={{ fontSize: token.fontSizeLG }} />
              </Col>
              <Col span={8}>
                <Statistic title={t('dataLeft')} value={formatBytes(left)} valueStyle={{ fontSize: token.fontSizeLG }} />
              </Col>
              <Col span={8}>
                <Statistic title={t('dataTotal')} value={formatBytes(total)} valueStyle={{ fontSize: token.fontSizeLG }} />
              </Col>
            </Row>
          </div>

          <Row gutter={token.margin}>
            <Col xs={12} md={8}>
              <Statistic
                title={t('expireAt')}
                value={
                  expiry.kind === 'never'
                    ? t('neverExpire')
                    : expiry.kind === 'expired'
                      ? t('expired')
                      : formatDate(data.expired_at)
                }
                valueStyle={{ fontSize: token.fontSize }}
              />
            </Col>
            <Col xs={12} md={8}>
              <Statistic
                title={t('deviceLimit')}
                value={data.device_limit ? String(data.device_limit) : t('unlimited')}
                valueStyle={{ fontSize: token.fontSize }}
              />
            </Col>
            <Col xs={12} md={8}>
              <Statistic
                title={t('speedLimit')}
                value={data.speed_limit ? `${data.speed_limit} Mbps` : t('unlimited')}
                valueStyle={{ fontSize: token.fontSize }}
              />
            </Col>
          </Row>
        </Space>
      </Card>

      <Card title={t('subscribeUrl')}>
        <Space.Compact style={{ width: '100%' }}>
          <Input
            readOnly
            value={revealed ? data.subscribe_url : data.subscribe_url.replace(/[^/]+$/, '••••••••')}
            className="break-all"
          />
          <Button
            icon={revealed ? <EyeInvisibleOutlined /> : <EyeOutlined />}
            onClick={() => setRevealed((value) => !value)}
          />
          <Button type="primary" icon={<CopyOutlined />} onClick={onCopy}>
            {t('copy')}
          </Button>
        </Space.Compact>
      </Card>
    </>
  )
}

export default function Dashboard() {
  const request = useRequest(() => api.subscribe())

  return (
    <Loadable {...request}>
      {(data) => <SubscribeCard data={data} reload={request.reload} />}
    </Loadable>
  )
}
