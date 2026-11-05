{{-- Anonymous component layout: used as <x-layouts.app title="...">...</x-layouts.app>. --}}
@props(['title' => null])

<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    {{-- Read by Echo: private channels are authorized with POST /broadcasting/auth (CSRF-protected). --}}
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $title ? $title.' — ' : '' }}{{ config('app.name') }}</title>
    @fonts
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-screen bg-stone-50 font-sans text-stone-800 antialiased">
    <header class="border-b border-stone-200 bg-white">
        <nav class="mx-auto flex max-w-7xl flex-wrap items-center gap-x-6 gap-y-2 px-4 py-3">
            <a href="{{ route('home') }}" class="font-semibold text-amber-700">{{ config('app.name') }}</a>

            @php
                $links = [
                    'books.index' => __('ui.nav.catalog'),
                    'authors.index' => __('ui.nav.authors'),
                    'genres.index' => __('ui.nav.genres'),
                    'about' => __('ui.nav.about'),
                ];
            @endphp

            @foreach ($links as $routeName => $label)
                <a href="{{ route($routeName) }}"
                   @class([
                       'text-sm hover:text-amber-700',
                       'font-semibold text-amber-700' => request()->routeIs(Str::before($routeName, '.').'*'),
                   ])>{{ $label }}</a>
            @endforeach

            {{-- Staff links: custom @role directive (Blade::if) and @can for a gate. --}}
            @role('manager', 'admin')
                <a href="{{ route('manage.warehouses.index') }}" @class(['text-sm hover:text-amber-700', 'font-semibold text-amber-700' => request()->routeIs('manage.*')])>{{ __('ui.nav.manage') }}</a>
            @endrole
            @can('view-reports')
                <a href="{{ route('reports.stock') }}" @class(['text-sm hover:text-amber-700', 'font-semibold text-amber-700' => request()->routeIs('reports.*')])>{{ __('ui.nav.reports') }}</a>
            @endcan
            @role('admin')
                <a href="{{ route('admin.dashboard') }}" @class(['text-sm hover:text-amber-700', 'font-semibold text-amber-700' => request()->routeIs('admin.*')])>{{ __('ui.nav.admin') }}</a>
            @endrole

            <span class="ml-auto flex flex-wrap items-center gap-4 text-sm text-stone-500">
                @if (App\Http\Middleware\LabAccess::allows(auth()->user()))
                    @php
                        $labs = [
                            'lab.relations' => __('ui.nav.lab_relations'),
                            'lab.n-plus-one' => __('ui.nav.lab_n_plus_one'),
                            'lab.collections' => __('ui.nav.lab_collections'),
                            'lab.models' => __('ui.nav.lab_models'),
                            'lab.xss' => __('ui.nav.lab_xss'),
                            'lab.queues' => __('ui.nav.lab_queues'),
                            'lab.realtime' => __('ui.nav.lab_realtime'),
                        ];
                    @endphp
                    {{-- Native <details> dropdown: opens without JS; resources/js/app.js closes it
                         on an outside click and on Escape. --}}
                    <details class="relative" data-dropdown>
                        <summary @class([
                            'flex cursor-pointer list-none items-center gap-1 hover:text-amber-700 [&::-webkit-details-marker]:hidden',
                            'font-semibold text-amber-700' => request()->routeIs('lab.*'),
                        ])>
                            {{ __('ui.nav.labs') }} <span aria-hidden="true" class="text-xs">▾</span>
                        </summary>
                        <div class="absolute right-0 z-20 mt-2 w-56 rounded border border-stone-200 bg-white py-1 shadow-lg">
                            @foreach ($labs as $routeName => $label)
                                <a href="{{ route($routeName) }}"
                                   @class([
                                       'block px-4 py-1.5 text-stone-700 hover:bg-stone-100 hover:text-amber-700',
                                       'font-semibold text-amber-700' => request()->routeIs($routeName.'*'),
                                   ])
                                   @if (request()->routeIs($routeName.'*')) aria-current="page" @endif>{{ $label }}</a>
                            @endforeach
                        </div>
                    </details>
                @endif

                @role('manager', 'admin')
                    {{-- One COUNT query per page for staff. --}}
                    @php($unread = auth()->user()->unreadNotifications()->count())
                    <a href="{{ route('notifications.index') }}" class="relative hover:text-amber-700" data-bell data-user-id="{{ auth()->id() }}"
                       title="{{ trans_choice('ui.notifications.unread', $unread, ['count' => $unread]) }}">
                        <span aria-hidden="true">🔔</span><span class="sr-only">{{ __('ui.notifications.title') }}</span>
                        @if ($unread > 0)
                            <span class="absolute -right-2 -top-2 rounded-full bg-red-600 px-1.5 text-xs leading-5 text-white" data-unread>{{ $unread }}</span>
                        @endif
                    </a>
                @endrole
                @auth
                    <span class="text-stone-700" data-user>{{ auth()->user()->name }} <span class="text-xs text-stone-400">({{ auth()->user()->role->label() }})</span></span>
                    <form method="POST" action="{{ route('logout') }}">
                        @csrf
                        <button type="submit" class="hover:text-amber-700">{{ __('ui.nav.logout') }}</button>
                    </form>
                @endauth
                @guest
                    <a href="{{ route('login') }}" class="hover:text-amber-700">{{ __('ui.nav.login') }}</a>
                    <a href="{{ route('register') }}" class="hover:text-amber-700">{{ __('ui.nav.register') }}</a>
                @endguest
            </span>
        </nav>
    </header>

    @auth
        @unless (auth()->user()->hasVerifiedEmail())
            <div class="mx-auto mt-4 max-w-7xl px-4">
                <div class="rounded border border-amber-200 bg-amber-50 px-4 py-2 text-sm text-amber-900">
                    {{ __('ui.auth.unverified_banner') }} <a href="{{ route('verification.notice') }}" class="underline">{{ __('ui.auth.verify_link') }}</a>
                </div>
            </div>
        @endunless
    @endauth

    {{-- Flash area: messages put into the session with ->with('status', '...').
         @session: renders only when the key exists and exposes it as $value. --}}
    @session('status')
        <div class="mx-auto mt-4 max-w-7xl px-4">
            <div class="rounded border border-emerald-200 bg-emerald-50 px-4 py-2 text-sm text-emerald-800" role="status">
                {{ $value }}
            </div>
        </div>
    @endsession

    <main class="mx-auto max-w-7xl px-4 py-6">
        @if ($title)
            <h1 class="mb-6 text-2xl font-semibold">{{ $title }}</h1>
        @endif

        {{ $slot }}
    </main>

    <footer class="mx-auto max-w-7xl px-4 py-8 text-xs text-stone-400">
        {{ __('ui.layout.tagline') }}
        {{-- @env: rendered only in the given environment(s). --}}
        @env('local')
            <span class="ml-2 rounded bg-amber-100 px-1.5 py-0.5 text-amber-800">{{ __('ui.layout.local_env') }}</span>
        @endenv
    </footer>

    {{-- Scripts pushed by pages/components with @push('scripts'). --}}
    @stack('scripts')
</body>
</html>
