<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">

<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta name="robots" content="noindex, nofollow">
  <title>{{ __('Online Devices') }} - {{ $app_name }}</title>
  <style>
    :root {
      --bg: #ffffff;
      --card: #ffffff;
      --text: #111111;
      --text-secondary: #6b7280;
      --primary: #2196f3;
      --success: #4caf50;
      --danger: #f44336;
      --warning: #f59e0b;
      --border: #e5e7eb;
    }

    @media (prefers-color-scheme: dark) {
      :root {
        --bg: #111827;
        --card: #1f2937;
        --text: #f3f4f6;
        --text-secondary: #9ca3af;
        --border: #374151;
      }
    }

    * { margin: 0; padding: 0; box-sizing: border-box; }

    body {
      font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', sans-serif;
      line-height: 1.6;
      background: var(--bg);
      color: var(--text);
      -webkit-font-smoothing: antialiased;
      padding: 2rem 1rem;
    }

    .container { max-width: 760px; margin: 0 auto; }

    .title { font-size: 1.5rem; font-weight: 700; margin-bottom: .25rem; }
    .subtitle { color: var(--text-secondary); font-size: .9rem; margin-bottom: 1.75rem; }

    .card {
      background: var(--card);
      border: 1px solid var(--border);
      border-radius: 10px;
      padding: 1.25rem;
      margin-bottom: 1rem;
    }

    label { display: block; font-size: .85rem; color: var(--text-secondary); margin-bottom: .35rem; }

    input[type=email], input[type=password] {
      width: 100%;
      padding: .6rem .75rem;
      font-size: 1rem;
      color: var(--text);
      background: var(--bg);
      border: 1px solid var(--border);
      border-radius: 6px;
      margin-bottom: 1rem;
    }

    button {
      font: inherit;
      padding: .55rem 1.1rem;
      border: none;
      border-radius: 6px;
      background: var(--primary);
      color: #fff;
      cursor: pointer;
    }

    button:disabled { opacity: .6; cursor: default; }
    button.secondary { background: transparent; color: var(--text-secondary); border: 1px solid var(--border); }

    .toolbar { display: flex; align-items: center; gap: .75rem; margin-bottom: 1rem; flex-wrap: wrap; }
    .toolbar .spacer { flex: 1; }

    .summary { color: var(--text-secondary); font-size: .9rem; }
    .summary strong { color: var(--text); }

    .device {
      display: flex;
      align-items: flex-start;
      gap: .9rem;
      padding: .9rem 0;
      border-bottom: 1px solid var(--border);
    }

    .device:last-child { border-bottom: none; }

    .dot { width: .6rem; height: .6rem; border-radius: 50%; background: var(--warning); margin-top: .55rem; flex: none; }
    .dot.self { background: var(--success); }

    .device-main { flex: 1; min-width: 0; }
    .ip { font-family: ui-monospace, SFMono-Regular, Menlo, monospace; font-size: 1rem; word-break: break-all; }
    .meta { color: var(--text-secondary); font-size: .85rem; }

    .tag {
      display: inline-block;
      font-size: .72rem;
      padding: .1rem .45rem;
      border-radius: 4px;
      margin-left: .4rem;
      vertical-align: middle;
    }

    .tag.self { background: rgba(76, 175, 80, .15); color: var(--success); }
    .tag.foreign { background: rgba(245, 158, 11, .15); color: var(--warning); }

    .hint { font-size: .85rem; color: var(--text-secondary); margin-top: 1.25rem; }
    .error { color: var(--danger); font-size: .9rem; margin-bottom: 1rem; }
    .empty { color: var(--text-secondary); padding: 1.5rem 0; text-align: center; }
    [hidden] { display: none !important; }
  </style>
</head>

