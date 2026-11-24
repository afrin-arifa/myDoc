<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="description" content="myDoc helps patients find the right care for their needs.">

        <title>@yield('title', 'myDoc')</title>

        <link rel="icon" href="{{ asset('favicon.ico') }}?v=2" sizes="32x32">
        <link rel="icon" href="{{ asset('favicon.svg') }}?v=2" type="image/svg+xml">
        <link rel="apple-touch-icon" href="{{ asset('apple-touch-icon.png') }}?v=2">

        @fonts
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>
    @php($hasSidebar = in_array(auth()->user()?->role, ['doctor', 'admin', App\Models\User::ROLE_ASSISTANT], true))
    <body @class(['app-body', 'app-body--side' => $hasSidebar, 'app-body--guest' => auth()->guest()])>
        <a class="skip-link" href="#main-content">Skip to main content</a>
        <header class="app-header">
            <x-layout.navbar />
        </header>

        <main class="app-main" id="main-content" tabindex="-1">
            @auth
                <x-layout.alerts class="container" />
            @endauth

            @yield('content')
        </main>

        @unless ($hasSidebar)
            <x-layout.footer />
        @endunless
    </body>
</html>
