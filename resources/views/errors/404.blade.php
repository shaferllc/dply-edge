@extends('errors.layout')

@section('card-width', 'max-w-3xl')

@section('title', __('Page not found'))

@section('content')
    @include('errors.partials.404-experience')
@endsection

@section('extra-actions')
    @php
        $errorContext = app(\App\View\Components\ErrorContext::class)->parse();
    @endphp

    @auth
        @if (empty($errorContext['server']) && empty($errorContext['site']))
            <div class="mt-4 flex flex-wrap items-center justify-center gap-2 text-sm">
                <span class="text-brand-moss">{{ __('Quick links:') }}</span>
                <a href="{{ route('edge.index') }}" class="text-brand-ink underline decoration-brand-ink/30 underline-offset-2 hover:text-brand-forest">
                    {{ __('Applications') }}
                </a>
                <span class="text-brand-moss/50">|</span>
                <a href="{{ route('status-pages.index') }}" class="text-brand-ink underline decoration-brand-ink/30 underline-offset-2 hover:text-brand-forest">
                    {{ __('Status pages') }}
                </a>
                <span class="text-brand-moss/50">|</span>
                <a href="{{ route('settings.profile') }}" class="text-brand-ink underline decoration-brand-ink/30 underline-offset-2 hover:text-brand-forest">
                    {{ __('Settings') }}
                </a>
            </div>
        @endif
    @endauth
@endsection
