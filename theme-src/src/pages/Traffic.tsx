import { Card, Table, Tag, Typography, Button } from 'antd'
import { ReloadOutlined } from '@ant-design/icons'
import { api, type TrafficLog } from '../api'
import { useRequest } from '../hooks/useRequest'
import Loadable from '../components/Loadable'
import { formatBytes, formatDate } from '../utils/format'
import { t } from '../i18n'

export default function Traffic() {
  const request = useRequest(() => api.trafficLog())

  return (
    <Loadable
      {...request}
      empty={request.data?.length === 0}
      emptyText={t('trafficEmpty')}
    >
      {(logs) => (
        <Card
          title={t('trafficTitle')}
          extra={<Button size="small" icon={<ReloadOutlined />} onClick={request.reload} />}
        >
          <Table<TrafficLog>
            rowKey={(row) => String(row.record_at)}
            dataSource={logs}
            size="middle"
            scroll={{ x: 520 }}
            pagination={{ pageSize: 15, hideOnSinglePage: true }}
            columns={[
              {
                title: t('trafficDate'),
                dataIndex: 'record_at',
                width: 130,
                render: (value: number) => formatDate(value),
              },
              {
                title: t('trafficUpload'),
                dataIndex: 'u',
                render: (value: number) => formatBytes(value),
              },
              {
                title: t('trafficDownload'),
                dataIndex: 'd',
                render: (value: number) => formatBytes(value),
              },
              {
                title: t('trafficTotal'),
                key: 'total',
                render: (_, row) => <strong>{formatBytes((row.u ?? 0) + (row.d ?? 0))}</strong>,
              },
              {
                title: t('trafficRate'),
                dataIndex: 'server_rate',
                width: 90,
                render: (value: string) => <Tag>{value}x</Tag>,
              },
            ]}
          />
          <Typography.Text type="secondary" style={{ fontSize: 12 }}>
            {t('trafficHint')}
          </Typography.Text>
        </Card>
      )}
    </Loadable>
  )
}
