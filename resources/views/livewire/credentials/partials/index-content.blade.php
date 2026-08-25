@php
    // Lightweight summary stats — pulled inline so this redesign doesn't
    // require touching the Livewire component or its renderer signature.
    $connectedProviders = $credentials->pluck('provider')->unique();
    $verifiedCredentials = $credentials->filter(fn ($c) => ! empty($c->verified_at ?? null));

    $capabilityTabs = [
        ['id' => 'all', 'label' => __('All'), 'icon' => 'heroicon-o-squares-2x2'],
        ['id' => 'server', 'label' => __('Compute'), 'icon' => 'heroicon-o-cpu-chip'],
        ['id' => 'dns', 'label' => __('DNS'), 'icon' => 'heroicon-o-globe-alt'],
        ['id' => 'cdn', 'label' => __('CDN'), 'icon' => 'heroicon-o-bolt'],
        ['id' => 'storage', 'label' => __('Storage'), 'icon' => 'heroicon-o-cloud-arrow-up'],
    ];

    // Capability dots for the provider cards — the existing panel.blade.php
    // uses the same vocabulary, so cards and panel speak the same language.
    $capabilityDot = function (string $cap): array {
        return match ($cap) {
            'compute' => ['label' => __('Compute'), 'dot' => 'bg-brand-moss'],
            'dns' => ['label' => __('DNS'), 'dot' => 'bg-brand-sage'],
            'cdn' => ['label' => __('CDN'), 'dot' => 'bg-sky-500'],
            'app_platform' => ['label' => __('App Platform'), 'dot' => 'bg-violet-500'],
            'import' => ['label' => __('Import'), 'dot' => 'bg-amber-500'],
            default => ['label' => ucfirst(str_replace('_', ' ', $cap)), 'dot' => 'bg-brand-mist'],
        };
    };
@endphp

<x-livewire-validation-errors />

{{-- In the org shell the trail is hoisted above the whole box (see index.blade.php);
     here it only renders on the standalone (non-shell) surface. --}}
@if (empty($useOrgShell))
    @if ($organization)
        <x-breadcrumb-trail doc-route="docs.connect-provider" :items="[
            ['label' => __('Dashboard'), 'href' => route('dashboard'), 'icon' => 'home'],
            ['label' => $organization->name, 'href' => route('organizations.show', $organization), 'icon' => 'building-office-2'],
            ['label' => __('Credentials'), 'icon' => 'key'],
        ]" />
    @else
        <x-breadcrumb-trail doc-route="docs.connect-provider" :items="[
            ['label' => __('Dashboard'), 'href' => route('dashboard'), 'icon' => 'home'],
            ['label' => __('Settings'), 'href' => route('settings.profile'), 'icon' => 'cog-6-tooth'],
            ['label' => __('Credentials'), 'icon' => 'key'],
        ]" />
    @endif
@endif

