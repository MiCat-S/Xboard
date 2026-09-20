import { beforeEach, describe, expect, it, vi } from 'vitest'
import userEvent from '@testing-library/user-event'
import { api, type Ticket } from '../api'
import { renderPage, screen, waitFor, within } from '../test/render'
import Tickets from './Tickets'

const openTicket: Ticket = {
  id: 1,
  level: 2,
  reply_status: 1,
  status: 0,
  subject: '订阅无法连接',
  message: null,
  created_at: 1789948800,
  updated_at: 1789948900,
}

const closedTicket: Ticket = {
  id: 2,
  level: 0,
  reply_status: 1,
  status: 1,
  subject: '发票申请',
  message: null,
  created_at: 1789948000,
  updated_at: 1789948100,
}

const waitingTicket: Ticket = {
  id: 3,
  level: 1,
  reply_status: 0,
  status: 0,
  subject: '续费问题',
  message: null,
  created_at: 1789947000,
  updated_at: 1789947100,
}

/** 详情接口才带对话，列表里 message 恒为 null */
const withMessages = (ticket: Ticket): Ticket => ({
  ...ticket,
  message: [
    { id: 11, ticket_id: ticket.id, is_me: true, message: '香港节点连不上', created_at: 1789948800 },
    { id: 12, ticket_id: ticket.id, is_me: false, message: '正在维护，两小时后恢复', created_at: 1789948850 },
  ],
})

function openDialog() {
  return document.querySelector('.ant-modal-content') as HTMLElement
}

