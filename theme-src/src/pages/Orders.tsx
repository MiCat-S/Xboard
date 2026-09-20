import { App as AntdApp, Button, Card, Popconfirm, Space, Table, Tag, Typography, theme } from 'antd'
import { ReloadOutlined } from '@ant-design/icons'
import { useNavigate } from 'react-router-dom'
import { api, type Order } from '../api'
import { useRequest } from '../hooks/useRequest'
import Loadable from '../components/Loadable'
import { formatMoney, isPayable, periodLabel, statusColor, statusLabel } from '../utils/order'
import { formatDateTime } from '../utils/format'
import { t } from '../i18n'

const { useToken } = theme

export default function Orders() {
  const { token } = useToken()
  const { message } = AntdApp.useApp()
  const navigate = useNavigate()
  const request = useRequest(() => api.orders())

  const cancel = async (tradeNo: string) => {
    try {
      await api.cancelOrder(tradeNo)
      message.success(t('orderCancelled'))
      request.reload()
    } catch (error) {
      message.error(error instanceof Error ? error.message : String(error))
    }
  }

  return (
    <Loadable {...request} empty={request.data?.length === 0} emptyText={t('ordersEmpty')}>
      {(orders) => (
        <Card
          title={t('navOrders')}
          extra={<Button size="small" icon={<ReloadOutlined />} onClick={request.reload} />}
        >
          <Table<Order>
            rowKey="trade_no"
            dataSource={orders}
            size="middle"
            scroll={{ x: 880 }}
            pagination={{ pageSize: 10, hideOnSinglePage: true }}
            columns={[
              {
                title: t('orderNo'),
                dataIndex: 'trade_no',
                width: 200,
                render: (value: string) => (
                  <Typography.Text
                    copyable={{ text: value }}
                    style={{ fontFamily: token.fontFamilyCode, fontSize: token.fontSizeSM }}
                  >
                    {value}
                  </Typography.Text>
                ),
              },
              {
                title: t('orderPlan'),
                key: 'plan',
                width: 160,
                render: (_, row) => (
                  <Space direction="vertical" size={0}>
                    <span>{row.plan?.name ?? '—'}</span>
                    <Typography.Text type="secondary" style={{ fontSize: token.fontSizeSM }}>
                      {periodLabel(row.period)}
                    </Typography.Text>
                  </Space>
                ),
              },
              {
                title: t('orderAmount'),
                key: 'amount',
                width: 120,
                render: (_, row) => (
                  <strong>{formatMoney((row.total_amount ?? 0) + (row.handling_amount ?? 0))}</strong>
                ),
              },
              {
                title: t('orderStatus'),
                dataIndex: 'status',
                width: 100,
                render: (status: Order['status']) => <Tag color={statusColor(status)}>{statusLabel(status)}</Tag>,
              },
              {
                title: t('orderCreatedAt'),
                dataIndex: 'created_at',
                width: 180,
                render: (value: number) => formatDateTime(value),
              },
              {
                title: t('orderActions'),
                key: 'actions',
                width: 160,
                render: (_, row) => (
                  <Space size={token.marginXS}>
                    <Button type="link" size="small" onClick={() => navigate(`/order/${row.trade_no}`)}>
                      {isPayable(row) ? t('orderPay') : t('orderDetail')}
                    </Button>
                    {isPayable(row) && (
                      <Popconfirm
                        title={t('orderCancelConfirm')}
                        okText={t('confirm')}
                        cancelText={t('cancel')}
                        onConfirm={() => cancel(row.trade_no)}
                      >
                        <Button type="link" size="small" danger>
                          {t('orderCancel')}
                        </Button>
                      </Popconfirm>
                    )}
                  </Space>
                ),
              },
            ]}
          />
        </Card>
      )}
    </Loadable>
  )
}
