@props([
    'organization',
    'section' => 'overview',
    /** Breadcrumb trail items, rendered above the whole shell (nav + content). */
    'breadcrumb' => null,
    /**
     * When set, wrap the content column in merged chrome (sand identity header,
     * optional stats/tabs strips, hairline body, optional sand footer) — same
     * composition as Fleet / site workspace panels.
     */
    'title' => null,
    'description' => null,
    'icon' => 'heroicon-o-building-office-2',
    /**
     * Opt-in dense chrome: one-line workspace-panel-head + stats as their own
     * strip (same composition as profile-shell dense). Callers unchanged when omitted.
     */
    'dense' => false,
])

@php
    $org = $organization;
    $is = fn (string $key): bool => $section === $key;
    $navBase = 'flex items-center gap-2.5 rounded-lg px-3 py-2 text-sm font-medium transition-colors';
    $link = fn (string $key) => $is($key)
        ? 'bg-brand-sand/70 text-brand-ink border border-brand-ink/10 shadow-sm'
        : 'text-brand-moss hover:bg-brand-sand/40 hover:text-brand-ink border border-transparent';
    $docNavOn = 'bg-brand-sand/70 text-brand-ink border border-brand-ink/10 shadow-sm';
    $docNavOff = 'text-brand-moss hover:bg-brand-sand/40 hover:text-brand-ink border border-transparent';
    $ni = 'h-[1.125rem] w-[1.125rem] shrink-0 opacity-90';
    $useMergedChrome = filled($title);
@endphp

@if (! empty($breadcrumb))
    {{-- Breadcrumb sits above the whole shell (nav + content), full width,
         matching the settings layout. The contextual docs button mirrors the
         server/site workspaces: the slug is resolved once here from the current
         route so it survives wire:navigate renders. --}}
    <x-breadcrumb-trail
        :items="$breadcrumb"
        doc-contextual
        :contextual-doc-slug="null"
    />
@endif

