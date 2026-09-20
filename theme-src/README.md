# Nova — Xboard 用户面板主题（源码）

Xboard 自带的用户面板只有构建产物（`theme/Xboard/assets/umi.js`），没有公开源码，
改不了。这是一套从零写的替代实现，源码在这个目录里。

- 技术栈：React 18 + Vite + TypeScript + [Ant Design](https://ant.design/index-cn) 5
- 构建产物：`theme/Nova/assets/`（文件名固定为 `app.js` / `app.css`，
  `dashboard.blade.php` 用文件 mtime 做缓存击穿）

## 开发

```bash
cd theme-src
pnpm install

# 后端先跑起来（另开一个终端）
php artisan serve --host=127.0.0.1 --port=8000

pnpm dev        # http://localhost:5173，/api 自动代理到 8000
```

## 构建并启用

```bash
pnpm build      # 产物写入 ../theme/Nova/assets
php artisan view:clear
```

然后在后台把「前端主题」切到 `Nova`（或 `admin_setting(['frontend_theme' => 'Nova'])`
再调一次 `ThemeService::refreshCurrentTheme()`，把主题拷进 `public/theme/`）。

## 已覆盖的页面

| 页面 | 路由 | 用到的接口 |
| --- | --- | --- |
| 登录 | `#/login` | `passport/auth/login` |
| 注册 | `#/register` | `guest/comm/config`、`passport/comm/sendEmailVerify`、`passport/auth/register` |
| 找回密码 | `#/forgetpassword` | `passport/comm/sendEmailVerify`、`passport/auth/forget` |
| 仪表板 | `#/dashboard` | `user/getSubscribe` |
| 节点状态 | `#/nodes` | `user/server/fetch` |
| 在线设备 | `#/devices` | `user/getOnlineDevices` |
| 流量明细 | `#/traffic` | `user/stat/getTrafficLog` |
| 我的账户 | `#/account` | `user/info`、`user/changePassword`、`user/resetSecurity` |
| 购买订阅 | `#/plans` | `user/plan/fetch`、`user/coupon/check`、`user/order/save` |
| 我的订单 | `#/orders` | `user/order/fetch`、`user/order/cancel` |
| 订单详情/支付 | `#/order/:tradeNo` | `user/order/detail`、`user/order/getPaymentMethod`、`user/order/checkout`、`user/order/check` |
| 我的工单 | `#/tickets` | `user/ticket/fetch`、`user/ticket/save`、`user/ticket/reply`、`user/ticket/close` |
| 邀请返利 | `#/invite` | `user/invite/fetch`、`user/invite/save`、`user/invite/details`、`user/ticket/withdraw`、`user/comm/config` |
| 使用文档 | `#/knowledge` | `user/knowledge/fetch` |
| Telegram | `#/telegram` | `user/telegram/getBotInfo`、`user/comm/config`、`user/info` |

## 还没做

通知公告（`user/notice/fetch`）、会话管理（`user/getActiveSession` /
`removeActiveSession`）。按现有的 `src/api/index.ts` 加方法、`src/pages/`
加页面、`src/layouts/AppLayout.tsx` 的 `NAV` 加一项即可。

## 几个容易踩的点

- **路由必须用 hash**。Laravel 只有 `/` 这一条服务端路由会渲染面板，
  `/dashboard` 这类深链接会 404。
- **`expired_at` 有三种语义**：`null` 是长期有效，`0` 是无套餐/已过期，
  时间戳才是真正的到期时间。只判 `null` 会把「无套餐」显示成「剩余 0 天」。
- **`group_ids` 存的是字符串**。后端用 `whereJsonContains('group_ids', (string) $user->group_id)`
  查询，写入整数会匹配不上。
- **金额单位是分**，余额、佣金、套餐价格都要除以 100。
- 服务端签发的 `auth_data` 已经带了 `Bearer ` 前缀，直接当 `Authorization` 头用，别再拼一次。
- **套餐价格的存储键和输出键不一样**。数据库 `plans.prices` 用新键名
  （`monthly`、`quarterly`、`yearly`…，单位元），`PlanResource` 输出的却是旧键名
  （`month_price`、`quarter_price`…）且已 ×100 变成分；下单时 `period` 也传旧键名。
  存错键名的直接后果是价格显示成 `—`。
- **`capacity_limit` 同样是三态**：`null` 不限量，`0` 会被后端判定为售罄，
  正整数才是剩余名额。数据库默认值是 `0`，建表后不显式设 `null` 套餐就不会出现在列表里。
- **手续费是 checkout 时才写进订单的**，下单后的详情页 `handling_amount` 还是空。
  前端得按后端同一公式 `round(total * percent / 100 + fixed)` 自己预估，
  否则页面显示的金额会比实际扣款少。
- **`Descriptions` 不会穿透 Fragment**。把 `Descriptions.Item` 包在自定义组件里返回，
  那几行会静默消失，得用 `items` 属性传数组。
- 套餐介绍 `content` 是后台填的富文本，必须渲染成 HTML，所以过一遍 DOMPurify 再插入。
- **`user/invite/details` 不走标准包装**，直接返回顶层 `{data, total}`，得用
  `getRaw()`，否则分页总数拿不到。`order/checkout` 同理。
- **工单消息的 `is_me` 是计算出来的**，不是数据库字段：后端比较
  `message.user_id === ticket.user_id`。造测试数据时写 `is_from_user` 会直接报列不存在。
- **后端不允许同时存在多个未关闭工单**。新建工单和申请提现（提现本身也是开工单）
  在有未结工单时都会被拒，错误文案是「存在未关闭的工单」，前端如实透出即可。
- `invite/fetch` 的 `stat` 是定长数组
  `[已注册人数, 已确认佣金, 确认中佣金, 佣金比例%, 可用佣金]`，金额单位是分。
- **知识库列表的 `language` 必须传**。后端是 `where('language', $request->input('language'))`
  严格相等，不传就按 `language = null` 过滤，结果恒为空。这里跟随界面语言，
  所以后台文章的语言标记要和 `zh-CN` / `en-US` 对得上。
- **Telegram 绑定不在面板里完成**，是给机器人发 `/bind <订阅链接>`（解绑发 `/unbind`）。
  面板只展示机器人账号、命令和当前绑定状态（`user/info` 的 `telegram_id`）。
  另外 `getBotInfo` 在机器人未配置时会抛原始 cURL/TLS 错误，所以要先用
  `user/comm/config` 的 `is_telegram` 做门控，别把底层错误甩给用户。
