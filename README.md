# Xboard

<div align="center">

[![Tests](https://github.com/MiCat-S/Xboard/actions/workflows/tests.yml/badge.svg)](https://github.com/MiCat-S/Xboard/actions/workflows/tests.yml)
![PHP](https://img.shields.io/badge/PHP-8.2%20|%208.4-green.svg)
![Laravel](https://img.shields.io/badge/Laravel-12-red.svg)
![MySQL](https://img.shields.io/badge/MySQL-5.7+-blue.svg)
[![License](https://img.shields.io/badge/License-MIT-yellow.svg)](LICENSE)

</div>

## 📖 Introduction

Xboard is a modern panel system built on Laravel 12 + Octane, focusing on providing a clean and efficient user experience.

**This repository is a fork of [cedar2025/Xboard](https://github.com/cedar2025/Xboard).** It does not track upstream.
What it adds on top:

- A security audit pass over payment callbacks, token handling, randomness and settings caching
- **Nova** — a user panel theme shipped **with its source** (upstream only distributes a built bundle)
- A test suite and CI that actually gate merges

See [Divergence from upstream](#-divergence-from-upstream) for the details.

## ✨ Features

- 🚀 Laravel 12 + Octane for significant performance gains
- 🎨 Admin interface built with React + Shadcn UI
- 📱 **Nova** user theme — React + Vite + TypeScript + Ant Design, source in [`theme-src/`](./theme-src)
- 🐳 Ready-to-use Docker deployment
- ✅ PHPUnit + Vitest suites gating CI, PHPStan level 5 at zero errors
- 🎯 Optimized system architecture for better maintainability

## 🚀 Quick Start

```bash
git clone -b compose --depth 1 https://github.com/MiCat-S/Xboard && \
cd Xboard && \
docker compose run -it --rm \
    -e ENABLE_SQLITE=true \
    -e ENABLE_REDIS=true \
    -e ADMIN_ACCOUNT=admin@demo.com \
    xboard php artisan xboard:install && \
docker compose up -d
```

> After installation, visit: http://SERVER_IP:7001
> ⚠️ Make sure to save the admin credentials shown during installation

A container image is published to `ghcr.io/micat-s/xboard` on every push to `master`.

## 🔐 Security notes

**`APP_KEY` must be unique per installation.** When `secure_path` is not set explicitly, the admin
path falls back to `hash('crc32b', config('app.key'))` — so any site sharing a known `APP_KEY` has a
guessable admin path, on top of forgeable signed URLs and decryptable cookies. `.env.example` ships
with an **empty** `APP_KEY`; `php artisan xboard:install` generates one. If you deployed from an
older `.env.example` that had a value baked in, rotate it. Nothing in this codebase encrypts stored
columns with `APP_KEY`, so rotating only invalidates existing sessions and cookies — user API tokens
are unaffected.

Dependencies are kept clear of published advisories (`composer audit`). `composer.json` pins
`config.platform.php` to `8.2`, the lowest supported version, so `composer update` on a newer
runtime cannot silently produce a lockfile that will not install in production.

## 🌍 Subscription access log & region rules

Every subscription fetch is recorded per `(user, IP)`: country, request count, blocked count,
first/last seen and the client's User-Agent. Records not seen for 90 days are pruned daily.

```bash
php artisan subscribe:log                 # where each account's subscription is fetched from
php artisan subscribe:log --blocked       # only fetches rejected by the region rule
```

An account fetched from more than one country is flagged in the output — that is the usual sign
of a shared subscription.

**Region rule.** Admin panel → *Plugins* → **订阅地区限制** (`plugins-core/SubscribeGeo`).
Modes: no restriction / only allow listed countries / block listed countries. HK, MO and TW are
separate codes and are not covered by `CN`. A blocked fetch gets an empty 403. An address whose
country cannot be determined is always allowed — refusing it would cut a legitimate user off
entirely. The plugin ships disabled; installed-but-disabled means no restriction.
`php artisan subscribe:geo` reads and writes the same plugin config from the command line.

**Country lookup**, first match wins:

1. Cloudflare's `CF-IPCountry` header (the site is behind Cloudflare and `TrustProxies` covers its ranges)
2. A MaxMind-format `.mmdb` at `storage/geoip/GeoLite2-Country.mmdb` (IPv4 + IPv6)
3. The bundled `ip2region` database (IPv4 only, unreliable outside mainland China)

```bash
php artisan geoip:update                  # download / refresh the .mmdb (runs monthly on the 3rd)
php artisan geoip:lookup 1.2.3.4 ::1      # which source says what, per address
```

`geoip:update` fetches [DB-IP](https://db-ip.com)'s free *IP to Country Lite* database by default.
A new file only replaces the old one after it parses, reports a country database type and resolves
known addresses correctly; any failure keeps the existing file. Pass `--url` to use another
MaxMind-format source (e.g. GeoLite2 with your own licence key).

> IP geolocation by [DB-IP](https://db-ip.com), licensed under
> [CC BY 4.0](https://creativecommons.org/licenses/by/4.0/).

## 🧑‍💻 Development

### Backend

```bash
composer install
vendor/bin/phpunit
vendor/bin/phpstan analyse         # level 5, expected to stay at zero errors
composer audit                     # expected to stay clean
```

Tests run against SQLite and need no MySQL. Some device-state tests need Redis; they are skipped
when it is unavailable.

### Nova theme

The theme's source lives in [`theme-src/`](./theme-src) and builds into `theme/Nova/assets/`.

```bash
cd theme-src
pnpm install
pnpm dev                           # dev server
pnpm test
pnpm typecheck
pnpm build                         # writes theme/Nova/assets/
```

**The built bundle is committed to the repository.** If you change the source, run `pnpm build` and
commit the result — CI fails the build if `theme/Nova/assets/` does not match what the current
source produces.

Stack constraints for this theme: React + Vite + TypeScript + Ant Design only. Prefer `antd`
components and `@ant-design/icons`, follow the antd version the project actually installs, and
theme through `ConfigProvider` + design tokens. No other component library.

### CI

[`.github/workflows/tests.yml`](./.github/workflows/tests.yml) runs on every push and pull request:

| Job | What it checks |
| --- | --- |
| Backend (PHP 8.2 / 8.4) | PHPUnit against a Redis service container, plus PHPStan |
| Frontend | `pnpm typecheck`, Vitest, and `antd lint` against the installed antd version |
| Theme build | `pnpm build`, then fails if the committed bundle drifted from the source |

## 📖 Documentation

### 🔄 Upgrade Notice
> 🚨 **Important:** This version involves significant changes. Please strictly follow the upgrade documentation and backup your database before upgrading. Note that upgrading and migration are different processes, do not confuse them.

### Development Guides
- [Plugin Development Guide](./docs/en/development/plugin-development-guide.md) - Complete guide for developing XBoard plugins

### Deployment Guides
- [Deploy with 1Panel](./docs/en/installation/1panel.md)
- [Deploy with Docker Compose](./docs/en/installation/docker-compose.md)
- [Deploy with aaPanel](./docs/en/installation/aapanel.md)
- [Deploy with aaPanel + Docker](./docs/en/installation/aapanel-docker.md) (Recommended)

### Migration Guides
- [Migrate from v2board dev](./docs/en/migration/v2board-dev.md)
- [Migrate from v2board 1.7.4](./docs/en/migration/v2board-1.7.4.md)
- [Migrate from v2board 1.7.3](./docs/en/migration/v2board-1.7.3.md)

## 🛠️ Tech Stack

- Backend: Laravel 12 + Octane (PHP 8.2+)
- Admin Panel: React + Shadcn UI + TailwindCSS ([dist submodule](https://github.com/MiCat-S/xboard-admin-dist))
- User Themes:
  - **Nova** — React + Vite + TypeScript + Ant Design, source included
  - **Xboard** — the legacy theme, distributed as a built bundle only
- Deployment: Docker + Docker Compose
- Caching: Redis + Octane Cache

## 🔀 Divergence from upstream

This fork does not sync with `cedar2025/Xboard`. The substantive differences:

- **Payment callbacks** — amounts are verified centrally before an order is marked paid; gateway
  plugins gate on event type and status, compare signatures with `hash_equals`, and a callback for
  an unknown order now reports failure instead of acknowledging it as success.
- **Auth** — bearer tokens are checked for expiry and for a banned owner.
- **Randomness** — verification codes, order numbers and generated secrets use a CSPRNG.
- **Settings cache** — a Redis failure falls back to the database instead of an empty array, which
  previously re-opened registration and disabled the captcha on a cache blip.
- **Sorting** — `sort` parameters are validated against the real schema rather than interpolated.
- **Nova theme** — a user panel with source, replacing a dist-only bundle.
- **Tests and CI** — see above. PHPStan runs at level 5 with no baseline.

The admin dist submodule points at [`MiCat-S/xboard-admin-dist`](https://github.com/MiCat-S/xboard-admin-dist),
a fork carrying a copy fix. Run `git submodule sync && git submodule update --init` after pulling.

## 📷 Preview

![Admin Preview](./docs/images/admin.png)

![User Preview](./docs/images/user.png)

> The user screenshot above shows the legacy `Xboard` theme. Nova is selected from the admin panel's
> theme settings.

## ⚠️ Disclaimer

This project is for learning and communication purposes only. Users are responsible for any consequences of using this project.

## 🔔 Important Notes

1. Restart after changing the admin path:
```bash
docker compose restart
```

2. For aaPanel installations, restart the Octane daemon process.

3. **Octane keeps workers alive across requests.** Any deployment must restart them, or the old code
   keeps serving.

## ❤️ Upstream

Xboard is originally by [cedar2025](https://github.com/cedar2025/Xboard) and is MIT licensed.
Upstream is under light maintenance: critical bugs and security issues get fixed, important pull
requests get reviewed, but new feature development is limited.

If the upstream project has helped you, the author accepts donations at `TLypStEWsVrj6Wz9mCxbXffqgt5yz3Y4XB` (TRC20).