<div class="lg:grid lg:grid-cols-12 lg:gap-10">
    <aside class="sm:col-span-3 mb-8 lg:mb-0 shrink-0">
        <div class="dply-surface-nav">
            <p class="text-xs font-semibold uppercase tracking-wider text-brand-moss">{{ __('Organization') }}</p>
            <div class="mt-1 flex items-center gap-2">
                @if ($org->hasIcon())
                    {{-- onerror: never show the broken-image glyph when the stored
                         icon file is gone — swap to the initials fallback beside it. --}}
                    <img src="{{ $org->iconUrl() }}" alt=""
                        onerror="this.style.display='none'; if (this.nextElementSibling) this.nextElementSibling.style.display='flex';"
                        class="h-6 w-6 shrink-0 rounded-md object-cover ring-1 ring-brand-ink/10" />
                    <span class="h-6 w-6 shrink-0 items-center justify-center rounded-md bg-brand-moss/15 text-[0.625rem] font-semibold text-brand-moss ring-1 ring-brand-ink/10" style="display: none;" aria-hidden="true">{{ $org->initials() }}</span>
                @else
                    <span class="flex h-6 w-6 shrink-0 items-center justify-center rounded-md bg-brand-moss/15 text-[0.625rem] font-semibold text-brand-moss ring-1 ring-brand-ink/10" aria-hidden="true">{{ $org->initials() }}</span>
                @endif
                <p class="font-semibold text-brand-ink truncate" title="{{ $org->name }}">{{ $org->name }}</p>
            </div>
            <nav class="mt-4 space-y-0.5" aria-label="{{ __('Organization navigation') }}">
                {{-- Overview is the workspace root — pinned first; everything below is alphabetical. --}}
                <a
                    href="{{ route('organizations.show', $org) }}"
                    wire:navigate
                    @class([$navBase, $link('overview')])
                >
                    <x-heroicon-o-squares-2x2 class="{{ $ni }}" aria-hidden="true" />
                    {{ __('Overview') }}
                </a>
                @if ($org->hasAdminAccess(auth()->user()))
                    <a
                        href="{{ route('organizations.activity', $org) }}"
                        wire:navigate
                        @class([$navBase, $link('activity')])
                    >
                        <x-heroicon-o-clock class="{{ $ni }}" aria-hidden="true" />
                        {{ __('Activity') }}
                    </a>
                    {{-- Automation folded into General settings — email defaults,
                         Cloud alerts, Edge data region, and the org-wide API token
                         list all live there now. --}}
                @endif
                @can('update', $org)
                    <a
                        href="{{ route('billing.show', $org) }}"
                        wire:navigate
                        @class([$navBase, $link('billing')])
                    >
                        <x-heroicon-o-credit-card class="{{ $ni }}" aria-hidden="true" />
                        {{ __('Billing & plan') }}
                    </a>
                    <a
                        href="{{ route('billing.analytics', $org) }}"
                        wire:navigate
                        @class([$navBase, $link('billing-analytics')])
                    >
                        <x-heroicon-o-chart-bar class="{{ $ni }}" aria-hidden="true" />
                        {{ __('Billing analytics') }}
                    </a>
                @endcan
                {{-- Realtime and Queues moved to the Services nav row (/realtime,
                     /queues). They are products, not organization settings — see
                     docs/adr/managed-services-tier.md, decision 1. The old
                     org-scoped URLs still resolve, via OrgScopedRedirectController. --}}
                @can('viewAny', \App\Models\ProviderCredential::class)
                    <a
                        href="{{ route('organizations.credentials', $org) }}"
                        wire:navigate
                        @class([$navBase, $link('providers')])
                    >
                        <x-heroicon-o-key class="{{ $ni }}" aria-hidden="true" />
                        {{ __('Credentials') }}
                    </a>
                @endcan
                @can('update', $org)
                    <a
                        href="{{ route('organizations.settings', $org) }}"
                        wire:navigate
                        @class([$navBase, $link('general')])
                    >
                        <x-heroicon-o-cog-6-tooth class="{{ $ni }}" aria-hidden="true" />
                        {{ __('General') }}
                    </a>
                    <a
                        href="{{ route('billing.invoices', $org) }}"
                        wire:navigate
                        @class([$navBase, $link('invoices')])
                    >
                        <x-heroicon-o-document-text class="{{ $ni }}" aria-hidden="true" />
                        {{ __('Invoices') }}
                    </a>
                @endcan
                <a
                    href="{{ route('organizations.members', $org) }}"
                    wire:navigate
                    @class([$navBase, $link('members')])
                >
                    <x-heroicon-o-users class="{{ $ni }}" aria-hidden="true" />
                    {{ __('Members') }}
                </a>
                @can('viewNotificationChannels', $org)
                    <a
                        href="{{ route('organizations.notification-channels', $org) }}"
                        wire:navigate
                        @class([$navBase, $link('notifications')])
                    >
                        <x-heroicon-o-bell class="{{ $ni }}" aria-hidden="true" />
                        {{ __('Notification channels') }}
                    </a>
                @endcan
                @can('view', $org)
                    <a
                        href="{{ route('organizations.secrets', $org) }}"
                        wire:navigate
                        @class([$navBase, $link('secrets')])
                    >
                        <x-heroicon-o-lock-closed class="{{ $ni }}" aria-hidden="true" />
                        {{ __('Secrets') }}
                    </a>
                @endcan
                <a
                    href="{{ route('organizations.teams', $org) }}"
                    wire:navigate
                    @class([$navBase, $link('teams')])
                >
                    <x-heroicon-o-rectangle-group class="{{ $ni }}" aria-hidden="true" />
                    {{ __('Teams') }}
                </a>
                {{-- Webserver templates nav temporarily hidden.
                @can('view', $org)
                @endcan
                --}}
            </nav>
        </div>
        <a
            href="{{ route('organizations.index') }}"
            wire:navigate
            class="mt-4 inline-flex text-sm text-brand-moss hover:text-brand-ink"
        >
            ← {{ __('All organizations') }}
        </a>
    </aside>
    <div {{ $attributes->merge(['class' => 'lg:col-span-9 min-w-0']) }}>

        @if ($useMergedChrome)
            {{-- Merged chrome: one outer card. Dense uses one-line panel head +
                 stats as their own strip; default keeps stats inside the sand header. --}}
            <section class="dply-card min-w-0 overflow-hidden p-0">
                @if ($dense)
                    <x-workspace-panel-head
                        dense
                        :icon="$icon"
                        :title="$title"
                        :note="$description"
                        class="border-b border-brand-ink/10"
                    >
                        @isset($actions)
                            <x-slot:actions>{{ $actions }}</x-slot:actions>
                        @endisset
                    </x-workspace-panel-head>

                    @isset($stats)
                        <div class="border-b border-brand-ink/10">{{ $stats }}</div>
                    @endisset
                @else
                    <div class="border-b border-brand-ink/10 bg-brand-sand/20 px-5 py-5 sm:px-6">
                        <div class="flex flex-wrap items-start justify-between gap-4">
                            <div class="flex min-w-0 items-start gap-3">
                                <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-brand-sage/15 text-brand-forest ring-1 ring-brand-sage/25">
                                    <x-dynamic-component :component="$icon" class="h-5 w-5" aria-hidden="true" />
                                </span>
                                <div class="min-w-0">
                                    <h1 class="text-lg font-semibold tracking-tight text-brand-ink">{{ $title }}</h1>
                                    @if ($description)
                                        <p class="mt-1 max-w-2xl text-sm leading-relaxed text-brand-moss">{{ $description }}</p>
                                    @endif
                                </div>
                            </div>
                            @isset($actions)
                                <div class="flex flex-wrap items-center gap-2">
                                    {{ $actions }}
                                </div>
                            @endisset
                        </div>

                        @isset($stats)
                            <div class="mt-5">
                                {{ $stats }}
                            </div>
                        @endisset
                    </div>
                @endif

                @isset($tabs)
                    <div class="border-b border-brand-ink/10 px-3 py-2 sm:px-4">
                        {{ $tabs }}
                    </div>
                @endisset

                <div class="min-w-0">
                    {{ $slot }}
                </div>

                @isset($footer)
                    <div @class([
                        'border-t border-brand-ink/10 bg-brand-sand/25',
                        'px-3 py-2.5 sm:px-4' => $dense,
                        'px-5 py-4 sm:px-6' => ! $dense,
                    ])>
                        {{ $footer }}
                    </div>
                @endisset
            </section>
        @else
            {{ $slot }}
        @endif
    </div>
</div>
