import { get, post } from './client'

export interface GuestConfig {
  tos_url: string | null
  is_email_verify: number
  is_invite_force: number
  email_whitelist_suffix: string[] | 0
  is_captcha: number
  captcha_type: string
  app_description: string | null
  logo: string | null
}

export interface AuthData {
  token: string
  auth_data: string
  is_admin: boolean
}

export interface UserInfo {
  email: string
  transfer_enable: number
  last_login_at: number | null
  created_at: number
  banned: number
  expired_at: number | null
  balance: number
  commission_balance: number
  plan_id: number | null
  uuid: string
  avatar_url: string
}

export interface Plan {
  id: number
  name: string
  content: string | null
}

export interface Subscribe {
  plan_id: number | null
  token: string
  expired_at: number | null
  u: number
  d: number
  transfer_enable: number
  email: string
  uuid: string
  device_limit: number | null
  speed_limit: number | null
  reset_day: number | null
  subscribe_url: string
  plan?: Plan
}

export interface Node {
  id: number
  type: string
  name: string
  rate: string
  tags: string[] | null
  is_online: number | boolean
  last_check_at: number | null
}

export interface Device {
  ip: string
  region: string | null
  nodes: string[]
  last_seen_at: number
  is_current_ip: boolean
}

export interface OnlineDevices {
  device_limit: number | null
  online_count: number
  current_ip: string
  devices: Device[]
}

export interface TrafficLog {
  u: number
  d: number
  record_at: number
  server_rate: string
}

export const api = {
  guestConfig: () => get<GuestConfig>('/guest/comm/config'),

  login: (email: string, password: string) =>
    post<AuthData>('/passport/auth/login', { email, password }),

  register: (payload: Record<string, unknown>) =>
    post<AuthData>('/passport/auth/register', payload),

  sendEmailVerify: (email: string) =>
    post<boolean>('/passport/comm/sendEmailVerify', { email }),

  forget: (email: string, email_code: string, password: string) =>
    post<boolean>('/passport/auth/forget', { email, email_code, password }),

  info: () => get<UserInfo>('/user/info'),
  subscribe: () => get<Subscribe>('/user/getSubscribe'),
  /** [待支付订单数, 未结工单数, 已邀请人数] */
  stat: () => get<number[]>('/user/getStat'),
  nodes: () => get<Node[]>('/user/server/fetch'),
  onlineDevices: () => get<OnlineDevices>('/user/getOnlineDevices'),
  trafficLog: () => get<TrafficLog[]>('/user/stat/getTrafficLog'),

  changePassword: (old_password: string, new_password: string) =>
    post<boolean>('/user/changePassword', { old_password, new_password }),

  resetSecurity: () => get<string>('/user/resetSecurity'),
}
