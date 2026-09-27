@php
    $navBase = 'flex w-full items-center gap-2 rounded-lg px-3 py-2 text-sm font-medium transition-colors';
    $navOn = 'bg-brand-sand/70 text-brand-ink border border-brand-ink/10 shadow-sm';
    $navOff = 'text-brand-moss border border-transparent hover:bg-brand-sand/40 hover:text-brand-ink';
    $navIcon = 'h-5 w-5 shrink-0 opacity-90';
    $subNavBase = 'flex w-full items-center rounded-lg px-3 py-1.5 text-sm transition-colors';
    $subNavOn = 'bg-brand-sage/10 text-brand-ink font-medium';
    $subNavOff = 'text-brand-moss hover:bg-brand-sand/30 hover:text-brand-ink';

    $overviewActive = request()->routeIs('admin.overview', 'admin.dashboard');
    $operationsActive = request()->routeIs('admin.operations');
    $auditActive = request()->routeIs('admin.audit');
    $organizationsActive = request()->routeIs('admin.organizations.*');
    $usersActive = request()->routeIs('admin.users.*');
    $betaInvitesActive = request()->routeIs('admin.beta-invites');
    $comingSoonAccessActive = request()->routeIs('admin.coming-soon-access');
    $connectionsActive = request()->routeIs('admin.connections');
@endphp

<nav aria-label="{{ __('Platform admin navigation') }}" class="dply-surface-nav sticky top-24 space-y-1">
    <div class="mb-4 px-3">
        <p class="text-xs font-semibold uppercase tracking-[0.14em] text-brand-mist">{{ __('Platform admin') }}</p>
        <p class="mt-1 text-xs text-brand-moss">{{ __('Operations, organizations and access') }}</p>
    </div>

    <a href="{{ route('admin.overview') }}" wire:navigate @class([$navBase, $overviewActive ? $navOn : $navOff])>
        <x-heroicon-o-home class="{{ $navIcon }}" />
        {{ __('Overview') }}
    </a>

    <a href="{{ route('admin.operations') }}" wire:navigate @class([$navBase, $operationsActive ? $navOn : $navOff])>
        <x-heroicon-o-cpu-chip class="{{ $navIcon }}" />
        {{ __('Operations') }}
    </a>

    <a href="{{ route('admin.audit') }}" wire:navigate @class([$navBase, $auditActive ? $navOn : $navOff])>
        <x-heroicon-o-clipboard-document-list class="{{ $navIcon }}" />
        {{ __('Audit log') }}
    </a>


    <a href="{{ route('admin.organizations.index') }}" wire:navigate @class([$navBase, $organizationsActive ? $navOn : $navOff])>
        <x-heroicon-o-building-office-2 class="{{ $navIcon }}" />
        {{ __('Organizations') }}
    </a>
    <a href="{{ route('admin.users.index') }}" wire:navigate @class([$navBase, $usersActive ? $navOn : $navOff])>
        <x-heroicon-o-users class="{{ $navIcon }}" />
        {{ __('Users') }}
    </a>
    <a href="{{ route('admin.beta-invites') }}" wire:navigate @class([$navBase, $betaInvitesActive ? $navOn : $navOff])>
        <x-heroicon-o-sparkles class="{{ $navIcon }}" />
        {{ __('Beta invites') }}
    </a>
    <a href="{{ route('admin.coming-soon-access') }}" wire:navigate @class([$navBase, $comingSoonAccessActive ? $navOn : $navOff])>
        <x-heroicon-o-lock-closed class="{{ $navIcon }}" />
        {{ __('Coming-soon access') }}
    </a>
    <a href="{{ route('admin.connections') }}" wire:navigate @class([$navBase, $connectionsActive ? $navOn : $navOff])>
        <x-heroicon-o-link class="{{ $navIcon }}" />
        {{ __('Connections') }}
    </a>
</nav>
