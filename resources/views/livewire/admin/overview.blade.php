@php
    $pillOk = 'inline-flex items-center rounded-full bg-emerald-50 px-2 py-0.5 text-xs font-semibold text-emerald-800';
    $pillBad = 'inline-flex items-center rounded-full bg-red-50 px-2 py-0.5 text-xs font-semibold text-red-800';

    // Hero tiles mirror the Edge index summary strip: icon + micro label on top,
    // mono tabular number under it. Tone is the only thing that varies.
    $kpis = [
        ['icon' => 'heroicon-o-users', 'label' => __('Users'), 'value' => $counts['users'], 'tone' => 'text-brand-sage'],
        ['icon' => 'heroicon-o-building-office-2', 'label' => __('Organizations'), 'value' => $counts['organizations'], 'tone' => 'text-brand-sage'],
        ['icon' => 'heroicon-o-globe-alt', 'label' => __('Sites'), 'value' => $counts['sites'], 'tone' => 'text-brand-forest'],
        ['icon' => 'heroicon-o-server-stack', 'label' => __('Servers'), 'value' => $counts['servers'], 'tone' => 'text-brand-mist'],
        ['icon' => 'heroicon-o-clipboard-document-list', 'label' => __('Audit (24h)'), 'value' => $counts['audit_logs_24h'], 'tone' => 'text-brand-sage'],
        ['icon' => 'heroicon-o-exclamation-triangle', 'label' => __('Failed jobs'), 'value' => $counts['failed_jobs'], 'tone' => $counts['failed_jobs'] > 0 ? 'text-rose-600' : 'text-brand-mist'],
        ['icon' => 'heroicon-o-user-plus', 'label' => __('New users (7d)'), 'value' => $counts['users_7d'], 'tone' => 'text-brand-forest'],
        ['icon' => 'heroicon-o-sparkles', 'label' => __('New orgs (7d)'), 'value' => $counts['organizations_7d'], 'tone' => 'text-brand-forest'],
    ];

    $quickLinks = [
        ['route' => 'admin.operations', 'icon' => 'heroicon-o-wrench-screwdriver', 'label' => __('Operations'), 'note' => __('Runtime, queues, logs, exports, cache')],
        ['route' => 'admin.audit', 'icon' => 'heroicon-o-clipboard-document-list', 'label' => __('Audit log'), 'note' => __('Filter and export platform activity')],
        ['route' => 'admin.organizations.index', 'icon' => 'heroicon-o-building-office-2', 'label' => __('Organizations'), 'note' => __('Search orgs and manage overrides')],
    ];
@endphp

