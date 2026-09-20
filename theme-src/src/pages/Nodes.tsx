import { Badge, Card, Table, Tag, Typography, Button, Space } from 'antd'
import { ReloadOutlined } from '@ant-design/icons'
import { api, type Node } from '../api'
import { useRequest } from '../hooks/useRequest'
import Loadable from '../components/Loadable'
import { t } from '../i18n'

export default function Nodes() {
  const request = useRequest(() => api.nodes())

  return (
    <Loadable
      {...request}
      empty={request.data?.length === 0}
      emptyText={t('nodesEmpty')}
    >
      {(nodes) => (
        <Card
          title={t('navNodes')}
          extra={<Button size="small" icon={<ReloadOutlined />} onClick={request.reload} />}
        >
          <Table<Node>
            rowKey="id"
            dataSource={nodes}
            pagination={false}
            size="middle"
            scroll={{ x: 520 }}
            columns={[
              {
                title: t('nodeStatus'),
                dataIndex: 'is_online',
                width: 90,
                render: (value: number | boolean) =>
                  value ? (
                    <Badge status="success" text={t('online')} />
                  ) : (
                    <Badge status="default" text={t('offline')} />
                  ),
              },
              { title: t('nodeName'), dataIndex: 'name', render: (value: string) => <span>{value}</span> },
              {
                title: t('nodeRate'),
                dataIndex: 'rate',
                width: 90,
                render: (value: string) => <Tag color={Number(value) > 1 ? 'orange' : 'default'}>{value}x</Tag>,
              },
              {
                title: t('nodeTags'),
                dataIndex: 'tags',
                render: (tags: string[] | null) => (
                  <Space size={4} wrap>
                    {(tags ?? []).map((tag) => (
                      <Tag key={tag}>{tag}</Tag>
                    ))}
                  </Space>
                ),
              },
            ]}
          />
          <Typography.Text type="secondary" style={{ fontSize: 12 }}>
            {t('nodesHint')}
          </Typography.Text>
        </Card>
      )}
    </Loadable>
  )
}
