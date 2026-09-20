import { useState } from 'react'
import {
  App as AntdApp, Button, Card, Col, Input, Modal, Row, Segmented, Space, Tag, Typography, theme,
} from 'antd'
import { useNavigate } from 'react-router-dom'
import DOMPurify from 'dompurify'
import { api, PERIODS, type Period, type PlanDetail } from '../api'
import { useRequest } from '../hooks/useRequest'
import Loadable from '../components/Loadable'
import { availablePeriods, formatMoney, periodLabel, planPrice } from '../utils/order'
import { formatBytes } from '../utils/format'
import { t } from '../i18n'

const { useToken } = theme

/**
 * 套餐介绍是管理员在后台填的富文本，必须渲染成 HTML 才不会乱。
 * 过一遍 DOMPurify，避免后台内容变成打向用户的 XSS。
 */
function PlanContent({ html }: { html: string }) {
  if (!html) return null

  return (
    <div
      className="plan-content"
      dangerouslySetInnerHTML={{ __html: DOMPurify.sanitize(html, { USE_PROFILES: { html: true } }) }}
    />
  )
}

function PlanCard({ plan, onBuy }: { plan: PlanDetail; onBuy: (plan: PlanDetail, period: Period) => void }) {
  const { token } = useToken()
  const periods = availablePeriods(plan, PERIODS)
  const [period, setPeriod] = useState<Period | undefined>(periods[0])

  const soldOut = plan.capacity_limit !== null && typeof plan.capacity_limit === 'string'
  const price = period ? planPrice(plan, period) : null

  return (
    <Card
      title={plan.name}
      extra={
        <Space size={token.marginXS}>
          {(plan.tags ?? []).map((tag) => (
            <Tag key={tag}>{tag}</Tag>
          ))}
        </Space>
      }
      style={{ height: '100%' }}
    >
      <Space direction="vertical" size={token.margin} style={{ width: '100%' }}>
        <Space size={token.marginXS} wrap>
          <Tag>{formatBytes(plan.transfer_enable * 1024 ** 3)}</Tag>
          {plan.device_limit ? <Tag>{t('deviceLimit')} {plan.device_limit}</Tag> : null}
          {plan.speed_limit ? <Tag>{plan.speed_limit} Mbps</Tag> : null}
          {typeof plan.capacity_limit === 'number' && (
            <Tag color="orange">{t('capacityLeft', { n: plan.capacity_limit })}</Tag>
          )}
        </Space>

        <PlanContent html={plan.content} />

        {periods.length > 1 && (
          <Segmented
            block
            value={period}
            onChange={(value) => setPeriod(value as Period)}
            options={periods.map((item) => ({ label: periodLabel(item), value: item }))}
          />
        )}

        <Space align="baseline" size={token.marginXS}>
          <Typography.Text strong style={{ fontSize: token.fontSizeHeading3 }}>
            {price === null ? '—' : formatMoney(price)}
          </Typography.Text>
          {period && <Typography.Text type="secondary">{t('perPeriod', { period: periodLabel(period) })}</Typography.Text>}
        </Space>

        <Button
          type="primary"
          block
          size="large"
          disabled={soldOut || !period || !plan.sell}
          onClick={() => period && onBuy(plan, period)}
        >
          {soldOut ? t('soldOut') : !plan.sell ? t('renewOnly') : t('buy')}
        </Button>
      </Space>
    </Card>
  )
}

export default function Plans() {
  const { token } = useToken()
  const { message } = AntdApp.useApp()
  const navigate = useNavigate()
  const request = useRequest(() => api.plans())

  const [target, setTarget] = useState<{ plan: PlanDetail; period: Period } | null>(null)
  const [coupon, setCoupon] = useState('')
  const [couponOk, setCouponOk] = useState<string | null>(null)
  const [checking, setChecking] = useState(false)
  const [creating, setCreating] = useState(false)

  const openBuy = (plan: PlanDetail, period: Period) => {
    setTarget({ plan, period })
    setCoupon('')
    setCouponOk(null)
  }

  const verifyCoupon = async () => {
    if (!target || !coupon) return
    setChecking(true)
    try {
      const result = await api.checkCoupon(coupon, target.plan.id, target.period)
      setCouponOk(result?.name ?? coupon)
      message.success(t('couponApplied', { name: result?.name ?? coupon }))
    } catch (error) {
      setCouponOk(null)
      message.error(error instanceof Error ? error.message : String(error))
    } finally {
      setChecking(false)
    }
  }

  const submit = async () => {
    if (!target) return
    setCreating(true)
    try {
      // 优惠码是在下单时生效的，不是支付时
      const tradeNo = await api.createOrder(target.plan.id, target.period, coupon || undefined)
      message.success(t('orderCreated'))
      setTarget(null)
      navigate(`/order/${tradeNo}`)
    } catch (error) {
      message.error(error instanceof Error ? error.message : String(error))
    } finally {
      setCreating(false)
    }
  }

  return (
    <>
      <Loadable {...request} empty={request.data?.length === 0} emptyText={t('plansEmpty')}>
        {(plans) => (
          <Row gutter={[token.margin, token.margin]}>
            {plans.map((plan) => (
              <Col key={plan.id} xs={24} md={12} xl={8}>
                <PlanCard plan={plan} onBuy={openBuy} />
              </Col>
            ))}
          </Row>
        )}
      </Loadable>

      <Modal
        open={target !== null}
        title={t('confirmOrder')}
        onCancel={() => setTarget(null)}
        onOk={submit}
        okText={t('createOrder')}
        cancelText={t('cancel')}
        confirmLoading={creating}
      >
        {target && (
          <Space direction="vertical" size={token.margin} style={{ width: '100%' }}>
            <Typography.Text>
              {t('selectedPlan')}：<strong>{target.plan.name}</strong>
            </Typography.Text>
            <Typography.Text>
              {t('selectedPeriod')}：<strong>{periodLabel(target.period)}</strong>
              {'　'}
              <strong>{formatMoney(planPrice(target.plan, target.period))}</strong>
            </Typography.Text>

            <div>
              <Typography.Text type="secondary">{t('couponOptional')}</Typography.Text>
              <Space.Compact style={{ width: '100%', marginTop: token.marginXXS }}>
                <Input
                  value={coupon}
                  onChange={(event) => {
                    setCoupon(event.target.value)
                    setCouponOk(null)
                  }}
                  placeholder={t('couponCode')}
                />
                <Button onClick={verifyCoupon} loading={checking} disabled={!coupon}>
                  {t('applyCoupon')}
                </Button>
              </Space.Compact>
              {couponOk && (
                <Typography.Text type="success" style={{ fontSize: token.fontSizeSM }}>
                  {t('couponApplied', { name: couponOk })}
                </Typography.Text>
              )}
            </div>
          </Space>
        )}
      </Modal>
    </>
  )
}
