@extends('errors.layout')

@section('content')
    <div class="inline-flex items-center justify-center w-20 h-20 rounded-full bg-brand-sage/20 mb-6">
        <x-heroicon-o-wrench-screwdriver class="w-10 h-10 text-brand-forest" />
    </div>

    <h1 class="text-5xl sm:text-6xl font-bold tracking-tight text-brand-ink mb-4">
        503
    </h1>

    <p class="text-xl sm:text-2xl font-semibold text-brand-ink mb-3">
        {{ __('Service unavailable') }}
    </p>

    <p class="text-base text-brand-moss mb-8 max-w-md mx-auto leading-relaxed">
        {{ __('We are currently performing maintenance or experiencing high traffic. Please check back in a few minutes. We appreciate your patience.') }}
    </p>
@endsection

@section('smart-actions')
    <div class="mt-8 pt-8 border-t border-brand-ink/10">
        <p class="text-sm font-medium text-brand-ink mb-4">{{ __('What you can do') }}</p>
        <div class="flex flex-wrap items-center justify-center gap-3">
            <button onclick="window.location.reload()" class="inline-flex items-center gap-2 px-5 py-2.5 rounded-lg bg-brand-ink text-brand-cream text-sm font-semibold shadow-sm shadow-brand-ink/10 hover:bg-brand-forest transition-colors cursor-pointer">
                <x-heroicon-o-arrow-path class="w-4 h-4" />
                {{ __('Try again') }}
            </button>
            <a href="{{ route('status-pages.index') }}" class="inline-flex items-center gap-2 px-5 py-2.5 rounded-lg border border-brand-ink/20 text-brand-ink text-sm font-medium hover:bg-brand-sand/50 transition-colors">
                <x-heroicon-o-check-circle class="w-4 h-4" />
                {{ __('Status page') }}
            </a>
        </div>

        <p class="mt-4 text-xs text-brand-moss">
            {{ __('If this persists, please check our status page for updates.') }}
        </p>
    </div>
@endsection
