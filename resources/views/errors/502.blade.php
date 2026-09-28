@extends('errors.layout')

@section('content')
    <div class="inline-flex items-center justify-center w-20 h-20 rounded-full bg-red-50 mb-6">
        <x-heroicon-o-server-stack class="w-10 h-10 text-red-500" />
    </div>

    <h1 class="text-5xl sm:text-6xl font-bold tracking-tight text-brand-ink mb-4">
        502
    </h1>

    <p class="text-xl sm:text-2xl font-semibold text-brand-ink mb-3">
        {{ __('Bad gateway') }}
    </p>

    <p class="text-base text-brand-moss mb-8 max-w-md mx-auto leading-relaxed">
        {{ __('We received an invalid response from an upstream server. This is usually a temporary issue. Please try again in a few moments.') }}
    </p>
@endsection

@section('smart-actions')
    <div class="mt-8 pt-8 border-t border-brand-ink/10">
        <p class="text-sm font-medium text-brand-ink mb-4">{{ __('What you can try') }}</p>
        <div class="flex flex-wrap items-center justify-center gap-3">
            <button onclick="window.location.reload()" class="inline-flex items-center gap-2 px-5 py-2.5 rounded-lg bg-brand-ink text-brand-cream text-sm font-semibold shadow-sm shadow-brand-ink/10 hover:bg-brand-forest transition-colors cursor-pointer">
                <x-heroicon-o-arrow-path class="w-4 h-4" />
                {{ __('Try again') }}
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
