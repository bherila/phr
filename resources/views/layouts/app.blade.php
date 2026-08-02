<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
  <head>
    @viteReactRefresh
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'PHR | ' . config('app.name', 'Personal Health Record'))</title>
    <meta name="color-scheme" content="dark light">
    <script id="app-initial-data" type="application/json">
      {!! json_encode([
        'appName' => config('app.name', 'Personal Health Record'),
        'appUrl' => config('app.url', ''),
        'authenticated' => auth()->check(),
        'isAdmin' => auth()->check() && auth()->user()->hasRole('admin'),
        'currentUser' => auth()->user() ? [
          'id' => auth()->id(),
          'name' => auth()->user()->name,
          'email' => auth()->user()->email,
          'user_role' => auth()->user()->user_role,
          'last_login_date' => optional(auth()->user()->last_login_date)->toDateTimeString(),
        ] : null,
      ], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) !!}
    </script>
    @stack('data-head')
    <script>
      (function() {
        try {
          var theme = localStorage.getItem('theme') || 'system';
          var d = document.documentElement;
          var isDark = theme === 'dark' || (theme === 'system' && window.matchMedia('(prefers-color-scheme: dark)').matches);
          if (isDark) d.classList.add('dark'); else d.classList.remove('dark');
        } catch (e) { /* no-op */ }
      })();
    </script>
    @vite(['resources/css/app.css'])
    @stack('head')
  </head>
  <body class="phr-shell min-h-screen flex flex-col">
    <main class="flex-1">
      @yield('content')
    </main>

    <footer class="border-t border-border py-6 text-sm text-center text-muted-foreground">
      <span>&copy; {{ date('Y') }} {{ config('app.name', 'Personal Health Record') }}</span>
      @if (auth()->check() && auth()->user()->hasRole('admin'))
        <span aria-hidden="true">&middot;</span>
        <a class="underline underline-offset-4 hover:text-foreground" href="{{ route('uptime') }}">Uptime</a>
      @endif
    </footer>

    @stack('scripts')
  </body>
</html>
