{{--
    Every full-page error, in the marketing site's Terminal look (dark, lime,
    font-terminal). A page passes its status code; the copy and actions for
    each code live here. errors/minimal (and anything else) may pass its own
    $title / $message. The page title comes from @head.

    Livewire requests get errors/livewire-404 instead (no header), and
    redis-unreachable / serverless-not-found stay standalone on purpose.
--}}
@php
    $code = (int) ($code ?? (isset($exception) && method_exists($exception, 'getStatusCode') ? $exception->getStatusCode() : 500));

    $pages = [
        400 => [__('Bad request'), __('The request could not be read. Check the address or the form and try again.'), 'back'],
        401 => [__('Log in to continue'), __('This page needs an account. Log in, or create one to get started.'), 'login'],
        403 => [__('Not allowed'), __('Your account cannot open this page. An owner of the organization can give you access.'), 'home'],
        404 => [__('Page not found'), __('Nothing is served at this address. It may have moved, or the link may be wrong.'), 'home'],
        419 => [__('Page expired'), __('The form waited too long and its security token expired. Reload the page and send it again.'), 'reload'],
        429 => [__('Too many requests'), __('That was a lot of requests in a short time. Wait a moment, then try again.'), 'reload'],
        500 => [__('Something went wrong'), __('An error on our side stopped this page. We have been notified. Try again in a moment.'), 'reload'],
        502 => [__('Bad gateway'), __('A service behind this page sent back a bad answer. This is usually brief. Try again in a moment.'), 'reload'],
        503 => [__('Back shortly'), __('dply is down for maintenance or under heavy load. Try again in a few minutes.'), 'reload'],
        504 => [__('Gateway timeout'), __('A service behind this page took too long to answer. Try again in a moment.'), 'reload'],
    ];
    [$defaultTitle, $defaultMessage, $primary] = $pages[$code] ?? [__('Something went wrong'), __('Try again, or contact support if it keeps happening.'), 'home'];
    $title ??= $defaultTitle;
    $message ??= $defaultMessage;
    $serverError = $code >= 500;

    $errorContext = app(\App\View\Components\ErrorContext::class)->parse();
    $suggestions = $errorContext['suggestions'] ?? [];
    $referrer = $errorContext['referrer'] ?? null;
    if ($referrer !== null && str_starts_with($referrer, url()->current())) {
        $referrer = null;
    }

    $ref = $serverError ? \App\Support\Debug\DebugReference::current() : null;
    $detail = $serverError && \App\Support\Debug\DebugExceptionDetail::viewerMaySee() ? \App\Support\Debug\DebugExceptionDetail::current() : null;

    $btn = 'font-terminal inline-flex items-center gap-2 px-5 py-3 text-sm font-bold transition-colors';
    $solid = $btn.' bg-edge-lime text-edge-void hover:bg-edge-lime-bright';
    $ghost = $btn.' border border-edge-line text-edge-text hover:border-edge-lime hover:text-edge-lime';
    $codeTone = $serverError ? 'text-[#ff8a65]' : 'text-edge-lime';
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="dark">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">

    @include('partials.theme-head')

    @head
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @livewireStyles
    <style>[x-cloak]{display:none!important}</style>
