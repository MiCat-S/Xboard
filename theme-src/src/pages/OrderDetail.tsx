import { useCallback, useEffect, useRef, useState } from 'react'
import {
  App as AntdApp, Button, Card, Descriptions, QRCode, Radio, Result, Space, Spin, Tag, Typography, theme,
} from 'antd'
import { useNavigate, useParams } from 'react-router-dom'
import { api, type Order } from '../api'
import { useRequest } from '../hooks/useRequest'
import Loadable from '../components/Loadable'
import { formatMoney, handlingFeeOf, isPayable, periodLabel, statusColor, statusLabel } from '../utils/order'
import { formatDateTime } from '../utils/format'
import { t } from '../i18n'

const { useToken } = theme

/**
 * 金额明细。必须返回数组交给 Descriptions 的 items —— 把 Descriptions.Item
 * 包在自定义组件里，antd 不会穿透 Fragment 去收集子项，那几行会直接不显示。
 */
function amountItems(order: Order) {
  return [
    { key: 'discount', label: t('amountDiscount'), value: order.discount_amount, sign: '-' },
    { key: 'surplus', label: t('amountSurplus'), value: order.surplus_amount, sign: '-' },
    { key: 'balance', label: t('amountBalance'), value: order.balance_amount, sign: '-' },
    { key: 'handling', label: t('amountHandling'), value: order.handling_amount, sign: '+' },
  ]
    .filter((row) => (row.value ?? 0) > 0)
    .map((row) => ({
      key: row.key,
      label: row.label,
      children: `${row.sign}${formatMoney(row.value)}`,
    }))
}

