<!DOCTYPE html>
<html lang="en">

<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>{{ $title }}</title>
  <script>
    window.settings = {
      base_url: "/",
      title: "{{ $title }}",
      version: "{{ $version }}",
      logo: "{{ $logo }}",
      secure_path: "{{ $secure_path }}",
      theme: { color: @json($theme_color) },
    };
  </script>
  @php
    $manifestPath = public_path('assets/admin/manifest.json');
    $manifest = file_exists($manifestPath) ? json_decode(file_get_contents($manifestPath), true) : null;
    $entry = is_array($manifest) ? ($manifest['index.html'] ?? null) : null;
    $scripts = [];
    $styles = [];
    $locales = [];
    $adminPluginScripts = [];
    $adminPluginStyles = [];
    $adminBundlePath = public_path('assets/admin/assets/index-CEIYH7i8.js');
    $adminBundleVersion = file_exists($adminBundlePath) ? substr(hash_file('sha256', $adminBundlePath), 0, 16) : '';

    $enabledAdminPlugins = \App\Models\Plugin::query()
      ->where('is_enabled', true)
      ->orderBy('code')
      ->pluck('code')
      ->filter(fn($code) => is_string($code) && preg_match('/^[a-z0-9_]+$/', $code));

    foreach ($enabledAdminPlugins as $pluginCode) {
      $pluginAssetPath = public_path('plugins/' . $pluginCode);
      if (file_exists($pluginAssetPath . '/' . $pluginCode . '.css')) {
        $adminPluginStyles[] = $pluginCode . '/' . $pluginCode . '.css';
      }
      if (file_exists($pluginAssetPath . '/' . $pluginCode . '.js')) {
        $adminPluginScripts[] = $pluginCode . '/' . $pluginCode . '.js';
      }
    }

    if (is_array($entry)) {
      $visited = [];
      $collectAssets = function ($chunkName) use (&$collectAssets, &$manifest, &$visited, &$scripts, &$styles) {
        if (isset($visited[$chunkName]) || !isset($manifest[$chunkName]) || !is_array($manifest[$chunkName])) {
          return;
        }

        $visited[$chunkName] = true;
        $chunk = $manifest[$chunkName];

        if (!empty($chunk['css']) && is_array($chunk['css'])) {
          foreach ($chunk['css'] as $cssFile) {
            $styles[$cssFile] = $cssFile;
          }
        }

        if (!empty($chunk['imports']) && is_array($chunk['imports'])) {
          foreach ($chunk['imports'] as $import) {
            $collectAssets($import);
          }
        }

        if (!empty($chunk['isEntry']) && !empty($chunk['file'])) {
          $scripts[$chunk['file']] = $chunk['file'];
        }
      };

      $collectAssets('index.html');
    }

    foreach (glob(public_path('assets/admin/locales/*.js')) ?: [] as $localeFile) {
      $locales[] = 'locales/' . basename($localeFile);
    }
    sort($locales);
  @endphp

  @if($entry && count($scripts) > 0)
    @foreach($styles as $css)
      <link rel="stylesheet" crossorigin href="/assets/admin/{{ $css }}" />
    @endforeach
    @foreach($locales as $locale)
      <script src="/assets/admin/{{ $locale }}"></script>
    @endforeach
    @foreach($adminPluginStyles as $css)
      <link rel="stylesheet" crossorigin href="/plugins/{{ $css }}" />
    @endforeach
    @foreach($adminPluginScripts as $js)
      <script type="module" crossorigin src="/plugins/{{ $js }}"></script>
    @endforeach
    @foreach($scripts as $js)
      <script type="module" crossorigin src="/assets/admin/{{ $js }}{{ $js === 'assets/index-CEIYH7i8.js' ? '?v=' . $adminBundleVersion : '' }}"></script>
    @endforeach
  @else
    {{-- Fallback: hardcoded paths for backward compatibility --}}
    @foreach($adminPluginStyles as $css)
      <link rel="stylesheet" crossorigin href="/plugins/{{ $css }}" />
    @endforeach
    @foreach($adminPluginScripts as $js)
      <script type="module" crossorigin src="/plugins/{{ $js }}"></script>
    @endforeach
    <script type="module" crossorigin src="/assets/admin/assets/index.js"></script>
    <link rel="stylesheet" crossorigin href="/assets/admin/assets/index.css" />
    <link rel="stylesheet" crossorigin href="/assets/admin/assets/vendor.css">
    <script src="/assets/admin/locales/en-US.js"></script>
    <script src="/assets/admin/locales/zh-CN.js"></script>
    <script src="/assets/admin/locales/ko-KR.js"></script>
  @endif
</head>

<body>
  <div id="root"></div>
</body>

</html>
