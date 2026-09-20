import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import http, { TOKEN_KEY, get, getRaw, getToken, post, postRaw, setToken, setUnauthorizedHandler } from './client'

/**
 * Xboard 的接口有两种形状：
 *   标准包装  { status, message, data, error }
 *   顶层结构  order/checkout 的 {type, data}、invite/details 的 {data, total}
 * 用错解包方式会静默丢字段，这里把两种都锁住。
 */
describe('response unwrapping', () => {
  const adapter = vi.fn()

  beforeEach(() => {
    adapter.mockReset()
    http.defaults.adapter = adapter
  })

  afterEach(() => {
    setUnauthorizedHandler(() => {})
  })

  const reply = (data: unknown, status = 200) =>
    adapter.mockResolvedValue({ data, status, statusText: 'OK', headers: {}, config: {} })

  it('get() unwraps the standard envelope', async () => {
    reply({ status: 'success', message: 'ok', data: { email: 'a@b.c' }, error: null })
    await expect(get('/user/info')).resolves.toEqual({ email: 'a@b.c' })
  })

  it('post() unwraps the standard envelope', async () => {
    reply({ status: 'success', message: 'ok', data: 'TRADE123', error: null })
    await expect(post('/user/order/save')).resolves.toBe('TRADE123')
  })

  it('postRaw() keeps the top-level body, which checkout needs', async () => {
    // order/checkout 直接返回 {type, data}，用 post() 会把 type 丢掉
    reply({ type: 1, data: 'https://pay.example.com/x' })
    await expect(postRaw('/user/order/checkout')).resolves.toEqual({
      type: 1,
      data: 'https://pay.example.com/x',
    })
  })

  it('getRaw() keeps the top-level body, which pagination needs', async () => {
    // invite/details 返回 {data, total}，用 get() 会把 total 丢掉
    reply({ data: [{ id: 1 }], total: 42 })
    await expect(getRaw('/user/invite/details')).resolves.toEqual({ data: [{ id: 1 }], total: 42 })
  })
})

describe('auth token', () => {
  const adapter = vi.fn()

  beforeEach(() => {
    adapter.mockReset()
    adapter.mockResolvedValue({ data: { data: null }, status: 200, statusText: 'OK', headers: {}, config: {} })
    http.defaults.adapter = adapter
  })

  it('round-trips through localStorage', () => {
    expect(getToken()).toBeNull()
    setToken('Bearer abc')
    expect(getToken()).toBe('Bearer abc')
    expect(localStorage.getItem(TOKEN_KEY)).toBe('Bearer abc')
    setToken(null)
    expect(getToken()).toBeNull()
  })

  it('sends the stored value as Authorization verbatim', async () => {
    // 服务端签发的 auth_data 已经带了 "Bearer " 前缀，不能再拼一次
    setToken('Bearer xyz')
    await get('/user/info')

    expect(adapter.mock.calls[0][0].headers.Authorization).toBe('Bearer xyz')
  })

  it('omits the header when signed out', async () => {
    await get('/guest/comm/config')
    expect(adapter.mock.calls[0][0].headers.Authorization).toBeUndefined()
  })
})

describe('error handling', () => {
  const adapter = vi.fn()

  beforeEach(() => {
    adapter.mockReset()
    http.defaults.adapter = adapter
  })

  const fail = (status: number, data: unknown) =>
    adapter.mockRejectedValue({
      response: { status, data, statusText: '', headers: {}, config: {} },
      message: 'Request failed',
    })

  it('surfaces the backend message', async () => {
    fail(400, { message: '优惠券不可用' })
    await expect(post('/user/coupon/check')).rejects.toThrow('优惠券不可用')
  })

  it('clears the token and notifies on 401', async () => {
    const onUnauthorized = vi.fn()
    setUnauthorizedHandler(onUnauthorized)
    setToken('Bearer stale')
    fail(401, { message: 'unauthorized' })

    await expect(get('/user/info')).rejects.toThrow()

    expect(getToken()).toBeNull()
    expect(onUnauthorized).toHaveBeenCalledOnce()
  })

  it('treats 403 the same as 401, which is what the user middleware returns', async () => {
    const onUnauthorized = vi.fn()
    setUnauthorizedHandler(onUnauthorized)
    setToken('Bearer stale')
    fail(403, { message: '未登录或登陆已过期' })

    await expect(get('/user/info')).rejects.toThrow('未登录或登陆已过期')

    expect(getToken()).toBeNull()
    expect(onUnauthorized).toHaveBeenCalledOnce()
  })

  it('keeps the session on an ordinary error', async () => {
    const onUnauthorized = vi.fn()
    setUnauthorizedHandler(onUnauthorized)
    setToken('Bearer good')
    fail(400, { message: 'nope' })

    await expect(get('/user/info')).rejects.toThrow()

    expect(getToken()).toBe('Bearer good')
    expect(onUnauthorized).not.toHaveBeenCalled()
  })
})