describe('Tickets', () => {
  beforeEach(() => {
    vi.restoreAllMocks()
    vi.spyOn(api, 'tickets').mockResolvedValue([openTicket, closedTicket, waitingTicket])
    vi.spyOn(api, 'ticket').mockImplementation(async (id) =>
      withMessages([openTicket, closedTicket, waitingTicket].find((t) => t.id === id)!),
    )
  })

  it('lists tickets with their priority', async () => {
    renderPage(<Tickets />)

    expect(await screen.findByText('订阅无法连接')).toBeInTheDocument()
    expect(screen.getByText('发票申请')).toBeInTheDocument()
    expect(screen.getByText('高')).toBeInTheDocument()
    expect(screen.getByText('低')).toBeInTheDocument()
    expect(screen.getByText('中')).toBeInTheDocument()
  })

  it('distinguishes closed, awaiting-reply and replied', async () => {
    renderPage(<Tickets />)

    await screen.findByText('订阅无法连接')

    expect(screen.getByText('已关闭')).toBeInTheDocument()
    expect(screen.getByText('等待回复')).toBeInTheDocument()
    expect(screen.getByText('已回复')).toBeInTheDocument()
  })

  /** 列表接口不带对话内容，打开时必须单独拉一次详情 */
  it('fetches the thread only when a ticket is opened', async () => {
    const detail = vi.spyOn(api, 'ticket')
    renderPage(<Tickets />)

    await screen.findByText('订阅无法连接')
    expect(detail).not.toHaveBeenCalled()

    await userEvent.click(screen.getAllByRole('button', { name: /查\s*看/ })[0])

    await waitFor(() => expect(detail).toHaveBeenCalledWith(1))
    expect(await screen.findByText('香港节点连不上')).toBeInTheDocument()
  })

  it('labels who wrote each message', async () => {
    renderPage(<Tickets />)

    await screen.findByText('订阅无法连接')
    await userEvent.click(screen.getAllByRole('button', { name: /查\s*看/ })[0])

    const dialog = await waitFor(openDialog)

    // is_me 为 true 的是用户自己发的
    expect(within(dialog).getByText(/^我 ·/)).toBeInTheDocument()
    expect(within(dialog).getByText(/^客服 ·/)).toBeInTheDocument()
  })

  it('posts a reply and refreshes both the thread and the list', async () => {
    const reply = vi.spyOn(api, 'replyTicket').mockResolvedValue(true)
    const list = vi.spyOn(api, 'tickets')
    const detail = vi.spyOn(api, 'ticket')

    renderPage(<Tickets />)
    await screen.findByText('订阅无法连接')
    await userEvent.click(screen.getAllByRole('button', { name: /查\s*看/ })[0])

    const dialog = await waitFor(openDialog)
    await userEvent.type(within(dialog).getByPlaceholderText('输入回复内容…'), '好的，谢谢')
    await userEvent.click(within(dialog).getByRole('button', { name: /回\s*复/ }))

    await waitFor(() => expect(reply).toHaveBeenCalledWith(1, '好的，谢谢'))
    // 回复后两边都要刷新，否则列表的「最后更新」和状态会是旧的
    await waitFor(() => expect(detail).toHaveBeenCalledTimes(2))
    await waitFor(() => expect(list).toHaveBeenCalledTimes(2))
  })

  it('will not send an empty reply', async () => {
    const reply = vi.spyOn(api, 'replyTicket').mockResolvedValue(true)

    renderPage(<Tickets />)
    await screen.findByText('订阅无法连接')
    await userEvent.click(screen.getAllByRole('button', { name: /查\s*看/ })[0])

    const dialog = await waitFor(openDialog)
    expect(within(dialog).getByRole('button', { name: /回\s*复/ })).toBeDisabled()
    expect(reply).not.toHaveBeenCalled()
  })

  it('offers neither reply box nor close button on a closed ticket', async () => {
    renderPage(<Tickets />)
    await screen.findByText('发票申请')

    // 第二行是已关闭的那张
    await userEvent.click(screen.getAllByRole('button', { name: /查\s*看/ })[1])

    const dialog = await waitFor(openDialog)
    await within(dialog).findByText(/香港节点连不上/)

    expect(within(dialog).queryByPlaceholderText('输入回复内容…')).not.toBeInTheDocument()
    expect(screen.queryByRole('button', { name: /关闭工单/ })).not.toBeInTheDocument()
  })

  it('closes a ticket after confirmation', async () => {
    const close = vi.spyOn(api, 'closeTicket').mockResolvedValue(true)

    renderPage(<Tickets />)
    await screen.findByText('订阅无法连接')
    await userEvent.click(screen.getAllByRole('button', { name: /查\s*看/ })[0])

    await waitFor(openDialog)
    await userEvent.click(await screen.findByRole('button', { name: /关闭工单/ }))
    await userEvent.click(await screen.findByRole('button', { name: /确\s*定/ }))

    await waitFor(() => expect(close).toHaveBeenCalledWith(1))
  })

  it('creates a ticket with the chosen priority', async () => {
    const create = vi.spyOn(api, 'createTicket').mockResolvedValue(true)

    renderPage(<Tickets />)
    await screen.findByText('订阅无法连接')
    await userEvent.click(screen.getByRole('button', { name: /新建工单/ }))

    const dialog = await waitFor(openDialog)
    await userEvent.type(within(dialog).getByRole('textbox', { name: '主题' }), '能否新增设备')
    await userEvent.type(within(dialog).getByRole('textbox', { name: '内容' }), '想再加一台设备')
    await userEvent.click(within(dialog).getByRole('button', { name: /提\s*交/ }))

    // 优先级默认「中」= 1
    await waitFor(() => expect(create).toHaveBeenCalledWith('能否新增设备', 1, '想再加一台设备'))
  })

  /**
   * 后端不允许同时存在多个未关闭工单，新建和提现都会被这条规则挡住。
   * 前端如实透出，不要自己吞掉。
   */
  it('surfaces the backend refusal when another ticket is still open', async () => {
    vi.spyOn(api, 'createTicket').mockRejectedValue(new Error('存在未关闭的工单'))

    renderPage(<Tickets />)
    await screen.findByText('订阅无法连接')
    await userEvent.click(screen.getByRole('button', { name: /新建工单/ }))

    const dialog = await waitFor(openDialog)
    await userEvent.type(within(dialog).getByRole('textbox', { name: '主题' }), 'x')
    await userEvent.type(within(dialog).getByRole('textbox', { name: '内容' }), 'y')
    await userEvent.click(within(dialog).getByRole('button', { name: /提\s*交/ }))

    expect(await screen.findByText('存在未关闭的工单')).toBeInTheDocument()
  })

  it('reports the empty state', async () => {
    vi.spyOn(api, 'tickets').mockResolvedValue([])

    renderPage(<Tickets />)

    expect(await screen.findByText('还没有工单')).toBeInTheDocument()
  })
})
