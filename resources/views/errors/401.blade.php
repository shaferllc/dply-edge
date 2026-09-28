@extends('errors.layout')

@section('content')
    <div class="inline-flex items-center justify-center w-20 h-20 rounded-full bg-brand-sand/50 mb-6">
        <x-heroicon-o-lock-closed class="w-10 h-10 text-brand-moss" />
    </div>

    <h1 class="text-5xl sm:text-6xl font-bold tracking-tight text-brand-ink mb-4">
        401
    </h1>

    <p class="text-xl sm:text-2xl font-semibold text-brand-ink mb-3">
        {{ __('Authentication required') }}
    </p>

    <p class="text-base text-brand-moss mb-8 max-w-md mx-auto leading-relaxed">
        {{ __('You need to log in to access this page. Please sign in with your credentials to continue.') }}
    </p>
@endsection

@section('smart-actions')
    @guest
        <div class="mt-8 pt-8 border-t border-brand-ink/10">
            <p class="text-sm font-medium text-brand-ink mb-4">{{ __('Sign in to continue') }}</p>
            <div class="flex flex-wrap items-center justify-center gap-3">
                <a href="{{ route('login') }}" class="inline-flex items-center gap-2 px-5 py-2.5 rounded-lg bg-brand-ink text-brand-cream text-sm font-semibold shadow-sm shadow-brand-ink/10 hover:bg-brand-forest transition-colors">
                    <x-heroicon-o-arrow-right-end-on-rectangle class="w-4 h-4" />
                    {{ __('Log in') }}
                </a>
                <a href="{{ route('register') }}" class="inline-flex items-center gap-2 px-5 py-2.5 rounded-lg border border-brand-ink/20 text-brand-ink text-sm font-medium hover:bg-brand-sand/50 transition-colors">
                    <x-heroicon-o-user-plus class="w-4 h-4" />
                    {{ __('Create account') }}
                </a>
            </div>
        </div>
    @endguest
@endsection
