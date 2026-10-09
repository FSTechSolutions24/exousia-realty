<!doctype html>
<html lang="en" dir="ltr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="theme-color" content="#102a2e">
    <title>Exousia Realty</title>
    <meta name="description" content="A modern real estate brokerage workspace built for the Egyptian market.">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&family=Manrope:wght@500;600;700&family=Tajawal:wght@400;500;700&display=swap" rel="stylesheet">
    @php($manifestPath = public_path('build/.vite/manifest.json'))
    @if(file_exists($manifestPath))
        @php($manifest = json_decode(file_get_contents($manifestPath), true))
        @php($entry = $manifest['resources/js/app.ts'])
        @foreach($entry['css'] ?? [] as $css)<link rel="stylesheet" href="/build/{{ $css }}">@endforeach
        <script type="module" src="/build/{{ $entry['file'] }}"></script>
    @else
        <script type="module" src="http://localhost:5173/@vite/client"></script>
        <script type="module" src="http://localhost:5173/resources/js/app.ts"></script>
    @endif
</head>
<body>
    <div id="app"><div style="min-height:100vh;display:grid;place-items:center;background:#f4f5f1;color:#102a2e;font:600 16px sans-serif">Loading Exousia Realty…</div></div>
</body>
</html>
