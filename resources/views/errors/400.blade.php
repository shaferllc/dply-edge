@extends('errors.layout')

@section('title', __('Bad request'))

@section('content')
    <div class="inline-flex items-center justify-center w-20 h-20 rounded-full bg-amber-50 mb-6">
        <x-heroicon-o-x-circle class="w-10 h-10 text-amber-500" />
    </div>

    <h1 class="text-5xl sm:text-6xl font-bold tracking-tight text-brand-ink mb-4">
        400
    </h1>

    <p class="text-xl sm:text-2xl font-semibold text-brand-ink mb-3">
        {{ __('Bad request') }}
    </p>

    <p class="text-base text-brand-moss mb-8 max-w-md mx-auto leading-relaxed">
        {{ __('The request could not be understood by the server due to malformed syntax. Please check your input and try again.') }}
    </p>
@endsection

@section('smart-actions')
    <div class="mt-8 pt-8 border-t border-brand-ink/10">
        <p class="text-sm font-medium text-brand-ink mb-4">{{ __('What you can try') }}</p>
        <div class="flex flex-wrap items-center justify-center gap-3">
            <button onclick="window.history.back()" class="inline-flex items-center gap-2 px-5 py-2.5 rounded-lg bg-brand-ink text-brand-cream text-sm font-semibold shadow-sm shadow-brand-ink/10 hover:bg-brand-forest transition-colors cursor-pointer">
                <x-heroicon-o-arrow-uturn-left class="w-4 h-4" />
                {{ __('Go back') }}
            </button>
            <a href="{{ url('/') }}" class="inline-flex items-center gap-2 px-5 py-2.5 rounded-lg border border-brand-ink/20 text-brand-ink text-sm font-medium hover:bg-brand-sand/50 transition-colors">
                <x-heroicon-o-home class="w-4 h-4" />
                {{ __('Go home') }}
            </a>
        </div>

    </div>
@endsection
