import { Alert, Badge, Button, Card, List, Space, Tag, Typography } from 'antd'
import { ReloadOutlined } from '@ant-design/icons'
import { useEffect } from 'react'
import { api, type Device, type OnlineDevices } from '../api'
import { useRequest } from '../hooks/useRequest'
import Loadable from '../components/Loadable'
import { formatDateTime } from '../utils/format'
import { t } from '../i18n'

function DeviceRow({ device }: { device: Device }) {
  return (
    <List.Item>
      <List.Item.Meta
        avatar={<Badge status={device.is_current_ip ? 'success' : 'warning'} style={{ marginTop: 8 }} />}
        title={
          <Space size={8} wrap>
            <Typography.Text strong style={{ fontFamily: 'ui-monospace, Menlo, monospace' }}>
              {device.ip}
            </Typography.Text>
            {device.is_current_ip ? (
              <Tag color="success">{t('thisDevice')}</Tag>
            ) : (
              <Tag color="warning">?</Tag>
            )}
          </Space>
        }
        description={
          <Typography.Text type="secondary" style={{ fontSize: 13 }}>
            {device.region || t('unknownRegion')}
            {' · '}
            {device.nodes.length ? device.nodes.join(' / ') : t('unknownNode')}
            {' · '}
            {t('lastSeen')} {formatDateTime(device.last_seen_at)}
          </Typography.Text>
        }
      />
    </List.Item>
  )
}

export default function Devices() {
  const request = useRequest(() => api.onlineDevices())

  // 设备状态在 Redis 里只存 5 分钟，页面停留时定期刷新才有意义
  useEffect(() => {
    const timer = window.setInterval(() => request.reload(), 60000)
    return () => window.clearInterval(timer)
  }, [request.reload])

  const summary = (data: OnlineDevices) => {
    const limit = data.device_limit ? String(data.device_limit) : t('unlimited')
    return `${t('inUse', { n: data.online_count })} · ${t('deviceLimit')} ${limit}`
  }

  return (
    <Loadable {...request}>
      {(data) => (
        <>
          <Alert
            type="info"
            showIcon
            message={t('devicesTitle')}
            description={t('devicesHint')}
            style={{ marginBottom: 16 }}
          />
          <Card
            title={summary(data)}
            extra={<Button size="small" icon={<ReloadOutlined />} onClick={request.reload} />}
          >
            <List
              dataSource={data.devices}
              locale={{ emptyText: t('devicesEmpty') }}
              renderItem={(device) => <DeviceRow key={device.ip} device={device} />}
            />
            <Typography.Text type="secondary" style={{ fontSize: 12 }}>
              {t('devicesFooter')}
            </Typography.Text>
          </Card>
        </>
      )}
    </Loadable>
  )
}
