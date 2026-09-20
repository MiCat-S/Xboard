import { beforeEach, describe, expect, it, vi } from 'vitest'
import userEvent from '@testing-library/user-event'
import { api, type KnowledgeItem, type PlanDetail } from '../api'
import { renderPage, screen, waitFor } from '../test/render'
import Knowledge from './Knowledge'
import Plans from './Plans'

/**
 * 套餐介绍和知识库正文都是后台填的富文本，必须按 HTML 渲染，
 * 所以插入前一定要过 DOMPurify。这几条守住这个前提。
 */

const HOSTILE = `
  <p>正常内容</p>
  <script>window.__pwned = true</script>
  <img src=x onerror="window.__pwned = true">
  <a href="javascript:window.__pwned=true">点我</a>
  <iframe src="https://evil.example.com"></iframe>
`

declare global {
  interface Window {
    __pwned?: boolean
  }
}

function expectSanitized(container: HTMLElement) {
  expect(window.__pwned).toBeUndefined()
  expect(container.querySelector('script')).toBeNull()
  expect(container.querySelector('iframe')).toBeNull()
  expect(container.querySelector('[onerror]')).toBeNull()
  expect(container.innerHTML).not.toContain('javascript:')
  // 正常内容仍然保留
  expect(container.textContent).toContain('正常内容')
}

describe('plan description sanitization', () => {
  beforeEach(() => {
    vi.restoreAllMocks()
    delete window.__pwned
  })

  it('strips scripts and event handlers from plan content', async () => {
    const plan = {
      id: 1, name: '套餐', content: HOSTILE, tags: null,
      transfer_enable: 100, speed_limit: null, device_limit: 3, capacity_limit: null,
      sell: true, renew: true,
      month_price: 1000, quarter_price: null, half_year_price: null, year_price: null,
      two_year_price: null, three_year_price: null, onetime_price: null, reset_price: null,
    } satisfies PlanDetail

    vi.spyOn(api, 'plans').mockResolvedValue([plan])

    const { container } = renderPage(<Plans />)
    await screen.findByText('套餐')

    expectSanitized(container)
  })
})

describe('knowledge article sanitization', () => {
  beforeEach(() => {
    vi.restoreAllMocks()
    delete window.__pwned
  })

  it('strips scripts and event handlers from article bodies', async () => {
    const item: KnowledgeItem = { id: 1, category: '教程', title: '配置说明', updated_at: 1789948800 }

    vi.spyOn(api, 'knowledge').mockResolvedValue({ 教程: [item] })
    vi.spyOn(api, 'knowledgeArticle').mockResolvedValue({ ...item, body: HOSTILE })

    renderPage(<Knowledge />)
    await userEvent.click(await screen.findByText('配置说明'))

    // 正文渲染在 Modal 里，Modal 挂在 body 上而不是 container 内
    await waitFor(() => expect(document.querySelector('.knowledge-body')).toBeTruthy())
    expectSanitized(document.body)
  })

  it('does not leak categories that the backend did not send', async () => {
    vi.spyOn(api, 'knowledge').mockResolvedValue({ 教程: [] })

    renderPage(<Knowledge />)

    await screen.findByText(/教程/)
    expect(screen.queryByText(/内部/)).not.toBeInTheDocument()
  })

  it('shows a hint when nothing is published for the current language', async () => {
    vi.spyOn(api, 'knowledge').mockResolvedValue({})

    renderPage(<Knowledge />)

    expect(await screen.findByText('暂无文档')).toBeInTheDocument()
    expect(screen.getByText('当前语言下没有已发布的文档，可以换个语言看看')).toBeInTheDocument()
  })

  it('always sends a language, which the backend matches strictly', async () => {
    const knowledge = vi.spyOn(api, 'knowledge').mockResolvedValue({})

    renderPage(<Knowledge />)

    // 不传 language 后端会按 language = null 过滤，结果恒为空
    await waitFor(() => expect(knowledge).toHaveBeenCalled())
    expect(knowledge.mock.calls[0][0]).toBe('zh-CN')
  })
})