<body>
  <div class="container">
    <h1 class="title">{{ __('Online Devices') }}</h1>
    <p class="subtitle">{{ __('These are the source IPs your nodes have seen in the last few minutes. An address you do not recognise may mean your subscription link has been shared or stolen.') }}</p>

    <div class="card" id="login-card" hidden>
      <p class="error" id="login-error" hidden></p>
      <form id="login-form">
        <label for="email">{{ __('Email') }}</label>
        <input type="email" id="email" autocomplete="username" required>
        <label for="password">{{ __('Password') }}</label>
        <input type="password" id="password" autocomplete="current-password" required>
        <button type="submit" id="login-submit">{{ __('Sign in') }}</button>
      </form>
    </div>

    <div id="devices-card" hidden>
      <div class="toolbar">
        <span class="summary" id="summary"></span>
        <span class="spacer"></span>
        <button type="button" class="secondary" id="refresh">{{ __('Refresh') }}</button>
        <button type="button" class="secondary" id="logout">{{ __('Sign out') }}</button>
      </div>
      <p class="error" id="list-error" hidden></p>
      <div class="card" id="device-list"></div>
      <p class="hint">{{ __('Device records expire a few minutes after a connection stops, so this list only reflects recent activity. If you see an address that is not yours, reset your subscription link from the panel.') }}</p>
    </div>
  </div>

  <script>
    (function () {
      var STORAGE_KEY = 'xboard_devices_auth';
      var TEXT = {
        signingIn: @json(__('Signing in...')),
        loading: @json(__('Loading...')),
        empty: @json(__('No active connections right now.')),
        thisDevice: @json(__('This device')),
        unknownRegion: @json(__('Unknown location')),
        unknownNode: @json(__('Unknown node')),
        summary: @json(__('In use: :count')),
        limit: @json(__('Limit: :limit')),
        unlimited: @json(__('Unlimited')),
        lastSeen: @json(__('Last seen :time')),
        genericError: @json(__('Request failed, please try again later')),
        sessionExpired: @json(__('Not logged in or login expired'))
      };

      var loginCard = document.getElementById('login-card');
      var devicesCard = document.getElementById('devices-card');
      var loginForm = document.getElementById('login-form');
      var loginError = document.getElementById('login-error');
      var listError = document.getElementById('list-error');
      var listEl = document.getElementById('device-list');
      var summaryEl = document.getElementById('summary');
      var submitBtn = document.getElementById('login-submit');
      var refreshTimer = null;

      function token(value) {
        try {
          if (value === undefined) return sessionStorage.getItem(STORAGE_KEY);
          if (value === null) sessionStorage.removeItem(STORAGE_KEY);
          else sessionStorage.setItem(STORAGE_KEY, value);
        } catch (e) {
          // 隐私模式下 sessionStorage 可能不可用，退化为仅本次可用
        }
        return value;
      }

      function showError(el, message) {
        el.textContent = message || '';
        el.hidden = !message;
      }

      function showLogin() {
        if (refreshTimer) { clearInterval(refreshTimer); refreshTimer = null; }
        devicesCard.hidden = true;
        loginCard.hidden = false;
      }

      function showDevices() {
        loginCard.hidden = true;
        devicesCard.hidden = false;
        if (!refreshTimer) refreshTimer = setInterval(load, 60000);
      }

      function formatTime(unix) {
        if (!unix) return '';
        try {
          return new Date(unix * 1000).toLocaleString();
        } catch (e) {
          return String(unix);
        }
      }

      // 节点名由管理员填写，一律走 textContent，不拼 HTML
      function render(payload) {
        listEl.textContent = '';

        var devices = (payload && payload.devices) || [];
        var limit = payload && payload.device_limit;

        summaryEl.textContent = TEXT.summary.replace(':count', devices.length)
          + ' · '
          + TEXT.limit.replace(':limit', (limit === null || limit === undefined || limit === 0) ? TEXT.unlimited : limit);

        if (!devices.length) {
          var empty = document.createElement('div');
          empty.className = 'empty';
          empty.textContent = TEXT.empty;
          listEl.appendChild(empty);
          return;
        }

        devices.forEach(function (device) {
          var row = document.createElement('div');
          row.className = 'device';

          var dot = document.createElement('span');
          dot.className = 'dot' + (device.is_current_ip ? ' self' : '');
          row.appendChild(dot);

          var main = document.createElement('div');
          main.className = 'device-main';

          var ip = document.createElement('div');
          ip.className = 'ip';
          ip.textContent = device.ip;

          var tag = document.createElement('span');
          tag.className = 'tag ' + (device.is_current_ip ? 'self' : 'foreign');
          tag.textContent = device.is_current_ip ? TEXT.thisDevice : '?';
          ip.appendChild(tag);
          main.appendChild(ip);

          var meta = document.createElement('div');
          meta.className = 'meta';
          var nodes = (device.nodes && device.nodes.length) ? device.nodes.join(' / ') : TEXT.unknownNode;
          meta.textContent = (device.region || TEXT.unknownRegion)
            + ' · ' + nodes
            + ' · ' + TEXT.lastSeen.replace(':time', formatTime(device.last_seen_at));
          main.appendChild(meta);

          row.appendChild(main);
          listEl.appendChild(row);
        });
      }

      function load() {
        var auth = token();
        if (!auth) { showLogin(); return; }

        showError(listError, '');

        fetch('{{ url('/api/v1/user/getOnlineDevices') }}', {
          headers: { 'Authorization': auth, 'Accept': 'application/json' }
        }).then(function (response) {
          if (response.status === 401 || response.status === 403) {
            token(null);
            showLogin();
            showError(loginError, TEXT.sessionExpired);
            return null;
          }
          return response.json();
        }).then(function (body) {
          if (!body) return;
          if (body.data) {
            showDevices();
            render(body.data);
          } else {
            showError(listError, body.message || TEXT.genericError);
          }
        }).catch(function () {
          showError(listError, TEXT.genericError);
        });
      }

      loginForm.addEventListener('submit', function (event) {
        event.preventDefault();
        showError(loginError, '');
        submitBtn.disabled = true;
        submitBtn.textContent = TEXT.signingIn;

        fetch('{{ url('/api/v1/passport/auth/login') }}', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
          body: JSON.stringify({
            email: document.getElementById('email').value,
            password: document.getElementById('password').value
          })
        }).then(function (response) {
          return response.json().then(function (body) { return { ok: response.ok, body: body }; });
        }).then(function (result) {
          if (!result.ok || !result.body.data || !result.body.data.auth_data) {
            showError(loginError, (result.body && result.body.message) || TEXT.genericError);
            return;
          }
          token(result.body.data.auth_data);
          document.getElementById('password').value = '';
          load();
        }).catch(function () {
          showError(loginError, TEXT.genericError);
        }).finally(function () {
          submitBtn.disabled = false;
          submitBtn.textContent = @json(__('Sign in'));
        });
      });

      document.getElementById('refresh').addEventListener('click', load);

      document.getElementById('logout').addEventListener('click', function () {
        token(null);
        showLogin();
      });

      if (token()) load(); else showLogin();
    })();
  </script>
</body>

</html>