export default function OrderDetail() {
  const { tradeNo = '' } = useParams()
  const { token } = useToken()
  const { message } = AntdApp.useApp()
  const navigate = useNavigate()

  const order = useRequest(() => api.orderDetail(tradeNo), [tradeNo])
  const methods = useRequest(() => api.paymentMethods())

  const [method, setMethod] = useState<number | undefined>()
  const [paying, setPaying] = useState(false)
  const [qr, setQr] = useState<string | null>(null)
  const [done, setDone] = useState(false)
  const pollTimer = useRef<number | undefined>(undefined)

  useEffect(() => () => window.clearInterval(pollTimer.current), [])

  useEffect(() => {
    if (method === undefined && methods.data?.length) setMethod(methods.data[0].id)
  }, [methods.data, method])

  // 跳转支付或扫码支付都不会回调前端，只能轮询订单状态
  const startPolling = useCallback(() => {
    window.clearInterval(pollTimer.current)
    pollTimer.current = window.setInterval(async () => {
      try {
        const status = await api.orderStatus(tradeNo)
        if (status !== 0) {
          window.clearInterval(pollTimer.current)
          setQr(null)
          setDone(true)
        }
      } catch {
        // 轮询失败不打断用户，下一轮再试
      }
    }, 3000)
  }, [tradeNo])

  const selectedMethod = methods.data?.find((item) => item.id === method)

  /**
   * 详情页里的 handling_amount 只有支付过一次才有值，所以未支付时按当前
   * 选中的支付方式现算，让页面上的数字和真正扣的钱一致。
   */
  const payableAmount = (data: Order) => {
    const base = data.total_amount ?? 0
    const fee = data.handling_amount ?? handlingFeeOf(base, selectedMethod)
    return base + fee
  }

  const pay = async () => {
    if (method === undefined) return
    setPaying(true)
    try {
      const result = await api.checkout(tradeNo, method)

      if (result.type === -1) {
        setDone(true)
        message.success(t('freeOrderDone'))
        return
      }

      if (result.type === 0 && typeof result.data === 'string') {
        setQr(result.data)
        startPolling()
        return
      }

      if (result.type === 1 && typeof result.data === 'string') {
        message.info(t('payRedirecting'))
        startPolling()
        window.location.href = result.data
        return
      }

      message.error(t('loadFailed'))
    } catch (error) {
      message.error(error instanceof Error ? error.message : String(error))
    } finally {
      setPaying(false)
    }
  }

  if (done) {
    return (
      <Result
        status="success"
        title={t('paySuccess')}
        extra={[
          <Button type="primary" key="dashboard" onClick={() => navigate('/dashboard')}>
            {t('viewDashboard')}
          </Button>,
          <Button key="orders" onClick={() => navigate('/orders')}>
            {t('backToOrders')}
          </Button>,
        ]}
      />
    )
  }

  return (
    <Loadable {...order}>
      {(data) => (
        <Space direction="vertical" size={token.margin} style={{ width: '100%' }}>
          <Card title={t('orderDetail')}>
            <Descriptions
              column={{ xs: 1, md: 2 }}
              size="middle"
              items={[
                {
                  key: 'trade_no',
                  label: t('orderNo'),
                  children: (
                    <span className="break-all" style={{ fontFamily: token.fontFamilyCode }}>
                      {data.trade_no}
                    </span>
                  ),
                },
                {
                  key: 'status',
                  label: t('orderStatus'),
                  children: <Tag color={statusColor(data.status)}>{statusLabel(data.status)}</Tag>,
                },
                { key: 'plan', label: t('orderPlan'), children: data.plan?.name ?? '—' },
                { key: 'period', label: t('selectedPeriod'), children: periodLabel(data.period) },
                { key: 'created', label: t('orderCreatedAt'), children: formatDateTime(data.created_at) },
                ...amountItems(data),
                {
                  key: 'total',
                  label: t('amountTotal'),
                  children: (
                    <Typography.Text strong style={{ fontSize: token.fontSizeLG }}>
                      {formatMoney(payableAmount(data))}
                    </Typography.Text>
                  ),
                },
              ]}
            />
          </Card>

          {!isPayable(data) ? (
            <Card>
              <Typography.Text type="secondary">{t('orderNotPayable')}</Typography.Text>
            </Card>
          ) : qr ? (
            <Card title={t('payMethod')}>
              <Space direction="vertical" align="center" size={token.margin} style={{ width: '100%' }}>
                <QRCode value={qr} size={200} />
                <Typography.Text type="secondary">{t('payScanQr')}</Typography.Text>
                <Space size={token.marginXS}>
                  <Spin size="small" />
                  <Typography.Text type="secondary">{t('payWaiting')}</Typography.Text>
                </Space>
              </Space>
            </Card>
          ) : (
            <Loadable
              {...methods}
              empty={methods.data?.length === 0}
              emptyText={t('payMethodsEmpty')}
            >
              {(list) => (
                <Card title={t('payMethod')}>
                  <Space direction="vertical" size={token.margin} style={{ width: '100%' }}>
                    <Radio.Group
                      value={method}
                      onChange={(event) => setMethod(event.target.value)}
                      style={{ display: 'flex', flexDirection: 'column', gap: token.marginXS }}
                    >
                      {list.map((item) => (
                        <Radio key={item.id} value={item.id}>
                          {item.name}
                          {(item.handling_fee_percent || item.handling_fee_fixed) && (
                            <Typography.Text type="secondary" style={{ fontSize: token.fontSizeSM }}>
                              {'　'}
                              {t('amountHandling')}
                              {item.handling_fee_percent ? ` ${item.handling_fee_percent}%` : ''}
                              {item.handling_fee_fixed ? ` +${formatMoney(item.handling_fee_fixed)}` : ''}
                            </Typography.Text>
                          )}
                        </Radio>
                      ))}
                    </Radio.Group>

                    <Button type="primary" size="large" loading={paying} onClick={pay} disabled={method === undefined}>
                      {t('payNow')} {formatMoney(payableAmount(data))}
                    </Button>
                  </Space>
                </Card>
              )}
            </Loadable>
          )}
        </Space>
      )}
    </Loadable>
  )
}
