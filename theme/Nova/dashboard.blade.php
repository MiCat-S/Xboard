<!doctype html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">

<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover" />
  <meta name="description" content="{{ $description }}" />
  <title>{{ $title }}</title>
  @if($logo)
    <link rel="icon" href="{{ $logo }}" />
  @endif
  @php
    // 产物文件名固定，升级后浏览器会拿到缓存里的旧版本，
    // 用文件 mtime 做缓存击穿——每次部署重新拷贝主题时它都会变。
    $assetVersion = @filemtime(public_path("theme/{$theme}/assets/app.js")) ?: $version;
  @endphp
  <link rel="stylesheet" href="/theme/{{ $theme }}/assets/app.css?v={{ $assetVersion }}" />
</head>

<body>
  <script>
    window.settings = {
      title: @json($title),
      description: @json($description),
      logo: @json($logo),
      version: @json($version),
      background_url: @json($theme_config['background_url'] ?? ''),
      theme: { color: @json($theme_config['theme_color'] ?? 'default') },
      assets_path: '/theme/{{ $theme }}/assets'
    }
  </script>

  <div id="root"></div>
  <script type="module" src="/theme/{{ $theme }}/assets/app.js?v={{ $assetVersion }}"></script>
  {!! $theme_config['custom_html'] ?? '' !!}
</body>

</html>
