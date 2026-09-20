import { useState } from 'react'
import {
  App as AntdApp, Alert, Button, Card, Col, Form, Input, Modal, Row, Select, Space, Statistic, Table, Typography, theme,
} from 'antd'
import { CopyOutlined, PlusOutlined, ReloadOutlined } from '@ant-design/icons'
import { api, type CommissionLog, type InviteCode } from '../api'
import { useRequest } from '../hooks/useRequest'
import Loadable from '../components/Loadable'
import { formatMoney } from '../utils/order'
import { copyText, formatDateTime } from '../utils/format'
import { t } from '../i18n'

const { useToken } = theme

/** 邀请链接指向站点首页并带上邀请码，注册页会读它 */
function inviteLinkOf(code: string): string {
  return `${window.location.origin}/#/register?code=${code}`
}

function WithdrawModal({
  open, methods, onClose,
}: {
  open: boolean
  methods: string[]
  onClose: () => void
}) {
  const { message } = AntdApp.useApp()
  const [form] = Form.useForm()
  const [submitting, setSubmitting] = useState(false)

  const submit = async (values: { withdraw_method: string; withdraw_account: string }) => {
    setSubmitting(true)
    try {
      await api.withdraw(values.withdraw_method, values.withdraw_account)
      message.success(t('withdrawSubmitted'))
      form.resetFields()
      onClose()
    } catch (error) {
      message.error(error instanceof Error ? error.message : String(error))
    } finally {
      setSubmitting(false)
    }
  }

  return (
    <Modal
      open={open}
      title={t('withdraw')}
      onCancel={onClose}
      onOk={() => form.submit()}
      okText={t('submit')}
      cancelText={t('cancel')}
      confirmLoading={submitting}
      destroyOnHidden
    >
      <Form form={form} layout="vertical" onFinish={submit} requiredMark={false}>
        <Form.Item name="withdraw_method" label={t('withdrawMethod')} rules={[{ required: true }]}>
          <Select options={methods.map((item) => ({ value: item, label: item }))} />
        </Form.Item>
        <Form.Item name="withdraw_account" label={t('withdrawAccount')} rules={[{ required: true }]}>
          <Input />
        </Form.Item>
        <Typography.Text type="secondary">{t('withdrawHint')}</Typography.Text>
      </Form>
    </Modal>
  )
}

