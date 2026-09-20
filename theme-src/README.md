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

## 还没做

购买下单与支付跳转、我的订单、工单、邀请佣金、知识库。这些接口后端都有
（`app/Http/Routes/V1/UserRoute.php` 一共 43 条），按现有的 `src/api/index.ts`
加方法、`src/pages/` 加页面、`src/layouts/AppLayout.tsx` 的 `NAV` 加一项即可。

## 几个容易踩的点

- **路由必须用 hash**。Laravel 只有 `/` 这一条服务端路由会渲染面板，
  `/dashboard` 这类深链接会 404。
- **`expired_at` 有三种语义**：`null` 是长期有效，`0` 是无套餐/已过期，
  时间戳才是真正的到期时间。只判 `null` 会把「无套餐」显示成「剩余 0 天」。
- **`group_ids` 存的是字符串**。后端用 `whereJsonContains('group_ids', (string) $user->group_id)`
  查询，写入整数会匹配不上。
- **金额单位是分**，余额、佣金、套餐价格都要除以 100。
- 服务端签发的 `auth_data` 已经带了 `Bearer ` 前缀，直接当 `Authorization` 头用，别再拼一次。