<div @class(["space-y-6" => empty($useOrgShell)])>
@if (empty($useOrgShell))
{{-- Standalone settings surface keeps its own hero; org shell already has title. --}}
    <section class="dply-card overflow-hidden">
        <div class="grid gap-6 p-6 sm:p-8 lg:grid-cols-12 lg:items-center lg:gap-8">
            <div class="lg:col-span-7">
                <div class="flex items-start gap-3">
                    <x-icon-badge size="md">
                        <x-heroicon-o-key class="h-6 w-6" aria-hidden="true" />
                    </x-icon-badge>
                    <div class="min-w-0">
                        <p class="text-xs font-semibold uppercase tracking-[0.18em] text-brand-sage">{{ __('Settings') }}</p>
                        <h2 class="mt-1 text-xl font-semibold tracking-tight text-brand-ink">{{ __('Credentials') }}</h2>
                        <p class="mt-2 max-w-2xl text-sm leading-relaxed text-brand-moss">
                            {{ __('Every secret this organization hands to a third party: API tokens for the clouds, registrars and CDNs you use, and the buckets and remotes your backups ship to. All encrypted at rest, and validated against the provider when we can.') }}
                        </p>
                    </div>
                </div>
                <div class="mt-4 flex flex-wrap items-center gap-2">
                </div>
            </div>
            <dl class="grid grid-cols-3 gap-2 lg:col-span-5">
                <div class="rounded-2xl border border-brand-ink/10 bg-white px-4 py-3 shadow-sm">
                    <dt class="text-2xs font-semibold uppercase tracking-wide text-brand-mist">{{ __('Providers') }}</dt>
                    <dd class="mt-1 flex items-baseline gap-1.5">
                        <span class="font-mono text-xl font-semibold tabular-nums text-brand-ink">{{ $connectedProviders->count() }}</span>
                        <span class="text-xs text-brand-moss">{{ __('connected') }}</span>
                    </dd>
                </div>
                <div class="rounded-2xl border border-brand-ink/10 bg-white px-4 py-3 shadow-sm">
                    <dt class="text-2xs font-semibold uppercase tracking-wide text-brand-mist">{{ __('Credentials') }}</dt>
                    <dd class="mt-1 flex items-baseline gap-1.5">
                        <span class="font-mono text-xl font-semibold tabular-nums text-brand-ink">{{ $credentials->count() }}</span>
                        <span class="text-xs text-brand-moss">{{ trans_choice('saved|saved', $credentials->count()) }}</span>
                    </dd>
                </div>
                <div class="rounded-2xl border border-brand-sage/30 bg-brand-sage/8 px-4 py-3 shadow-sm">
                    <dt class="text-2xs font-semibold uppercase tracking-wide text-brand-forest/80">{{ __('Storage') }}</dt>
                    <dd class="mt-1 flex items-center gap-1.5">
                        <x-heroicon-o-lock-closed class="h-4 w-4 shrink-0 text-brand-forest" aria-hidden="true" />
                        <span class="text-xs font-medium text-brand-forest">{{ __('Encrypted at rest') }}</span>
                    </dd>
                </div>
            </dl>
        </div>
    </section>
