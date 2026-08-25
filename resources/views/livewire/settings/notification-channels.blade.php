@php
    $channelCount = $channels->count();
    $canAddChannel = $canManage && count($types) > 0;
    // Shell header: Add lives in the sand header only when the list already
    // has items. Empty state owns the single CTA so we never stack two Adds.
    $showShellAdd = $canAddChannel && $channelCount > 0;
@endphp

<div>
    @if (! empty($useOrgShell))
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
            <x-organization-shell
                :organization="$organization"
                :section="$orgShellSection ?? 'notifications'"
                :title="$pageTitle"
                :description="$intro"
                icon="heroicon-o-bell"
                :breadcrumb="$breadcrumbs ?? null"
            >
                <x-slot:actions>
                    @if (! empty($showBulkAssign ?? false))
                        <a
                            href="{{ route('profile.notification-channels.bulk-assign') }}"
                            wire:navigate
                            class="inline-flex h-6 items-center gap-1 rounded-md border border-brand-ink/15 bg-white px-2 text-xs font-semibold text-brand-ink shadow-sm transition-colors hover:bg-brand-sand/40"
                        >
                            <x-heroicon-o-paper-airplane class="h-3.5 w-3.5 shrink-0 opacity-90" aria-hidden="true" />
                            {{ __('Bulk assign') }}
                        </a>
                    @endif
                    @if ($showShellAdd)
                        <button
                            type="button"
                            wire:click="openCreateChannelModal"
                            class="inline-flex h-6 items-center gap-1 rounded-md bg-brand-ink px-2 text-xs font-semibold text-brand-cream shadow-sm transition-colors hover:bg-brand-forest"
                        >
                            <x-heroicon-o-plus class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                            {{ __('Add channel') }}
                        </button>
                    @endif
                </x-slot:actions>

                {{-- The org page had no glance row at all while the personal one
                     did. Same three-up shape, counting what matters here: how
                     many destinations exist, how many are actually wired to
                     events, and how many page a human. --}}
                @php
                    $routedCount = $channels->where('subscriptions_count', '>', 0)->count();
                    $pagingCount = $channels->filter(fn ($c) => $c->isPaging())->count();
                @endphp
                <x-slot:stats>
                    <dl class="grid grid-cols-3 gap-px bg-brand-ink/5" aria-label="{{ __('Notification channels at a glance') }}">
                        <div class="bg-white px-3 py-2">
                            <dt class="text-2xs font-semibold uppercase tracking-wide text-brand-mist">{{ __('Channels') }}</dt>
                            <dd class="mt-0.5 flex items-baseline gap-1.5">
                                <span class="font-mono text-base font-semibold tabular-nums text-brand-ink">{{ $channelCount }}</span>
                                <span class="truncate text-xs text-brand-moss">{{ trans_choice('destination|destinations', $channelCount) }}</span>
                            </dd>
                        </div>
                        <div class="bg-white px-3 py-2">
                            <dt class="text-2xs font-semibold uppercase tracking-wide text-brand-mist">{{ __('Routed') }}</dt>
                            <dd class="mt-0.5 flex items-baseline gap-1.5">
                                <span class="font-mono text-base font-semibold tabular-nums {{ $channelCount > 0 && $routedCount === 0 ? 'text-amber-700' : 'text-brand-ink' }}">{{ $routedCount }}</span>
                                <span class="truncate text-xs text-brand-moss">
                                    {{ $channelCount > 0 && $routedCount < $channelCount
                                        ? __(':n not subscribed', ['n' => $channelCount - $routedCount])
                                        : __('subscribed to events') }}
                                </span>
                            </dd>
                        </div>
                        <div class="bg-white px-3 py-2">
                            <dt class="text-2xs font-semibold uppercase tracking-wide text-brand-mist">{{ __('Paging') }}</dt>
                            <dd class="mt-0.5 flex items-baseline gap-1.5">
                                <span class="font-mono text-base font-semibold tabular-nums text-brand-ink">{{ $pagingCount }}</span>
                                <span class="truncate text-xs text-brand-moss">{{ trans_choice('wakes on-call|wake on-call', $pagingCount) }}</span>
                            </dd>
                        </div>
                    </dl>
                </x-slot:stats>

                @include('livewire.settings.partials.notification-channels-content')
            </x-organization-shell>
        </div>
    @else
        <x-profile-shell
            :title="$pageTitle"
            :description="$intro"
            icon="heroicon-o-bell"
        >
            {{-- No "Back to profile": the breadcrumb already covers it. --}}
            <x-slot:actions>
                @if (! empty($showBulkAssign ?? false))
                    <a
                        href="{{ route('profile.notification-channels.bulk-assign') }}"
                        wire:navigate
                        class="inline-flex h-6 items-center gap-1 rounded-md border border-brand-ink/15 bg-white px-2 text-xs font-semibold text-brand-ink shadow-sm transition-colors hover:bg-brand-sand/40"
                    >
                        <x-heroicon-o-paper-airplane class="h-3.5 w-3.5 shrink-0 opacity-90" aria-hidden="true" />
                        {{ __('Bulk assign') }}
                    </a>
                @endif
                @if ($showShellAdd)
                    <button
                        type="button"
                        wire:click="openCreateChannelModal"
                        class="inline-flex h-6 items-center gap-1 rounded-md bg-brand-ink px-2 text-xs font-semibold text-brand-cream shadow-sm transition-colors hover:bg-brand-forest"
                    >
                        <x-heroicon-o-plus class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                        {{ __('Add channel') }}
                    </button>
                @endif
            </x-slot:actions>

            @php
                $orgChannelCount = isset($organizationChannels) ? $organizationChannels->count() : 0;
                $teamChannelCount = ($teamChannelGroups ?? collect())->sum(fn ($e) => $e['channels']->count());
                $teamCount = ($teamChannelGroups ?? collect())->count();
            @endphp
            <x-slot:stats>
                <dl class="grid grid-cols-3 gap-px bg-brand-ink/5" aria-label="{{ __('Notification channels at a glance') }}">
                    <div class="bg-white px-3 py-2">
                        <dt class="text-2xs font-semibold uppercase tracking-wide text-brand-mist">{{ __('Personal') }}</dt>
                        <dd class="mt-0.5 flex items-baseline gap-1.5">
                            <span class="font-mono text-base font-semibold tabular-nums text-brand-ink">{{ $channelCount }}</span>
                            <span class="truncate text-xs text-brand-moss">{{ trans_choice('channel you own|channels you own', $channelCount) }}</span>
                        </dd>
                    </div>
                    <div class="bg-white px-3 py-2">
                        <dt class="text-2xs font-semibold uppercase tracking-wide text-brand-mist">{{ __('Organization') }}</dt>
                        <dd class="mt-0.5 flex items-baseline gap-1.5">
                            <span class="font-mono text-base font-semibold tabular-nums text-brand-ink">{{ $orgChannelCount }}</span>
                            <span class="truncate text-xs text-brand-moss" title="{{ ($currentOrganization ?? null) ? $currentOrganization->name : __('No current org') }}">{{ ($currentOrganization ?? null) ? $currentOrganization->name : __('no current org') }}</span>
                        </dd>
                    </div>
                    <div class="bg-white px-3 py-2">
                        <dt class="text-2xs font-semibold uppercase tracking-wide text-brand-mist">{{ __('Teams') }}</dt>
                        <dd class="mt-0.5 flex items-baseline gap-1.5">
                            <span class="font-mono text-base font-semibold tabular-nums text-brand-ink">{{ $teamChannelCount }}</span>
                            <span class="truncate text-xs text-brand-moss">{{ trans_choice('across :n team|across :n teams', $teamCount, ['n' => $teamCount]) }}</span>
                        </dd>
                    </div>
                </dl>
            </x-slot:stats>

            @include('livewire.settings.partials.notification-channels-content')
        </x-profile-shell>
    @endif

    <x-slot name="modals">
        @include('livewire.partials.confirm-action-modal')
    </x-slot>
</div>
