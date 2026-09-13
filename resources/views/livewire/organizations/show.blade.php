@php
    $tonePalette = [
        'sage' => 'bg-brand-sage/15 text-brand-forest ring-brand-sage/25',
        'sky' => 'bg-sky-50 text-sky-700 ring-sky-200',
        'amber' => 'bg-amber-50 text-amber-900 ring-amber-200',
        'violet' => 'bg-violet-50 text-violet-700 ring-violet-200',
        'sand' => 'bg-brand-sand/55 text-brand-forest ring-brand-ink/10',
        'forest' => 'bg-brand-forest/10 text-brand-forest ring-brand-forest/20',
    ];

    $isAdmin = $organization->hasAdminAccess(auth()->user());
    $canViewCreds = auth()->user()?->can('viewAny', \App\Models\ProviderCredential::class) ?? false;
    $canViewChannels = auth()->user()?->can('viewNotificationChannels', $organization) ?? false;

    // Quick-link tiles. Built as data so the same shape can be rendered in
    // a tighter grid below, and each entry only emits when the viewer has
    // permission for that destination.
    $quickLinks = array_values(array_filter([
        [
            'label' => __('Members'),
            'description' => __('People, roles, invitations.'),
            'href' => route('organizations.members', $organization),
            'icon' => 'heroicon-o-user-group',
            'tone' => 'sage',
            'show' => true,
        ],
        [
            'label' => __('Teams'),
            'description' => __('Scoped notification groups.'),
            'href' => route('organizations.teams', $organization),
            'icon' => 'heroicon-o-rectangle-group',
            'tone' => 'sand',
            'show' => true,
        ],
        [
            'label' => __('Activity'),
            'description' => __('Audit trail of changes.'),
            'href' => route('organizations.activity', $organization),
            'icon' => 'heroicon-o-archive-box',
            'tone' => 'violet',
            'show' => $isAdmin,
        ],
        [
            'label' => __('Settings'),
            'description' => __('Branding, email defaults, API tokens.'),
            'href' => route('organizations.settings', $organization),
            'icon' => 'heroicon-o-cog-6-tooth',
            'tone' => 'amber',
            'show' => $isAdmin,
        ],
        [
            'label' => __('Notification channels'),
            'description' => __('Slack, email, webhooks.'),
            'href' => route('organizations.notification-channels', $organization),
            'icon' => 'heroicon-o-bell-alert',
            'tone' => 'sky',
            'show' => $canViewChannels,
        ],
        [
            'label' => __('Secrets'),
            'description' => __('Shared vault you link onto sites.'),
            'href' => route('organizations.secrets', $organization),
            'icon' => 'heroicon-o-lock-closed',
            'tone' => 'forest',
            'show' => auth()->user()?->can('view', $organization) ?? false,
        ],
        [
            'label' => __('Credentials'),
            'description' => __('Cloud, DNS, CDN tokens.'),
            'href' => route('organizations.credentials', $organization),
            'icon' => 'heroicon-o-key',
            'tone' => 'sage',
            'show' => $canViewCreds,
        ],
        // Webserver templates tile temporarily hidden.
        // [
        //     'label' => __('Webserver templates'),
        //     'description' => __('Reusable nginx / Apache / Caddy snippets.'),
        //     'href' => route('organizations.webserver-templates', $organization),
        //     'icon' => 'heroicon-o-server',
        //     'tone' => 'forest',
        //     'show' => auth()->user()?->can('view', $organization) ?? false,
        // ],
        [
            'label' => __('Billing'),
            'description' => __('Pay-per-use, payment, invoices.'),
            'href' => route('billing.show', $organization),
            'icon' => 'heroicon-o-credit-card',
            'tone' => 'sage',
            'show' => $isAdmin,
        ],
    ], fn (array $l) => $l['show']));
@endphp

