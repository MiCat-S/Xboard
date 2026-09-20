import { useState } from 'react'
import {
  App as AntdApp, Badge, Button, Card, Form, Input, Modal, Popconfirm, Select, Space, Table, Tag, Typography, theme,
} from 'antd'
import { PlusOutlined, ReloadOutlined } from '@ant-design/icons'
import { api, type Ticket, type TicketLevel, type TicketMessage } from '../api'
import { useRequest } from '../hooks/useRequest'
import Loadable from '../components/Loadable'
import { formatDateTime } from '../utils/format'
import { t } from '../i18n'
import type { MessageKey } from '../i18n'

const { useToken } = theme

const LEVEL_LABEL: Record<TicketLevel, MessageKey> = {
  0: 'levelLow',
  1: 'levelMedium',
  2: 'levelHigh',
}

const LEVEL_COLOR: Record<TicketLevel, string> = {
  0: 'default',
  1: 'blue',
  2: 'red',
}

function Conversation({ ticket, onReplied }: { ticket: Ticket; onReplied: () => void }) {
  const { token } = useToken()
  const { message } = AntdApp.useApp()
  const [text, setText] = useState('')
  const [sending, setSending] = useState(false)

  const messages: TicketMessage[] = ticket.message ?? []
  const closed = ticket.status === 1

  const send = async () => {
    if (!text.trim()) return
    setSending(true)
    try {
      await api.replyTicket(ticket.id, text.trim())
      message.success(t('ticketReplySent'))
      setText('')
      onReplied()
    } catch (error) {
      message.error(error instanceof Error ? error.message : String(error))
    } finally {
      setSending(false)
    }
  }

  return (
    <Space direction="vertical" size={token.margin} style={{ width: '100%' }}>
      <Space direction="vertical" size={token.marginSM} style={{ width: '100%' }}>
        {messages.map((item) => {
          const mine = Boolean(item.is_me)
          return (
            <div
              key={item.id}
              style={{
                display: 'flex',
                flexDirection: 'column',
                alignItems: mine ? 'flex-end' : 'flex-start',
              }}
            >
              <Typography.Text type="secondary" style={{ fontSize: token.fontSizeSM }}>
                {mine ? t('ticketFromMe') : t('ticketFromStaff')} · {formatDateTime(item.created_at)}
              </Typography.Text>
              <div
                style={{
                  maxWidth: '85%',
                  marginTop: token.marginXXS,
                  padding: `${token.paddingXS}px ${token.paddingSM}px`,
                  borderRadius: token.borderRadiusLG,
                  background: mine ? token.colorPrimaryBg : token.colorFillTertiary,
                  whiteSpace: 'pre-wrap',
                  wordBreak: 'break-word',
                }}
              >
                {item.message}
              </div>
            </div>
          )
        })}
      </Space>

      {!closed && (
        <Space.Compact style={{ width: '100%' }}>
          <Input.TextArea
            value={text}
            onChange={(event) => setText(event.target.value)}
            placeholder={t('ticketReplyPlaceholder')}
            autoSize={{ minRows: 2, maxRows: 6 }}
          />
          <Button type="primary" onClick={send} loading={sending} disabled={!text.trim()}>
            {t('ticketReply')}
          </Button>
        </Space.Compact>
      )}
    </Space>
  )
}