<div>
    <x-breadcrumb-trail :items="[
        ['label' => __('Dashboard'), 'href' => route('dashboard'), 'icon' => 'home'],
        ['label' => __('Platform admin'), 'icon' => 'shield-check'],
    ]" />

    <x-profile-shell
        class="mt-4"
        :title="__('Overview')"
        :description="__('Platform KPIs, health signals, and quick links into operations, audit, flags, and organizations.')"
        icon="heroicon-o-shield-check"
    >
        <x-slot:actions>
            <x-outline-link href="{{ route('admin.operations') }}" wire:navigate size="sm">
                <x-heroicon-o-wrench-screwdriver class="h-4 w-4 shrink-0 opacity-90" aria-hidden="true" />
                {{ __('Operations') }}
            </x-outline-link>
            <x-outline-link href="{{ route('admin.audit') }}" wire:navigate size="sm">
                <x-heroicon-o-clipboard-document-list class="h-4 w-4 shrink-0 opacity-90" aria-hidden="true" />
                {{ __('Audit log') }}
            </x-outline-link>
        </x-slot:actions>

        <x-slot:stats>
            <dl class="grid grid-cols-2 gap-2 sm:grid-cols-4">
                @foreach ($kpis as $stat)
                    <div class="rounded-xl border border-brand-ink/10 bg-white/80 px-3 py-2">
                        <dt class="flex items-center gap-1.5 text-2xs font-semibold uppercase tracking-wide text-brand-mist">
                            <x-dynamic-component :component="$stat['icon']" class="h-3.5 w-3.5 shrink-0 {{ $stat['tone'] }}" aria-hidden="true" />
                            <span class="truncate">{{ $stat['label'] }}</span>
                        </dt>
                        <dd class="mt-0.5 font-mono text-lg font-semibold tabular-nums leading-none text-brand-ink">{{ number_format($stat['value']) }}</dd>
                    </div>
                @endforeach
            </dl>
        </x-slot:stats>

        {{-- Health banner rides at the top of the body as its own strip so the
             card still reads as one surface. --}}
        @if ($healthIssues !== [])
            <div class="border-b border-brand-ink/10 bg-amber-50/70 px-3 py-3 text-sm text-amber-950 sm:px-4" role="status">
                <p class="flex items-center gap-1.5 font-semibold">
                    <x-heroicon-o-exclamation-triangle class="h-4 w-4 shrink-0 text-amber-700" aria-hidden="true" />
                    {{ __('Attention needed') }}
                </p>
                <ul class="mt-2 list-inside list-disc space-y-1">
                    @foreach ($healthIssues as $issue)
                        <li>{{ $issue }}</li>
                    @endforeach
                </ul>
                <p class="mt-2">
                    <a href="{{ route('admin.operations') }}" wire:navigate class="font-medium underline">{{ __('Open operations') }}</a>
                </p>
            </div>
        @else
            <div class="flex items-center gap-1.5 border-b border-brand-ink/10 bg-emerald-50/60 px-3 py-2.5 text-sm text-emerald-900 sm:px-4" role="status">
                <x-heroicon-o-check-badge class="h-4 w-4 shrink-0 text-emerald-700" aria-hidden="true" />
                {{ __('Core connectivity checks look healthy. Review operations for queue depth and logs.') }}
            </div>
        @endif

        <section class="border-b border-brand-ink/10">
            <x-workspace-panel-head
                dense
                icon="heroicon-o-squares-2x2"
                :title="__('Jump to')"
                :note="__('The four admin surfaces behind this dashboard.')"
            />
            <div class="grid gap-2 px-3 py-3 sm:grid-cols-2 sm:px-4 lg:grid-cols-4">
                @foreach ($quickLinks as $link)
                    <a
                        href="{{ route($link['route']) }}"
                        wire:navigate
                        class="group rounded-xl border border-brand-ink/10 bg-white/80 px-3 py-2.5 transition hover:border-brand-sage/40 hover:bg-brand-sand/30"
                    >
                        <p class="flex items-center gap-1.5 text-sm font-semibold text-brand-ink">
                            <x-dynamic-component :component="$link['icon']" class="h-4 w-4 shrink-0 text-brand-sage" aria-hidden="true" />
                            {{ $link['label'] }}
                        </p>
                        <p class="mt-1 text-xs text-brand-moss">{{ $link['note'] }}</p>
                    </a>
                @endforeach
            </div>
        </section>

        <section class="border-b border-brand-ink/10">
            <x-workspace-panel-head
                dense
                icon="heroicon-o-cpu-chip"
                :title="__('Runtime snapshot')"
                :note="__('Environment and backing-service reachability for this node.')"
            />
            <dl class="px-3 py-1 text-sm sm:px-4">
                <div class="flex items-baseline justify-between gap-2 border-b border-brand-ink/5 py-2">
                    <dt class="text-brand-moss">{{ __('Environment') }}</dt>
                    <dd class="font-mono text-xs text-brand-ink">{{ $system['env'] }}</dd>
                </div>
                <div class="flex items-baseline justify-between gap-2 border-b border-brand-ink/5 py-2">
                    <dt class="text-brand-moss">{{ __('Database') }}</dt>
                    <dd>
                        @if ($system['db_ok'])
                            <span class="{{ $pillOk }}">{{ __('OK') }}</span>
                        @else
                            <span class="{{ $pillBad }}">{{ __('Failed') }}</span>
                        @endif
                    </dd>
                </div>
                <div class="flex items-baseline justify-between gap-2 py-2">
                    <dt class="text-brand-moss">{{ __('Redis') }}</dt>
                    <dd>
                        @if ($system['redis_ok'] === true)
                            <span class="{{ $pillOk }}">{{ __('OK') }}</span>
                        @elseif ($system['redis_ok'] === false)
                            <span class="{{ $pillBad }}">{{ __('Failed') }}</span>
                        @else
                            <span class="text-brand-mist">{{ __('Unknown') }}</span>
                        @endif
                    </dd>
                </div>
            </dl>
        </section>

        <section class="border-b border-brand-ink/10">
            <x-workspace-panel-head
                dense
                icon="heroicon-o-building-office-2"
                :title="__('Top organizations by servers')"
                :count="$topOrganizations->count()"
            />
            <ul class="divide-y divide-brand-ink/5 text-sm">
                @forelse ($topOrganizations as $org)
                    <li class="flex items-center justify-between gap-2 px-3 py-2 sm:px-4">
                        <a href="{{ route('admin.organizations.show', $org) }}" wire:navigate class="truncate font-medium text-brand-ink hover:underline">{{ $org->name }}</a>
                        <span class="font-mono tabular-nums text-brand-moss">{{ number_format($org->servers_count) }}</span>
                    </li>
                @empty
                    <li class="px-3 py-6 text-center text-brand-mist sm:px-4">{{ __('No organizations yet.') }}</li>
                @endforelse
            </ul>
        </section>

        <section class="border-b border-brand-ink/10">
            <x-workspace-panel-head
                dense
                icon="heroicon-o-clipboard-document-list"
                :title="__('Recent audit log')"
            >
                <x-slot:actions>
                    <x-outline-link href="{{ route('admin.audit') }}" wire:navigate size="xxs">
                        {{ __('View all') }}
                    </x-outline-link>
                </x-slot:actions>
            </x-workspace-panel-head>
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-brand-ink/10 text-left text-xs">
                    <thead class="bg-white text-brand-mist">
                        <tr>
                            <th class="px-3 py-2 font-semibold uppercase tracking-wide sm:px-4">{{ __('When') }}</th>
                            <th class="px-3 py-2 font-semibold uppercase tracking-wide">{{ __('Action') }}</th>
                            <th class="px-3 py-2 font-semibold uppercase tracking-wide">{{ __('User') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-brand-ink/5">
                        @forelse ($recentAuditLogs as $log)
                            <tr>
                                <td class="whitespace-nowrap px-3 py-2 text-brand-mist sm:px-4">{{ $log->created_at?->timezone(config('app.timezone'))->format('M j H:i') }}</td>
                                <td class="max-w-[14rem] truncate px-3 py-2 font-mono text-brand-ink">{{ $log->action }}</td>
                                <td class="max-w-[12rem] truncate px-3 py-2 text-brand-moss">{{ $log->user?->email ?? '—' }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="3" class="px-3 py-6 text-center text-brand-mist sm:px-4">{{ __('No audit entries yet.') }}</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </section>

        <section>
            <x-workspace-panel-head
                dense
                icon="heroicon-o-user-plus"
                :title="__('Newest users')"
            >
                <x-slot:actions>
                    <x-outline-link href="{{ route('admin.users.index') }}" wire:navigate size="xxs">
                        {{ __('All users') }}
                    </x-outline-link>
                </x-slot:actions>
            </x-workspace-panel-head>
            <ul class="divide-y divide-brand-ink/5 text-sm">
                @forelse ($recentUsers as $u)
                    <li class="flex flex-wrap items-baseline justify-between gap-2 px-3 py-2 sm:px-4">
                        <span class="font-medium text-brand-ink">{{ $u->name }}</span>
                        <span class="text-xs text-brand-moss">{{ $u->email }}</span>
                    </li>
                @empty
                    <li class="px-3 py-6 text-center text-brand-mist sm:px-4">{{ __('No users.') }}</li>
                @endforelse
            </ul>
        </section>
    </x-profile-shell>
</div>