<div>
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
        <x-organization-shell
            :organization="$organization"
            section="overview"
            :title="$organization->name"
            :description="__('People and everything dply automates on your behalf.')"
            icon="heroicon-o-building-office-2"
            :breadcrumb="[
                ['label' => __('Dashboard'), 'href' => route('dashboard'), 'icon' => 'home'],
                ['label' => $organization->name, 'icon' => 'building-office-2'],
            ]"
        >
            <x-slot:actions>
                <x-docs-link slug="org-overview" class="!h-6 !gap-1 !rounded-md !px-2 !py-0 !text-xs !font-semibold">
                    <x-heroicon-o-document-text class="h-3.5 w-3.5 shrink-0 opacity-90" aria-hidden="true" />
                    {{ __('Guide') }}
                </x-docs-link>
                <x-docs-link slug="org-roles-and-limits" class="!h-6 !gap-1 !rounded-md !px-2 !py-0 !text-xs !font-semibold">
                    <x-heroicon-o-queue-list class="h-3.5 w-3.5 shrink-0 opacity-90" aria-hidden="true" />
                    {{ __('Roles & limits') }}
                </x-docs-link>
                @if ($isAdmin)
                    <a href="{{ route('billing.show', $organization) }}" wire:navigate class="inline-flex h-6 items-center gap-1 rounded-lg bg-brand-ink px-2.5 text-xs font-semibold text-brand-cream shadow-sm transition-colors hover:bg-brand-forest">
                        <x-heroicon-o-credit-card class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                        {{ __('Billing') }}
                    </a>
                @endif
            </x-slot:actions>

            {{-- Dense stat strip, same composition as the sibling org leaves:
                 hairline-separated cells, headline number inline with its unit
                 and the secondary count on the same line. --}}
            <x-slot:stats>
                <dl class="grid grid-cols-2 gap-px bg-brand-ink/5 sm:grid-cols-4" aria-label="{{ __('Organization at a glance') }}">
                    <div class="bg-white px-3 py-2">
                        <dt class="text-2xs font-semibold uppercase tracking-wide text-brand-mist">{{ __('Plan') }}</dt>
                        <dd class="mt-0.5 truncate text-sm font-semibold text-brand-ink" title="{{ $organization->planTierLabel() }}">{{ $organization->planTierLabel() }}</dd>
                    </div>
                    <div class="bg-white px-3 py-2">
                        <dt class="text-2xs font-semibold uppercase tracking-wide text-brand-mist">{{ __('Fleet') }}</dt>
                        <dd class="mt-0.5 flex items-baseline gap-1.5">
                            <span class="font-mono text-base font-semibold tabular-nums text-brand-ink">{{ $organization->servers_count }}</span>
                            <span class="truncate text-2xs font-semibold uppercase tracking-wide text-brand-moss">
                                {{ trans_choice('server|servers', $organization->servers_count) }}
                                · {{ $organization->sites_count }} {{ trans_choice('site|sites', $organization->sites_count) }}
                            </span>
                        </dd>
                    </div>
                    <div class="bg-white px-3 py-2">
                        <dt class="text-2xs font-semibold uppercase tracking-wide text-brand-mist">{{ __('People') }}</dt>
                        <dd class="mt-0.5 flex items-baseline gap-1.5">
                            <span class="font-mono text-base font-semibold tabular-nums text-brand-ink">{{ $organization->users->count() }}</span>
                            <span class="truncate text-2xs font-semibold uppercase tracking-wide text-brand-moss">
                                {{ trans_choice('member|members', $organization->users->count()) }}
                                · {{ $organization->teams->count() }} {{ trans_choice('team|teams', $organization->teams->count()) }}
                                @if ($organization->invitations->count() > 0)
                                    · {{ $organization->invitations->count() }} {{ __('pending') }}
                                @endif
                            </span>
                        </dd>
                    </div>
                    <div class="bg-white px-3 py-2">
                        {{-- Was "Automation", counting apiTokens + the retired
                             notificationWebhookDestinations (always 0 since the
                             2026_08_14 migration folded them into channels). --}}
                        <dt class="text-2xs font-semibold uppercase tracking-wide text-brand-mist">{{ __('API tokens') }}</dt>
                        <dd class="mt-0.5 flex items-baseline gap-1.5">
                            <span class="font-mono text-base font-semibold tabular-nums text-brand-ink">{{ $organization->apiTokens->count() }}</span>
                            <span class="truncate text-2xs font-semibold uppercase tracking-wide text-brand-moss">
                                {{ trans_choice('token|tokens', $organization->apiTokens->count()) }}
                                · {{ $organization->notificationChannels->count() }} {{ trans_choice('channel|channels', $organization->notificationChannels->count()) }}
                            </span>
                        </dd>
                    </div>
                </dl>
            </x-slot:stats>

            {{-- Section navigator as a hairline strip (not a nested card). Each
                 tile is a single row — icon, label, truncated description — so the
                 whole grid reads as a menu rather than a wall of cards. --}}
            <section class="border-b border-brand-ink/10 last:border-b-0">
                <x-workspace-panel-head
                    dense
                    icon="heroicon-o-squares-2x2"
                    :title="__('Sections')"
                    :note="__('Billing, people, channels, credentials, and automation.')"
                    class="border-b border-brand-ink/10"
                />
                <ul class="grid gap-2 p-3 sm:grid-cols-2 sm:p-4 lg:grid-cols-3 xl:grid-cols-4">
                    @foreach ($quickLinks as $link)
                        <li>
                            <a
                                href="{{ $link['href'] }}"
                                wire:navigate
                                class="group flex h-full w-full items-center gap-2.5 rounded-lg border border-brand-ink/10 bg-brand-cream/30 px-2.5 py-2 text-left transition hover:border-brand-sage/35 hover:bg-brand-sand/20 focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-sage/40"
                            >
                                <span class="inline-flex h-7 w-7 shrink-0 items-center justify-center rounded-md ring-1 {{ $tonePalette[$link['tone']] }}">
                                    <x-dynamic-component :component="$link['icon']" class="h-4 w-4" aria-hidden="true" />
                                </span>
                                <span class="min-w-0 flex-1">
                                    <span class="block truncate text-xs font-semibold text-brand-ink">{{ $link['label'] }}</span>
                                    {{-- `title` is upgraded to the styled bubble by tooltip.js when clipped. --}}
                                    <span class="block truncate text-xs text-brand-moss" title="{{ $link['description'] }}">{{ $link['description'] }}</span>
                                </span>
                                <span aria-hidden="true" class="shrink-0 text-brand-mist transition group-hover:translate-x-0.5 group-hover:text-brand-moss">→</span>
                            </a>
                        </li>
                    @endforeach
                </ul>
            </section>
        </x-organization-shell>
    </div>
</div>
