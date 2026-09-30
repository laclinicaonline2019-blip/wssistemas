<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<meta name="theme-color" content="#070d18">
<title>{{ isset($title) ? $title.' · ' : '' }}{{ config('aivexa.brand.name') }}</title>
<link rel="icon" type="image/png" sizes="32x32" href="{{ asset('assets/img/icon-32.png') }}">
<link rel="apple-touch-icon" href="{{ asset('assets/img/icon-192.png') }}">
<link rel="manifest" href="{{ asset('manifest.webmanifest') }}">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap">
<link rel="stylesheet" href="{{ asset('assets/css/app.css') }}?v={{ filemtime(public_path('assets/css/app.css')) }}">
<script src="{{ asset('assets/js/app.js') }}?v={{ filemtime(public_path('assets/js/app.js')) }}" defer></script>