export default function Invite() {
  const { token } = useToken()
  const { message } = AntdApp.useApp()

  const invites = useRequest(() => api.invites())
  const config = useRequest(() => api.userConfig())
  const [page, setPage] = useState(1)
  const logs = useRequest(() => api.commissionLogs(page, 10), [page])

  const [generating, setGenerating] = useState(false)
  const [withdrawing, setWithdrawing] = useState(false)

  const generate = async () => {
    setGenerating(true)
    try {
      await api.createInviteCode()
      message.success(t('inviteGenerated'))
      invites.reload()
    } catch (error) {
      message.error(error instanceof Error ? error.message : String(error))
    } finally {
      setGenerating(false)
    }
  }

  const copy = async (text: string) => {
    const ok = await copyText(text)
    if (ok) message.success(t('copied'))
    else message.error(t('copyFailed'))
  }

  const withdrawClosed = config.data?.withdraw_close === 1
  const withdrawMethods = config.data?.withdraw_methods ?? []

  return (
    <Space direction="vertical" size={token.margin} style={{ width: '100%' }}>
      <Loadable {...invites}>
        {(data) => {
          // stat 是定长数组：[注册数, 已确认佣金, 确认中佣金, 佣金比例%, 可用佣金]
          const [registered, confirmed, pending, rate, available] = data.stat

          return (
            <>
              <Card>
                <Row gutter={[token.margin, token.margin]}>
                  <Col xs={12} md={8} xl={4}>
                    <Statistic title={t('statRegistered')} value={registered} />
                  </Col>
                  <Col xs={12} md={8} xl={5}>
                    <Statistic title={t('statConfirmed')} value={formatMoney(confirmed)} />
                  </Col>
                  <Col xs={12} md={8} xl={5}>
                    <Statistic title={t('statPending')} value={formatMoney(pending)} />
                  </Col>
                  <Col xs={12} md={8} xl={4}>
                    <Statistic title={t('statRate')} value={`${rate}%`} />
                  </Col>
                  <Col xs={24} md={8} xl={6}>
                    <Statistic
                      title={t('statAvailable')}
                      value={formatMoney(available)}
                      valueStyle={{ color: token.colorSuccess }}
                    />
                    <Button
                      type="primary"
                      size="small"
                      style={{ marginTop: token.marginXS }}
                      disabled={withdrawClosed || available <= 0}
                      onClick={() => setWithdrawing(true)}
                    >
                      {t('withdraw')}
                    </Button>
                  </Col>
                </Row>
                {withdrawClosed && (
                  <Alert
                    type="info"
                    showIcon
                    message={t('withdrawClosed')}
                    style={{ marginTop: token.margin }}
                  />
                )}
              </Card>

              <Card
                title={t('inviteCodes')}
                extra={
                  <Space size={token.marginXS}>
                    <Button size="small" icon={<PlusOutlined />} loading={generating} onClick={generate}>
                      {t('inviteGenerate')}
                    </Button>
                    <Button size="small" icon={<ReloadOutlined />} onClick={invites.reload} />
                  </Space>
                }
              >
                <Table<InviteCode>
                  rowKey="code"
                  dataSource={data.codes}
                  size="middle"
                  scroll={{ x: 620 }}
                  pagination={false}
                  locale={{ emptyText: t('inviteCodesEmpty') }}
                  columns={[
                    {
                      title: t('inviteCodeCol'),
                      dataIndex: 'code',
                      width: 140,
                      render: (code: string) => (
                        <Typography.Text style={{ fontFamily: token.fontFamilyCode }}>{code}</Typography.Text>
                      ),
                    },
                    {
                      title: t('inviteLink'),
                      key: 'link',
                      render: (_, row) => (
                        <Space.Compact style={{ width: '100%' }}>
                          <Input readOnly value={inviteLinkOf(row.code)} size="small" />
                          <Button size="small" icon={<CopyOutlined />} onClick={() => copy(inviteLinkOf(row.code))} />
                        </Space.Compact>
                      ),
                    },
                    { title: t('invitePv'), dataIndex: 'pv', width: 90 },
                    {
                      title: t('ticketCreatedAt'),
                      dataIndex: 'created_at',
                      width: 170,
                      render: (value: number) => formatDateTime(value),
                    },
                  ]}
                />
              </Card>
            </>
          )
        }}
      </Loadable>

      <Card title={t('commissionLogs')}>
        <Loadable {...logs}>
          {(result) => (
            <Table<CommissionLog>
              rowKey="id"
              dataSource={result.data}
              size="middle"
              scroll={{ x: 560 }}
              locale={{ emptyText: t('commissionLogsEmpty') }}
              pagination={{
                current: page,
                pageSize: 10,
                total: result.total,
                onChange: setPage,
                hideOnSinglePage: true,
              }}
              columns={[
                {
                  title: t('commissionOrder'),
                  dataIndex: 'trade_no',
                  render: (value: string) => (
                    <Typography.Text style={{ fontFamily: token.fontFamilyCode, fontSize: token.fontSizeSM }}>
                      {value}
                    </Typography.Text>
                  ),
                },
                {
                  title: t('commissionOrderAmount'),
                  dataIndex: 'order_amount',
                  width: 130,
                  render: (value: number) => formatMoney(value),
                },
                {
                  title: t('commissionGet'),
                  dataIndex: 'get_amount',
                  width: 130,
                  render: (value: number) => <strong>{formatMoney(value)}</strong>,
                },
                {
                  title: t('ticketCreatedAt'),
                  dataIndex: 'created_at',
                  width: 170,
                  render: (value: number) => formatDateTime(value),
                },
              ]}
            />
          )}
        </Loadable>
      </Card>

      <WithdrawModal
        open={withdrawing}
        methods={withdrawMethods}
        onClose={() => {
          setWithdrawing(false)
          invites.reload()
        }}
      />
    </Space>
  )
}
