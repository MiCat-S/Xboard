import axios, { type AxiosInstance, type AxiosResponse } from 'axios'

/**
 * Xboard 的 API 统一返回 { status, message, data, error }，HTTP 状态码携带真实结果。
 * 这里把它收敛成「成功给 data，失败抛带 message 的 Error」。
 */

export const TOKEN_KEY = 'xboard_access_token'

let onUnauthorized: (() => void) | null = null

export function setUnauthorizedHandler(handler: () => void) {
  onUnauthorized = handler
}

export function getToken(): string | null {
  try {
    return localStorage.getItem(TOKEN_KEY)
  } catch {
    return null
  }
}

export function setToken(token: string | null) {
  try {
    if (token === null) localStorage.removeItem(TOKEN_KEY)
    else localStorage.setItem(TOKEN_KEY, token)
  } catch {
    // 隐私模式下 localStorage 不可用，本次会话内仍可工作
  }
}

const http: AxiosInstance = axios.create({
  baseURL: '/api/v1',
  timeout: 20000,
  headers: { Accept: 'application/json' },
})

http.interceptors.request.use((config) => {
  const token = getToken()
  // 服务端签发的 auth_data 已经带了 "Bearer " 前缀
  if (token) config.headers.Authorization = token
  return config
})

function messageOf(response: AxiosResponse | undefined, fallback: string): string {
  const data = response?.data
  if (data && typeof data.message === 'string' && data.message) return data.message
  return fallback
}

http.interceptors.response.use(
  (response) => response,
  (error) => {
    const status = error?.response?.status

    if (status === 401 || status === 403) {
      setToken(null)
      onUnauthorized?.()
    }

    return Promise.reject(new Error(messageOf(error?.response, error?.message || '请求失败')))
  },
)

export async function get<T>(url: string, params?: Record<string, unknown>): Promise<T> {
  const response = await http.get(url, { params })
  return response.data?.data as T
}

export async function post<T>(url: string, body?: Record<string, unknown>): Promise<T> {
  const response = await http.post(url, body)
  return response.data?.data as T
}

/**
 * 拿整个响应体，不做 data 解包。
 * order/checkout 这类接口直接返回 {type, data}，没有外层 data 包装。
 */
export async function postRaw<T>(url: string, body?: Record<string, unknown>): Promise<T> {
  const response = await http.post(url, body)
  return response.data as T
}

export default http
