import { get, post, postRaw } from './client'

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

/** 套餐价格用的是旧版键名，且后端已乘以 100（单位：分） */
export const PERIODS = [
  'month_price',
  'quarter_price',
  'half_year_price',
  'year_price',
  'two_year_price',
  'three_year_price',
  'onetime_price',
] as const

export type Period = (typeof PERIODS)[number]

export interface PlanDetail {
  id: number
  name: string
  content: string
  tags: string[] | null
  transfer_enable: number
  speed_limit: number | null
  device_limit: number | null
  capacity_limit: number | string | null
  sell: boolean
  renew: boolean
  month_price: number | null
  quarter_price: number | null
  half_year_price: number | null
  year_price: number | null
  two_year_price: number | null
  three_year_price: number | null
  onetime_price: number | null
  reset_price: number | null
}

/** 0 待支付 / 1 开通中 / 2 已取消 / 3 已完成 / 4 已折抵 */
export type OrderStatus = 0 | 1 | 2 | 3 | 4

export interface Order {
  trade_no: string
  plan_id: number
  period: string
  status: OrderStatus
  type: number
  total_amount: number
  discount_amount: number | null
  balance_amount: number | null
  handling_amount: number | null
  surplus_amount: number | null
  created_at: number
  plan?: PlanDetail
  payment?: { id: number; name: string; payment: string; icon: string | null } | null
}

export interface PaymentMethod {
  id: number
  name: string
  payment: string
  icon: string | null
  handling_fee_fixed: number | null
  handling_fee_percent: number | null
}

export interface Coupon {
  id: number
  code: string
  name: string
  type: number
  value: number
}

/**
 * checkout 的返回：
 *   type -1  余额/免费已完成
 *   type  0  返回二维码内容，需自行渲染
 *   type  1  返回跳转地址
 */
export interface CheckoutResult {
  type: -1 | 0 | 1
  data: string | boolean
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

  plans: () => get<PlanDetail[]>('/user/plan/fetch'),

  checkCoupon: (code: string, plan_id: number, period: Period) =>
    post<Coupon>('/user/coupon/check', { code, plan_id, period }),

  createOrder: (plan_id: number, period: Period, coupon_code?: string) =>
    post<string>('/user/order/save', coupon_code ? { plan_id, period, coupon_code } : { plan_id, period }),

  orders: () => get<Order[]>('/user/order/fetch'),
  orderDetail: (trade_no: string) => get<Order>('/user/order/detail', { trade_no }),
  orderStatus: (trade_no: string) => get<OrderStatus>('/user/order/check', { trade_no }),
  cancelOrder: (trade_no: string) => post<boolean>('/user/order/cancel', { trade_no }),

  paymentMethods: () => get<PaymentMethod[]>('/user/order/getPaymentMethod'),

  checkout: (trade_no: string, method: number) =>
    postRaw<CheckoutResult>('/user/order/checkout', { trade_no, method }),
}
