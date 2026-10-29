<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>@yield('code') — {{ config('app.name', 'AfyaNova') }}</title>
    <link rel="icon" type="image/svg+xml" href="/afyanova.svg">
    <style>
        :root { color-scheme: light dark; }
        body {
            margin: 0;
            min-height: 100dvh;
            display: flex;
            align-items: center;
            justify-content: center;
            font-family: system-ui, -apple-system, 'Segoe UI', sans-serif;
            background: #f4f4f4;
            color: #161616;
        }
        @media (prefers-color-scheme: dark) {
            body { background: #161616; color: #f4f4f4; }
        }
        main { max-width: 28rem; padding: 2rem; text-align: center; }
        .code { font-size: 0.875rem; letter-spacing: 0.05em; opacity: 0.7; margin: 0; }
        h1 { font-size: 1.5rem; font-weight: 600; margin: 0.5rem 0; }
        p { margin: 0 0 1.5rem; line-height: 1.5; }
        a {
            display: inline-block;
            padding: 0.75rem 1.5rem;
            background: #0f62fe;
            color: #fff;
            text-decoration: none;
            font-weight: 600;
        }
        a:hover { background: #0353e9; }
    </style>
</head>
<body>
    <main>
        <p class="code">@yield('code')</p>
        <h1>@yield('title')</h1>
        <p>@yield('message')</p>
        <a href="@yield('href', '/dashboard')">@yield('action', 'Go to dashboard')</a>
    </main>
</body>
</html>
