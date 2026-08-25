@php
    $errorContext = app(\App\View\Components\ErrorContext::class)->parse();
@endphp

@extends('errors.layout')

@section('title', $title ?? __('Error'))

@section('content')
    <div class="inline-flex items-center justify-center w-20 h-20 rounded-full {{ $iconBg ?? 'bg-brand-sand/50' }} mb-6">
        @if (isset($icon))
            {{ $icon }}
        @else
            <x-heroicon-o-exclamation-circle class="w-10 h-10 {{ $iconColor ?? 'text-brand-moss' }}" />
        @endif
    </div>

    <h1 class="text-5xl sm:text-6xl font-bold tracking-tight text-brand-ink mb-4">
        {{ $code ?? '??' }}
    </h1>

    <p class="text-xl sm:text-2xl font-semibold text-brand-ink mb-3">
        {{ $title ?? __('An error occurred') }}
    </p>

    <p class="text-base text-brand-moss mb-8 max-w-md mx-auto leading-relaxed">
        {{ $message ?? __('Something went wrong. Please try again or contact support if the problem persists.') }}
    </p>
@endsection

@section('smart-actions')
    <div class="mt-8 pt-8 border-t border-brand-ink/10">
        <p class="text-sm font-medium text-brand-ink mb-4">{{ __('What you can do') }}</p>
        <div class="flex flex-wrap items-center justify-center gap-3">
            <a href="{{ url('/') }}" class="inline-flex items-center gap-2 px-5 py-2.5 rounded-lg bg-brand-ink text-brand-cream text-sm font-semibold shadow-sm shadow-brand-ink/10 hover:bg-brand-forest transition-colors">
                <x-heroicon-o-home class="w-4 h-4" />
                {{ __('Go home') }}
            </a>
            @auth
                <a href="{{ route('dashboard') }}" class="inline-flex items-center gap-2 px-5 py-2.5 rounded-lg border border-brand-ink/20 text-brand-ink text-sm font-medium hover:bg-brand-sand/50 transition-colors">
                    <x-heroicon-o-squares-2x2 class="w-4 h-4" />
                    {{ __('Dashboard') }}
                </a>
            @else
                <a href="{{ route('login') }}" class="inline-flex items-center gap-2 px-5 py-2.5 rounded-lg border border-brand-ink/20 text-brand-ink text-sm font-medium hover:bg-brand-sand/50 transition-colors">
                    <x-heroicon-o-arrow-right-end-on-rectangle class="w-4 h-4" />
                    {{ __('Log in') }}
                </a>
            @endauth
        </div>

    </div>
@endsection
