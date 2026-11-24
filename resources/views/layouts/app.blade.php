<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="description" content="myDoc helps patients find the right care for their needs.">

        <title>@yield('title', 'myDoc')</title>

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
            @if (session('status'))
                <div class="container pt-4">
                    <x-ui.alert class="mb-0">{{ session('status') }}</x-ui.alert>
                </div>
            @endif

            @if (session('warning'))
                <div class="container pt-4">
                    <x-ui.alert type="warning" role="alert" class="mb-0">{{ session('warning') }}</x-ui.alert>
                </div>
            @endif

            @if ($errors->any())
                <div class="container pt-4">
                    <x-ui.alert type="danger" role="alert" class="mb-0">
                        <p class="fw-semibold mb-2">Please review the highlighted fields.</p>
                        <ul class="mb-0 ps-3">
                            @foreach ($errors->all() as $error)
                                <li>{{ $error }}</li>
                            @endforeach
                        </ul>
                    </x-ui.alert>
                </div>
            @endif

            @yield('content')
        </main>

        @unless ($hasSidebar)
            <x-layout.footer />
        @endunless
    </body>
</html>
