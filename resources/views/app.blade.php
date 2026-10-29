<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title inertia>{{ config('app.name', 'AfyaNova V4') }}</title>
    <link rel="icon" type="image/svg+xml" href="/afyanova.svg">
    <script>
        (function() {
            var themes = ['white', 'g10', 'g90', 'g100'];
            try {
                var stored = window.localStorage.getItem('afyanova-theme');
                var theme = themes.indexOf(stored) !== -1 ? stored : (stored === 'dark' ? 'g100' : 'white');
                document.documentElement.classList.add('cds--' + theme);
                document.documentElement.style.colorScheme = theme === 'g90' || theme === 'g100' ? 'dark' : 'light';
            } catch (e) {}
        })();
    </script>
    {{--
        React Fast Refresh preamble. @vitejs/plugin-react installs this via
        transformIndexHtml, which never runs for Blade-served HTML — without
        it every React module throws "can't detect preamble" in dev. It must
        execute before the @vite scripts below (module order). Dev only: no
        hot file exists in production, so nothing ships.
    --}}
    @if (is_file(public_path('hot')))
        <script type="module">
            import RefreshRuntime from '{{ trim((string) file_get_contents(public_path('hot'))) }}/@react-refresh';
            RefreshRuntime.injectIntoGlobalHook(window);
            window.$RefreshReg$ = () => {};
            window.$RefreshSig$ = () => (type) => type;
        </script>
    @endif
    @vite(['resources/css/app.css', 'resources/js/app.tsx'])
    @inertiaHead
</head>
<body class="h-full">
    @inertia
</body>
</html>
