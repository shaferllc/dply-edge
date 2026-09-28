@extends('errors.layout')

@section('content')
    <div class="inline-flex items-center justify-center w-20 h-20 rounded-full bg-red-50 mb-6">
        <x-heroicon-o-exclamation-triangle class="w-10 h-10 text-red-500" />
    </div>

    <h1 class="text-5xl sm:text-6xl font-bold tracking-tight text-brand-ink mb-4">
        500
    </h1>

    <p class="text-xl sm:text-2xl font-semibold text-brand-ink mb-3">
        {{ __('Something went wrong') }}
    </p>

    <p class="text-base text-brand-moss mb-8 max-w-md mx-auto leading-relaxed">
        {{ __('We are sorry, but something went wrong on our end. Our team has been notified and we are working to fix the issue. Please try again later.') }}
    </p>

    @php
        $dplyRef = \App\Support\Debug\DebugReference::current();
        $dplyDetail = \App\Support\Debug\DebugExceptionDetail::viewerMaySee()
            ? \App\Support\Debug\DebugExceptionDetail::current()
            : null;
    @endphp
    @if ($dplyRef)
        <p class="text-xs text-brand-mist mb-1">
            {{ __('Reference') }}
            <button type="button"
                onclick="navigator.clipboard && navigator.clipboard.writeText('{{ $dplyRef }}')"
                class="ml-1 font-mono text-brand-moss hover:text-brand-ink transition-colors cursor-pointer"
                title="{{ __('Copy reference') }}">{{ $dplyRef }}</button>
        </p>
        <p class="text-xs text-brand-mist/80 {{ $dplyDetail ? 'mb-4' : 'mb-8' }}">{{ __('Quote this reference to support to help us find what happened.') }}</p>
    @endif

    @if ($dplyDetail)
        <details class="mx-auto mb-8 max-w-lg rounded-xl border border-brand-ink/10 bg-brand-sand/30 text-left open:shadow-sm">
            <summary class="cursor-pointer select-none px-4 py-3 text-sm font-semibold text-brand-ink hover:bg-brand-sand/50">
                {{ __('Technical details') }}
            </summary>
            <div class="space-y-2 border-t border-brand-ink/10 px-4 py-3">
                <p class="break-words font-mono text-xs leading-relaxed text-brand-ink">{{ $dplyDetail['message'] }}</p>
                <p class="font-mono text-xs text-brand-mist">{{ class_basename($dplyDetail['class']) }} · {{ $dplyDetail['file'] }}:{{ $dplyDetail['line'] }}</p>
                <button
                    type="button"
                    onclick="navigator.clipboard && navigator.clipboard.writeText(@js($dplyDetail['message'].' ('.$dplyDetail['class'].' at '.$dplyDetail['file'].':'.$dplyDetail['line'].')'))"
                    class="text-xs font-semibold text-brand-moss hover:text-brand-ink"
                >
                    {{ __('Copy details') }}
                </button>
            </div>
        </details>
    @endif
@endsection

@section('smart-actions')
    <div class="mt-8 pt-8 border-t border-brand-ink/10">
        <p class="text-sm font-medium text-brand-ink mb-4">{{ __('What you can try') }}</p>
        <div class="flex flex-wrap items-center justify-center gap-3">
            <button onclick="window.location.reload()" class="inline-flex items-center gap-2 px-5 py-2.5 rounded-lg bg-brand-ink text-brand-cream text-sm font-semibold shadow-sm shadow-brand-ink/10 hover:bg-brand-forest transition-colors cursor-pointer">
                <x-heroicon-o-arrow-path class="w-4 h-4" />
                {{ __('Refresh page') }}
            </button>
            <a href="{{ url('/') }}" class="inline-flex items-center gap-2 px-5 py-2.5 rounded-lg border border-brand-ink/20 text-brand-ink text-sm font-medium hover:bg-brand-sand/50 transition-colors">
                <x-heroicon-o-home class="w-4 h-4" />
                {{ __('Go home') }}
            </a>
        </div>

        @auth
            <div class="mt-4">
                <a href="{{ route('status-pages.index') }}" class="text-sm text-brand-moss hover:text-brand-ink transition-colors inline-flex items-center gap-1">
                    <x-heroicon-o-check-circle class="w-3.5 h-3.5" />
                    {{ __('Check system status') }}
                </a>
            </div>
        @endauth
    </div>
@endsection