@endif

    {{-- OAuth round trips land back here, so this is the only place their
         outcome can be reported. Without it a successful "Connect Dropbox"
         returned silently and looked like nothing happened. --}}
    @if (session('success') || session('error'))
        <div @class([
            'px-5 py-4 sm:px-6' => empty($useOrgShell),
            'border-b border-brand-ink/10 px-5 py-4 sm:px-6' => ! empty($useOrgShell),
        ])>
            <x-alert :tone="session('error') ? 'danger' : 'success'">
                {{ session('error') ?: session('success') }}
            </x-alert>
        </div>
    @endif

    {{-- Capability filter. --}}
    <section aria-label="{{ __('Capability filter') }}" @class([
        'border-b border-brand-ink/10 px-3 py-2 sm:px-4' => ! empty($useOrgShell),
    ])>
        <x-server-workspace-tablist :aria-label="__('Capability filter')" scroll class="!mb-0">
            @foreach ($capabilityTabs as $tabItem)
                <x-server-workspace-tab :icon="$tabItem['icon']" :active="$tab === $tabItem['id']" wire:click="setTab('{{ $tabItem['id'] }}')">
                    {{ $tabItem['label'] }}
                </x-server-workspace-tab>
            @endforeach
        </x-server-workspace-tablist>
    </section>

    {{-- First-run nudge. When the org has zero credentials anywhere,
         surface a single "Connect your first provider" card above the grid
         so an empty workspace doesn't read as a wall of greyed-out tiles. --}}
    @if ($credentials->isEmpty())
        <section @class([
            'rounded-2xl border border-brand-sage/30 bg-brand-sage/5 p-4' => empty($useOrgShell),
            'border-b border-brand-ink/10 bg-brand-sage/5 px-5 py-3 sm:px-6' => ! empty($useOrgShell),
        ])>
            <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:gap-4">
                <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-brand-sage/15 text-brand-forest ring-1 ring-brand-sage/30">
                    <x-heroicon-o-link class="h-4 w-4" aria-hidden="true" />
                </span>
                <div class="min-w-0 flex-1">
                    <p class="text-sm font-semibold text-brand-ink">{{ __('Connect your first provider') }}</p>
                    <p class="mt-0.5 text-xs leading-relaxed text-brand-moss">{{ __('Pick a provider below to link an API token. Tokens are encrypted before they hit disk and verified when possible.') }}</p>
                </div>
                <button
                    type="button"
                    x-on:click="$dispatch('open-add-provider-credential-modal')"
                    class="inline-flex h-6 shrink-0 items-center gap-1 rounded-lg bg-brand-ink px-2.5 text-xs font-semibold text-brand-cream shadow-sm transition hover:bg-brand-forest"
                >
                    <x-heroicon-o-plus class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                    {{ __('Connect a provider') }}
                </button>
            </div>
        </section>
    @endif

    {{-- Provider grid. Replaces the dense sidebar with tappable cards laid
         out by category. Each card shows capability dots, a count badge,
         and a clear connect / manage state — clicking sets the active
         provider and the panel below jumps in to take over. --}}
    {{-- The storage tab empties this list entirely; without the guard the
         section still paints its padding and bottom rule as a blank band. --}}
    @if (! empty($providerNav))
    <section aria-label="{{ __('Pick a provider') }}" @class([
        'space-y-4' => empty($useOrgShell),
        'space-y-4 border-b border-brand-ink/10 px-5 py-4 sm:px-6' => ! empty($useOrgShell),
    ])>
        @foreach ($providerNav as $group)
            @php
                $groupItems = $group['items'];
                $groupTotal = count($groupItems);
                $groupAvailable = collect($groupItems)->reject(fn ($i) => ! empty($i['comingSoon']))->count();
                $groupConnected = collect($groupItems)->filter(fn ($i) => empty($i['comingSoon']) && $this->credentialCountFor($i['id']) > 0)->count();
                $groupIcon = match (strtolower((string) $group['label'])) {
                    default => 'heroicon-o-cube',
                };
                if (str_contains(strtolower((string) $group['label']), 'vps') || str_contains(strtolower((string) $group['label']), 'cloud')) {
                    $groupIcon = 'heroicon-o-cloud';
                } elseif (str_contains(strtolower((string) $group['label']), 'dns')) {
                    $groupIcon = 'heroicon-o-globe-alt';
                } elseif (str_contains(strtolower((string) $group['label']), 'cdn') || str_contains(strtolower((string) $group['label']), 'edge')) {
                    $groupIcon = 'heroicon-o-bolt';
                } elseif (str_contains(strtolower((string) $group['label']), 'registr') || str_contains(strtolower((string) $group['label']), 'image')) {
                    $groupIcon = 'heroicon-o-archive-box';
                } elseif (str_contains(strtolower((string) $group['label']), 'import')) {
                    $groupIcon = 'heroicon-o-arrow-down-tray';
                }
            @endphp
            <div>
                {{-- Group header. Tightened with an icon + connected/total
                     chip — gives quick read on how much of each family is
                     already wired up without scanning every card. --}}
                <div class="flex flex-wrap items-baseline justify-between gap-2">
                    <h3 class="inline-flex items-center gap-2 text-xs font-semibold uppercase tracking-[0.18em] text-brand-mist">
                        <x-dynamic-component :component="$groupIcon" class="h-3.5 w-3.5 shrink-0 text-brand-moss" aria-hidden="true" />
                        {{ $group['label'] }}
                    </h3>
                    <span class="text-2xs font-semibold tabular-nums text-brand-mist">
                        @if ($groupConnected > 0)
                            <span class="text-brand-forest">{{ $groupConnected }}</span><span class="text-brand-mist"> / {{ $groupAvailable }} {{ __('connected') }}</span>
                        @else
                            {{ $groupAvailable }} {{ __('available') }}
                        @endif
                    </span>
                </div>
                <ul class="mt-2 grid gap-2 sm:grid-cols-2 xl:grid-cols-3">
                    @foreach ($groupItems as $item)
                        @php
                            $count = $this->credentialCountFor($item['id']);
                            $isComing = ! empty($item['comingSoon']);
                            $isActive = $active_provider === $item['id'];
                            $firstCred = $credentials->firstWhere('provider', $item['id']);
                            $sampleCaps = $firstCred?->capabilities() ?? [];
                        @endphp
                        <li>
                            <button
                                type="button"
                                @if (! $isComing)
                                    x-on:click="$dispatch('open-add-provider-credential-modal', { provider: @js($item['id']) })"
                                @endif
                                @disabled($isComing)
                                @class([
                                    'group relative flex h-full w-full items-center gap-2.5 rounded-xl border bg-white p-2.5 text-left shadow-sm transition focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-sage/40',
                                    'border-brand-ink/10 hover:border-brand-sage/35 hover:shadow-md' => ! $isComing && $count === 0,
                                    'border-brand-sage/35 hover:border-brand-sage/55 hover:shadow-md' => ! $isComing && $count > 0,
                                    'border-brand-ink/10 cursor-not-allowed opacity-65' => $isComing,
                                ])
                            >
                                {{-- Horizontal card: icon, identity, state. A quarter
                                     the height of the old stacked tile, and the whole
                                     row still reads left-to-right in one pass. --}}
                                <span @class([
                                    'inline-flex h-8 w-8 shrink-0 items-center justify-center rounded-lg ring-1 ring-brand-ink/10',
                                    'bg-brand-sage/12 text-brand-forest' => $count > 0 && ! $isComing,
                                    'bg-brand-sand/45 text-brand-forest group-hover:bg-brand-sand/70' => $count === 0 || $isComing,
                                ])>
                                    <x-credentials-provider-icon :provider="$item['id']" class="h-4 w-4" />
                                </span>

                                {{-- The name owns its own line at full width: sharing a
                                     row with the count and action chips squeezed it to
                                     nothing on narrow columns. State + capability dots
                                     ride the line beneath it. --}}
                                <span class="min-w-0 flex-1">
                                    <span class="block truncate text-sm font-semibold text-brand-ink" title="{{ $item['label'] }}">{{ $item['label'] }}</span>
                                    <span class="mt-0.5 flex min-w-0 items-center gap-1.5 text-2xs text-brand-moss">
                                        @if ($isComing)
                                            <span class="shrink-0 rounded-full bg-brand-sand/60 px-1.5 text-2xs font-semibold uppercase tracking-wide text-brand-mist ring-1 ring-brand-ink/10">{{ __('Soon') }}</span>
                                        @elseif ($count === 0)
                                            <span class="truncate">{{ __('Not connected') }}</span>
                                        @else
                                            <span class="inline-flex shrink-0 items-center gap-0.5 rounded-full bg-brand-sage/15 px-1.5 font-semibold tabular-nums text-brand-forest ring-1 ring-brand-sage/20">
                                                <x-heroicon-m-check-circle class="h-3 w-3" aria-hidden="true" />
                                                {{ $count }}
                                            </span>
                                            {{-- Capability dots: the colour is the
                                                 information, the label is the fallback
                                                 where the column is wide enough. --}}
                                            @foreach (array_slice($sampleCaps, 0, 3) as $cap)
                                                @php $chip = $capabilityDot($cap); @endphp
                                                <span class="inline-flex shrink-0 items-center gap-1" title="{{ $chip['label'] }}">
                                                    <span class="inline-block h-1.5 w-1.5 shrink-0 rounded-full {{ $chip['dot'] }}" aria-hidden="true"></span>
                                                </span>
                                            @endforeach
                                            @if (empty($sampleCaps) && $firstCred?->created_at)
                                                <span class="truncate" title="{{ $firstCred->created_at->toDayDateTimeString() }}">{{ __('added :time', ['time' => $firstCred->created_at->diffForHumans()]) }}</span>
                                            @endif
                                        @endif
                                    </span>
                                </span>

                                {{-- Trailing affordance. Visually a chip, semantically a
                                     span — the whole card IS the trigger, so we don't
                                     nest <button>s. --}}
                                @unless ($isComing)
                                    <span class="inline-flex h-6 shrink-0 items-center gap-1 rounded-md border border-brand-ink/10 bg-brand-cream/40 px-2 text-xs font-semibold text-brand-ink transition group-hover:border-brand-sage/35 group-hover:bg-brand-sage/8 group-hover:text-brand-forest">
                                        @if ($count > 0)
                                            <x-heroicon-o-cog-6-tooth class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                                            <span>{{ __('Manage') }}</span>
                                        @else
                                            <x-heroicon-o-plus class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                                            <span>{{ __('Add') }}</span>
                                        @endif
                                    </span>
                                @endunless
                            </button>
                        </li>
                    @endforeach
                </ul>
            </div>
        @endforeach
    </section>
    @endif

    {{-- Backup storage. Same card language as the provider grid, but a
         different model underneath: a destination is a NAMED bucket or remote
         and an org can hold several per provider, so the card counts rows
         rather than showing a single connected/not-connected state. --}}
    @if (! empty($storageNav))
        <section aria-label="{{ __('Backup storage') }}" @class([
            'space-y-4' => empty($useOrgShell),
            'space-y-4 border-b border-brand-ink/10 px-5 py-4 sm:px-6' => ! empty($useOrgShell),
        ])>
            @foreach ($storageNav as $group)
                @php
                    $storageItems = $group['items'];
                    $storageAvailable = collect($storageItems)->reject(fn ($i) => ! empty($i['comingSoon']))->count();
                    $storageConnected = collect($storageItems)
                        ->filter(fn ($i) => $this->storageDestinationsFor($i['id'])->isNotEmpty())
                        ->count();
                @endphp
                <div>
                    <div class="flex flex-wrap items-baseline justify-between gap-2">
                        <h3 class="inline-flex items-center gap-2 text-xs font-semibold uppercase tracking-[0.18em] text-brand-mist">
                            <x-heroicon-o-cloud-arrow-up class="h-3.5 w-3.5 shrink-0 text-brand-moss" aria-hidden="true" />
                            {{ $group['label'] }}
                        </h3>
                        <span class="text-2xs font-semibold tabular-nums text-brand-mist">
                            @if ($storageCount > 0)
                                <span class="text-brand-forest">{{ $storageCount }}</span><span class="text-brand-mist"> {{ trans_choice('destination|destinations', $storageCount) }} · {{ $storageConnected }} / {{ $storageAvailable }} {{ __('providers') }}</span>
                            @else
                                {{ $storageAvailable }} {{ __('available') }}
                            @endif
                        </span>
                    </div>
                    <p class="mt-0.5 text-2xs leading-relaxed text-brand-moss">
                        {{ __('Where scheduled database dumps ship. Without one, a dump stays on the server that made it.') }}
                    </p>
                    <ul class="mt-2 grid gap-2 sm:grid-cols-2 xl:grid-cols-3">
                        @foreach ($storageItems as $item)
                            @php
                                $destinations = $this->storageDestinationsFor($item['id']);
                                $storageTotal = $destinations->count();
                                $isComing = ! empty($item['comingSoon']);
                                $newest = $destinations->sortByDesc('created_at')->first();
                            @endphp
                            <li>
                                {{-- A configured provider becomes a real card with one
                                     editable row per destination. It can't stay a single
                                     <button> — the rows are buttons themselves, and
                                     nesting them would be invalid. --}}
                                @if (! $isComing && $storageTotal > 0)
                                    <div class="flex h-full w-full flex-col gap-2 rounded-xl border border-brand-sage/35 bg-white p-2.5 shadow-sm">
                                        <div class="flex w-full items-center gap-2.5">
                                            <span class="inline-flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-brand-sage/12 text-brand-forest ring-1 ring-brand-ink/10">
                                                <x-heroicon-o-cloud-arrow-up class="h-4 w-4" aria-hidden="true" />
                                            </span>
                                            <span class="min-w-0 flex-1">
                                                <span class="truncate text-sm font-semibold text-brand-ink">{{ $item['label'] }}</span>
                                                <span class="mt-0.5 block truncate text-2xs text-brand-moss">
                                                    {{ trans_choice(':n destination|:n destinations', $storageTotal, ['n' => $storageTotal]) }}
                                                </span>
                                            </span>
                                            <span class="inline-flex shrink-0 items-center gap-0.5 rounded-full bg-brand-sage/15 px-1.5 py-0.5 text-2xs font-semibold tabular-nums text-brand-forest ring-1 ring-brand-sage/20">
                                                <x-heroicon-m-check-circle class="h-3 w-3" aria-hidden="true" />
                                                {{ $storageTotal }}
                                            </span>
                                        </div>

                                        <ul class="w-full space-y-1">
                                            @foreach ($destinations as $destination)
                                                <li wire:key="dest-{{ $destination->id }}">
                                                    <button
                                                        type="button"
                                                        wire:click="editDestination('{{ $destination->id }}')"
                                                        class="group/row flex w-full items-center gap-2 rounded-lg border border-brand-ink/10 bg-brand-cream/50 px-2 py-1.5 text-left transition hover:border-brand-sage/40 hover:bg-white"
                                                    >
                                                        <span class="min-w-0 flex-1">
                                                            <span class="flex items-center gap-1.5">
                                                                <span class="truncate text-xs font-semibold text-brand-ink">{{ $destination->name }}</span>
                                                                @unless ($destination->hasDurableCredentials())
                                                                    {{-- A short-lived Dropbox token stops scheduled dumps
                                                                         without reporting a configuration error. --}}
                                                                    <span class="shrink-0 rounded bg-brand-gold/25 px-1 py-0.5 text-[9px] font-bold uppercase tracking-wide text-amber-800" title="{{ __('This uses a short-lived access token — add a refresh token to keep schedules working.') }}">
                                                                        {{ __('expires') }}
                                                                    </span>
                                                                @endunless
                                                            </span>
                                                            <span class="mt-0.5 block truncate font-mono text-[10px] text-brand-mist">{{ $destination->locationSummary() }}</span>
                                                        </span>
                                                        <x-heroicon-o-pencil-square class="h-3.5 w-3.5 shrink-0 text-brand-mist transition-colors group-hover/row:text-brand-forest" aria-hidden="true" />
                                                    </button>
                                                </li>
                                            @endforeach
                                        </ul>

                                        <button
                                            type="button"
                                            wire:click="openStorageModal('{{ $item['id'] }}')"
                                            class="mt-auto inline-flex h-6 w-full items-center justify-center gap-1 rounded-md border border-brand-ink/10 bg-brand-cream/40 px-2 text-xs font-semibold text-brand-ink transition hover:border-brand-sage/35 hover:bg-brand-sage/8 hover:text-brand-forest"
                                        >
                                            <x-heroicon-o-plus class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                                            {{ __('Add another') }}
                                        </button>
                                    </div>
                                @else
                                <button
                                    type="button"
                                    @if (! $isComing)
                                        wire:click="openStorageModal('{{ $item['id'] }}')"
                                    @endif
                                    @disabled($isComing)
                                    @class([
                                        'group relative flex h-full w-full items-center gap-2.5 rounded-xl border bg-white p-2.5 text-left shadow-sm transition focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-sage/40',
                                        'border-brand-ink/10 hover:border-brand-sage/35 hover:shadow-md' => ! $isComing,
                                        'border-brand-ink/10 cursor-not-allowed opacity-65' => $isComing,
                                    ])
                                >
                                    <span class="inline-flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-brand-sand/45 text-brand-forest ring-1 ring-brand-ink/10 group-hover:bg-brand-sand/70">
                                        <x-heroicon-o-cloud-arrow-up class="h-4 w-4" aria-hidden="true" />
                                    </span>
                                    <span class="min-w-0 flex-1">
                                        <span class="flex items-center gap-1.5">
                                            <span class="truncate text-sm font-semibold text-brand-ink">{{ $item['label'] }}</span>
                                            @if ($isComing)
                                                <span class="shrink-0 rounded-full bg-brand-sand/60 px-1.5 py-0.5 text-2xs font-semibold uppercase tracking-wide text-brand-mist ring-1 ring-brand-ink/10">{{ __('Soon') }}</span>
                                            @endif
                                        </span>
                                        <span class="mt-0.5 block truncate text-2xs text-brand-moss">
                                            {{ $isComing ? __('Coming soon') : __('Not configured') }}
                                        </span>
                                    </span>
                                    @unless ($isComing)
                                        <span class="inline-flex h-6 shrink-0 items-center gap-1 rounded-md border border-brand-ink/10 bg-brand-cream/40 px-2 text-xs font-semibold text-brand-ink transition group-hover:border-brand-sage/35 group-hover:bg-brand-sage/8 group-hover:text-brand-forest">
                                            <x-heroicon-o-plus class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                                            <span>{{ __('Add') }}</span>
                                        </span>
                                    @endunless
                                </button>
                                @endif
                            </li>
                        @endforeach
                    </ul>
                </div>
            @endforeach
        </section>
    @endif

    {{-- Provider management lives in a modal now (opened per-card). Keeps
         the index focused on connection state at a glance. --}}
</div>