export default function Tickets() {
  const { token } = useToken()
  const { message } = AntdApp.useApp()
  const list = useRequest(() => api.tickets())

  const [openId, setOpenId] = useState<number | null>(null)
  const [creating, setCreating] = useState(false)
  const [submitting, setSubmitting] = useState(false)
  const [form] = Form.useForm()

  // 详情要单独拉，列表接口不带对话内容（message 恒为 null）
  const detail = useRequest(
    () => (openId === null ? Promise.resolve(null) : api.ticket(openId)),
    [openId],
  )

  const create = async (values: { subject: string; level: TicketLevel; message: string }) => {
    setSubmitting(true)
    try {
      await api.createTicket(values.subject, values.level, values.message)
      message.success(t('ticketCreated'))
      form.resetFields()
      setCreating(false)
      list.reload()
    } catch (error) {
      message.error(error instanceof Error ? error.message : String(error))
    } finally {
      setSubmitting(false)
    }
  }

  const close = async (id: number) => {
    try {
      await api.closeTicket(id)
      message.success(t('ticketClosedDone'))
      setOpenId(null)
      list.reload()
    } catch (error) {
      message.error(error instanceof Error ? error.message : String(error))
    }
  }

  return (
    <>
      <Loadable {...list} empty={list.data?.length === 0} emptyText={t('ticketsEmpty')}>
        {(tickets) => (
          <Card
            title={t('navTickets')}
            extra={
              <Space size={token.marginXS}>
                <Button size="small" icon={<PlusOutlined />} onClick={() => setCreating(true)}>
                  {t('newTicket')}
                </Button>
                <Button size="small" icon={<ReloadOutlined />} onClick={list.reload} />
              </Space>
            }
          >
            <Table<Ticket>
              rowKey="id"
              dataSource={tickets}
              size="middle"
              scroll={{ x: 720 }}
              pagination={{ pageSize: 10, hideOnSinglePage: true }}
              columns={[
                { title: t('ticketSubject'), dataIndex: 'subject', width: 220 },
                {
                  title: t('ticketLevel'),
                  dataIndex: 'level',
                  width: 90,
                  render: (level: TicketLevel) => (
                    <Tag color={LEVEL_COLOR[level]}>{t(LEVEL_LABEL[level])}</Tag>
                  ),
                },
                {
                  title: t('orderStatus'),
                  key: 'status',
                  width: 130,
                  render: (_, row) =>
                    row.status === 1 ? (
                      <Badge status="default" text={t('ticketClosed')} />
                    ) : row.reply_status === 0 ? (
                      <Badge status="processing" text={t('ticketWaiting')} />
                    ) : (
                      <Badge status="success" text={t('ticketReplied')} />
                    ),
                },
                {
                  title: t('ticketUpdatedAt'),
                  dataIndex: 'updated_at',
                  width: 180,
                  render: (value: number) => formatDateTime(value),
                },
                {
                  title: t('orderActions'),
                  key: 'actions',
                  width: 90,
                  render: (_, row) => (
                    <Button type="link" size="small" onClick={() => setOpenId(row.id)}>
                      {t('ticketView')}
                    </Button>
                  ),
                },
              ]}
            />
          </Card>
        )}
      </Loadable>

      <Modal
        open={creating}
        title={t('newTicket')}
        onCancel={() => setCreating(false)}
        onOk={() => form.submit()}
        okText={t('submit')}
        cancelText={t('cancel')}
        confirmLoading={submitting}
      >
        <Form form={form} layout="vertical" onFinish={create} initialValues={{ level: 1 }} requiredMark={false}>
          <Form.Item name="subject" label={t('ticketSubject')} rules={[{ required: true }]}>
            <Input />
          </Form.Item>
          <Form.Item name="level" label={t('ticketLevel')} rules={[{ required: true }]}>
            <Select
              options={[
                { value: 0, label: t('levelLow') },
                { value: 1, label: t('levelMedium') },
                { value: 2, label: t('levelHigh') },
              ]}
            />
          </Form.Item>
          <Form.Item name="message" label={t('ticketMessage')} rules={[{ required: true }]}>
            <Input.TextArea autoSize={{ minRows: 4, maxRows: 10 }} />
          </Form.Item>
        </Form>
      </Modal>

      <Modal
        open={openId !== null}
        title={detail.data?.subject ?? t('ticketConversation')}
        onCancel={() => setOpenId(null)}
        width={640}
        footer={
          detail.data && detail.data.status !== 1 ? (
            <Popconfirm
              title={t('ticketCloseConfirm')}
              okText={t('confirm')}
              cancelText={t('cancel')}
              onConfirm={() => close(detail.data!.id)}
            >
              <Button danger>{t('ticketClose')}</Button>
            </Popconfirm>
          ) : null
        }
        destroyOnHidden
      >
        <Loadable {...detail}>
          {(ticket) =>
            ticket ? (
              <Conversation
                ticket={ticket}
                onReplied={() => {
                  detail.reload()
                  list.reload()
                }}
              />
            ) : null
          }
        </Loadable>
      </Modal>
    </>
  )
}