</head>
<body class="flex min-h-dvh flex-col bg-edge-void font-display text-edge-text antialiased">
    @include('partials.skip-link')

    <x-edge-marketing-header />

    <main id="main-content" tabindex="-1" class="flex flex-1 items-center border-b border-edge-line">
        <div class="mx-auto grid w-full max-w-6xl items-center gap-12 px-6 py-16 lg:grid-cols-[minmax(0,1fr)_minmax(0,25rem)] lg:px-10 lg:py-24">
            <div>
                <p class="font-terminal text-[11px] uppercase tracking-[0.2em] {{ $codeTone }}">{{ __('Error :code', ['code' => $code]) }}</p>
                <h1 class="mt-4 text-4xl font-bold leading-[1.05] tracking-[-0.03em] sm:text-5xl">{{ $title }}</h1>
                <p class="mt-5 max-w-xl text-base leading-7 text-edge-mute">{{ $message }}</p>

                @if ($detail)
                    <details class="mt-6 max-w-xl border border-edge-line bg-edge-panel">
                        <summary class="font-terminal cursor-pointer select-none px-4 py-3 text-xs uppercase tracking-[0.16em] text-edge-mute hover:text-edge-text">{{ __('Technical details') }}</summary>
                        <div class="space-y-2 border-t border-edge-line px-4 py-3">
                            <p class="font-terminal break-words text-xs leading-relaxed text-edge-text">{{ $detail['message'] }}</p>
                            <p class="font-terminal text-xs text-edge-faint">{{ class_basename($detail['class']) }} · {{ $detail['file'] }}:{{ $detail['line'] }}</p>
                            <button type="button" class="font-terminal text-xs text-edge-lime hover:text-edge-lime-bright"
                                    onclick="navigator.clipboard && navigator.clipboard.writeText(@js($detail['message'].' ('.$detail['class'].' at '.$detail['file'].':'.$detail['line'].')'))">{{ __('Copy details') }}</button>
                        </div>
                    </details>
                @endif

                <div class="mt-8 flex flex-wrap items-center gap-3">
                    @switch($primary)
                        @case('reload')
                            <button type="button" onclick="window.location.reload()" class="{{ $solid }}">{{ __('Try again') }} <span aria-hidden="true">↻</span></button>
                            @break
                        @case('back')
                            <button type="button" onclick="history.length > 1 ? history.back() : (location.href = '/')" class="{{ $solid }}"><span aria-hidden="true">←</span> {{ __('Go back') }}</button>
                            @break
                        @case('login')
                            <a href="{{ route('login') }}" class="{{ $solid }}">{{ __('Log in') }} <span aria-hidden="true">→</span></a>
                            <a href="{{ route('register') }}" class="{{ $ghost }}">{{ __('Create an account') }}</a>
                            @break
                        @default
                            @auth
                                <a href="{{ route('dashboard') }}" class="{{ $solid }}">{{ __('Go to your apps') }} <span aria-hidden="true">→</span></a>
                            @else
                                <a href="{{ url('/') }}" class="{{ $solid }}">{{ __('Go home') }} <span aria-hidden="true">→</span></a>
                            @endauth
                    @endswitch
                    @if ($primary !== 'home' && $primary !== 'login')
                        <a href="{{ auth()->check() ? route('dashboard') : url('/') }}" class="{{ $ghost }}">{{ auth()->check() ? __('Your apps') : __('Home') }}</a>
                    @endif
                    @if ($referrer)
                        <a href="{{ $referrer }}" class="font-terminal text-sm text-edge-mute transition-colors hover:text-edge-text">{{ __('Previous page') }}</a>
                    @endif
                </div>

                @if ($suggestions !== [])
                    <div class="mt-10 max-w-xl border-t border-edge-line pt-6">
                        <p class="font-terminal text-[11px] uppercase tracking-[0.2em] text-edge-faint">{{ __('Maybe you meant') }}</p>
                        <ul class="mt-3 divide-y divide-edge-line border-y border-edge-line">
                            @foreach ($suggestions as $suggestion)
                                <li>
                                    <a href="{{ $suggestion['url'] }}" class="flex items-center justify-between gap-4 py-3 text-sm text-edge-text transition-colors hover:text-edge-lime">
                                        {{ $suggestion['label'] }} <span class="font-terminal text-edge-lime" aria-hidden="true">→</span>
                                    </a>
                                </li>
                            @endforeach
                        </ul>
                    </div>
                @endif
            </div>

            {{-- The request, as a terminal would show it. Decorative; the copy on the left says it all. --}}
            <div class="font-terminal overflow-hidden border border-edge-line bg-edge-panel text-xs leading-6" aria-hidden="true">
                <div class="flex items-center gap-1.5 border-b border-edge-line px-4 py-2.5">
                    <span class="h-2 w-2 rounded-full bg-edge-line"></span><span class="h-2 w-2 rounded-full bg-edge-line"></span><span class="h-2 w-2 rounded-full bg-edge-line"></span>
                    <span class="ml-2 text-[10px] uppercase tracking-[0.16em] text-edge-faint">{{ __('request') }}</span>
                </div>
                <div class="space-y-0.5 px-4 py-4">
                    <p class="truncate"><span class="text-edge-lime">$</span> <span class="text-edge-dim">curl -sI</span> {{ \Illuminate\Support\Str::limit(request()->fullUrl(), 64) }}</p>
                    <p class="{{ $codeTone }}">HTTP/2 {{ $code }}</p>
                    <p class="text-edge-faint">server: dply-edge</p>
                    <p class="text-edge-faint">date: {{ now()->toRfc7231String() }}</p>
                    @if ($ref)
                        <p class="text-edge-faint">x-dply-ref: <button type="button" class="text-edge-text hover:text-edge-lime" title="{{ __('Copy reference') }}" onclick="navigator.clipboard && navigator.clipboard.writeText('{{ $ref }}')">{{ $ref }}</button></p>
                    @endif
                    <p class="pt-3 text-edge-mute"><span class="text-edge-lime">$</span> <span class="inline-block h-3.5 w-1.5 translate-y-0.5 animate-pulse bg-edge-lime"></span></p>
                </div>
            </div>
            @if ($ref)
                <p class="text-sm text-edge-mute lg:col-start-1">{{ __('Quote reference :ref to support so we can find what happened.', ['ref' => $ref]) }}</p>
            @endif
        </div>
    </main>

    <x-edge-marketing-footer />
    @livewireScripts
</body>
</html>
