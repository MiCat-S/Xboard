import { beforeEach, describe, expect, it, vi } from 'vitest'
import { api, type OnlineDevices } from '../api'
import { renderPage, screen } from '../test/render'
import Devices from './Devices'

function mockDevices(data: Partial<OnlineDevices>) {
  vi.spyOn(api, 'onlineDevices').mockResolvedValue({
    device_limit: 3,
    online_count: 0,
    current_ip: '203.0.113.7',
    devices: [],
    ...data,
  })
}

describe('Devices', () => {
  beforeEach(() => {
    vi.restoreAllMocks()
  })

  it('marks the address the request came from, and only that one', async () => {
    mockDevices({
      online_count: 2,
      devices: [
        { ip: '203.0.113.7', region: '中国江苏省南京市', nodes: ['香港 01'], last_seen_at: 1789948800, is_current_ip: true },
        { ip: '198.51.100.9', region: '美国', nodes: ['日本 02'], last_seen_at: 1789948700, is_current_ip: false },
      ],
    })

    renderPage(<Devices />)

    // 自己的 IP 打上「本机」，陌生的留问号——用户就是靠这个发现订阅被盗用
    expect(await screen.findByText('本机')).toBeInTheDocument()
    expect(screen.getAllByText('本机')).toHaveLength(1)
    expect(screen.getByText('?')).toBeInTheDocument()
  })

  it('shows region and nodes for each address', async () => {
    mockDevices({
      online_count: 1,
      devices: [
        { ip: '203.0.113.7', region: '中国江苏省南京市', nodes: ['香港 01', '日本 02'], last_seen_at: 1789948800, is_current_ip: false },
      ],
    })

    renderPage(<Devices />)

    expect(await screen.findByText(/中国江苏省南京市/)).toBeInTheDocument()
    expect(screen.getByText(/香港 01 \/ 日本 02/)).toBeInTheDocument()
  })

  it('falls back when the region is unknown, which IPv6 always is', async () => {
    mockDevices({
      online_count: 1,
      devices: [
        { ip: '2001:4860:4860::8888', region: null, nodes: [], last_seen_at: 1789948800, is_current_ip: false },
      ],
    })

    renderPage(<Devices />)

    expect(await screen.findByText(/归属地未知/)).toBeInTheDocument()
    expect(screen.getByText(/未知节点/)).toBeInTheDocument()
  })

  it('renders node names as text, since administrators author them', async () => {
    mockDevices({
      online_count: 1,
      devices: [
        {
          ip: '203.0.113.7',
          region: null,
          nodes: ['<img src=x onerror="window.__pwned=1">'],
          last_seen_at: 1789948800,
          is_current_ip: false,
        },
      ],
    })

    const { container } = renderPage(<Devices />)

    await screen.findByText(/203\.0\.113\.7/)
    expect(container.querySelector('img')).toBeNull()
    expect(container.textContent).toContain('<img src=x')
  })

  it('reports an empty state rather than a blank card', async () => {
    mockDevices({ online_count: 0, devices: [] })

    renderPage(<Devices />)

    expect(await screen.findByText('当前没有活动连接')).toBeInTheDocument()
  })

  it('shows the device limit alongside the count', async () => {
    mockDevices({ online_count: 2, device_limit: 3, devices: [] })

    renderPage(<Devices />)

    expect(await screen.findByText(/已用 2 台 · 设备限制 3/)).toBeInTheDocument()
  })

  it('says unlimited when there is no device cap', async () => {
    mockDevices({ online_count: 1, device_limit: null, devices: [] })

    renderPage(<Devices />)

    expect(await screen.findByText(/设备限制 不限/)).toBeInTheDocument()
  })
})
