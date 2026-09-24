@extends('errors.layout')

@section('card-width', 'max-w-3xl')

@section('title', __('Access forbidden'))

@section('content')
    @include('errors.partials.403-experience')
@endsection

@section('smart-actions')
    <div class="mt-8 pt-8 border-t border-brand-ink/10">
        @auth
            <div class="flex flex-wrap items-center justify-center gap-2 text-sm">
                <span class="text-brand-moss">{{ __('Quick links:') }}</span>
                <a href="{{ route('dashboard') }}" class="text-brand-ink underline decoration-brand-ink/30 underline-offset-2 hover:text-brand-forest">
                    {{ __('Servers') }}
                </a>
                <span class="text-brand-moss/50">|</span>
                <a href="{{ route('settings.index') }}" class="text-brand-ink underline decoration-brand-ink/30 underline-offset-2 hover:text-brand-forest">
                    {{ __('Settings') }}
                </a>
                <span class="text-brand-moss/50">|</span>
                <a href="{{ route('settings.profile') }}" class="text-brand-ink underline decoration-brand-ink/30 underline-offset-2 hover:text-brand-forest">
                    {{ __('Profile') }}
                </a>
            </div>
        @endauth

    </div>
@endsection
