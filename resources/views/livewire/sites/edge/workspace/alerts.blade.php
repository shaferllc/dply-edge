@php
    $hasRepoAlerts = collect($repoAlerts ?? [])->contains(fn ($m) => is_array($m) && ($m['enabled'] ?? false));
@endphp

<div>
    <section class="border-b border-brand-ink/10 px-5 py-4 sm:px-6">
        @include('livewire.sites.edge.workspace.partials.feature-guide', [
            'docSlug' => 'alerts',
            'what' => __('Route Edge events to notification channels, and set RUM / error thresholds that publish edge.rum.breach when crossed.'),
            'steps' => [
                __('Click a rule to choose which channels hear about it.'),
                __('The real-user metric rule holds the LCP / 5xx thresholds — checked hourly against the last 60 minutes.'),
                __('Wire channels before a launch so failures and breaches reach someone.'),
            ],
            'setupLinks' => [
                [
                    'label' => __('Traffic & analytics'),
                    'href' => route('sites.show', ['server' => $server, 'site' => $site, 'section' => 'traffic']),
                ],
            ],
            'tips' => [
                __('Same channel system as BYO site notifications — create channels inline or under My channels.'),
                __('In-app inbox still notifies stakeholders even without a Slack/email channel.'),
            ],
        ])
    </section>

    @php
        $join = fn ($names) => collect($names)->count() > 2
            ? collect($names)->take(2)->implode(', ').' +'.(collect($names)->count() - 2)
            : collect($names)->implode(__(' and '));
        $lastAlert = $recentAlerts->first();
        $rule = $editingRule !== null ? ($rules[$editingRule] ?? null) : null;
        $input = 'block w-24 rounded-md border border-brand-ink/15 bg-white px-2 py-1.5 font-mono text-xs text-brand-ink focus:border-brand-forest focus:ring-brand-forest disabled:opacity-50 dark:border-brand-mist/20 dark:bg-zinc-900';
    @endphp

    <section class="space-y-8 border-b border-brand-ink/10 px-5 py-8 sm:px-10 sm:py-10">
        <div>
            <p class="text-2xs font-semibold uppercase tracking-[0.16em] text-brand-mist">{{ __('Alerts') }}</p>
            <p class="mt-3 max-w-3xl text-2xl font-medium leading-snug tracking-tight text-brand-ink sm:text-3xl">
                @if ($assignableNotificationChannels->isEmpty())
                    {{ __('Nothing reaches anyone yet. Add a channel, then pick what it hears about.') }}
                @elseif ($routedCount === 0)
                    {{ __('No events are routed yet. Pick a rule below to send it to a channel.') }}
                @else
                    {{ __(':n of :total events reach someone, through', ['n' => $routedCount, 'total' => $eventCount]) }}
                    <span class="text-brand-sage">{{ $join($routedChannels) }}</span>.
                @endif
                @if ($lastAlert)
                    {{ __('The last alert was :ago.', ['ago' => $lastAlert->created_at->diffForHumans()]) }}
                @endif
            </p>
        </div>

        <div>
            <div class="flex items-center justify-between gap-3 border-b border-brand-ink/10 pb-2">
                <p class="text-sm font-semibold text-brand-ink">{{ __('When something happens') }}</p>
                <span class="flex items-center gap-4">
                    <a href="{{ route('profile.notification-channels') }}" wire:navigate class="text-xs text-brand-moss hover:text-brand-ink hover:underline">{{ __('Manage channels') }}</a>
                    <button type="button" wire:click="openCreateChannelModal" class="inline-flex min-h-9 items-center gap-1 text-sm font-medium text-brand-sage hover:underline">
                        <x-heroicon-m-plus class="h-4 w-4" aria-hidden="true" />{{ __('Add channel') }}
                    </button>
                </span>
            </div>
            <ul>
                @foreach ($rules as $key => $r)
                    <li class="border-b border-brand-ink/10" wire:key="alert-rule-{{ $key }}">
                        <button type="button" wire:click="editRule('{{ $key }}')" class="flex min-h-12 w-full items-center gap-3 py-3 text-left hover:bg-brand-sand/20">
                            <span class="flex-1 text-sm text-brand-ink sm:text-base">{{ $r['sentence'] }}</span>
                            @if ($r['to'] === [])
                                <span class="text-sm text-amber-600 dark:text-amber-300">{{ __('Nobody') }}</span>
                            @else
                                <span class="text-right text-sm text-brand-moss">{{ $join($r['to']) }}</span>
                            @endif
                            <x-heroicon-m-chevron-right class="h-4 w-4 shrink-0 text-brand-mist" aria-hidden="true" />
                        </button>
                    </li>
                @endforeach
            </ul>
        </div>

        @if ($recentAlerts->isNotEmpty())
            <div>
                <p class="border-b border-brand-ink/10 pb-2 text-sm font-semibold text-brand-ink">{{ __('Recent alerts') }}</p>
                <ul>
                    @foreach ($recentAlerts as $alert)
                        <li class="flex min-h-12 items-center gap-3 border-b border-brand-ink/10 py-2.5" wire:key="alert-recent-{{ $alert->id }}">
                            <span class="flex-1 text-sm text-brand-ink">{{ $alert->title }}</span>
                            <time datetime="{{ $alert->created_at->toIso8601String() }}" title="{{ $alert->created_at->toDayDateTimeString() }}" class="shrink-0 font-mono text-xs text-brand-mist">{{ $alert->created_at->format('M j') }}</time>
                        </li>
                    @endforeach
                </ul>
            </div>
        @endif
    </section>

    <x-modal name="edge-alert-rule" maxWidth="2xl" overlayClass="bg-brand-ink/40" focusable>
        @if ($rule)
            <div class="space-y-6 p-6 sm:p-7">
                <div class="flex items-start justify-between gap-4">
                    <h2 class="text-lg font-semibold leading-snug text-brand-ink">{{ $rule['sentence'] }}</h2>
                    <button type="button" wire:click="cancelRule" class="dply-icon-btn h-9 w-9" aria-label="{{ __('Close') }}">
                        <x-heroicon-o-x-mark class="h-5 w-5" aria-hidden="true" />
                    </button>
                </div>

                <div>
                    <p class="text-2xs font-semibold uppercase tracking-[0.14em] text-brand-mist">{{ __('Send to') }}</p>
                    @if ($assignableNotificationChannels->isEmpty())
                        <p class="mt-2 rounded-lg border border-dashed border-brand-ink/15 px-3 py-3 text-sm text-brand-moss">
                            {{ __('No channels yet.') }}
                            <button type="button" x-on:click="$wire.cancelRule().then(() => $wire.openCreateChannelModal())" class="font-medium text-brand-sage hover:underline">{{ __('Add one') }}</button>
                        </p>
                    @else
                        <div class="mt-2 overflow-x-auto rounded-lg border border-brand-ink/10">
                            <table class="w-full text-sm">
                                <thead>
                                    <tr class="text-left text-xs text-brand-mist">
                                        <th class="px-3 py-2 font-normal"><span class="sr-only">{{ __('Event') }}</span></th>
                                        @foreach ($assignableNotificationChannels as $channel)
                                            <th class="px-3 py-2 text-center font-normal">
                                                <span class="block text-brand-ink">{{ $channel->label }}</span>
                                                <span class="block">{{ \App\Models\NotificationChannel::labelForType($channel->type) }}</span>
                                            </th>
                                        @endforeach
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach ($rule['events'] as $eventKey => $eventLabel)
                                        <tr class="border-t border-brand-ink/10">
                                            <td class="px-3 py-2 text-brand-ink">{{ $eventLabel }}</td>
                                            @foreach ($assignableNotificationChannels as $channel)
                                                <td class="px-3 py-1 text-center">
                                                    <label class="inline-flex h-11 w-11 cursor-pointer items-center justify-center">
                                                        <span class="sr-only">{{ __(':event to :channel', ['event' => $eventLabel, 'channel' => $channel->label]) }}</span>
                                                        <input type="checkbox" value="{{ $eventKey }}" wire:model="channelEventSelections.{{ $channel->id }}" class="h-4 w-4 rounded border-brand-ink/30 text-brand-forest focus:ring-brand-forest" />
                                                    </label>
                                                </td>
                                            @endforeach
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    @endif
                </div>

                @if ($editingRule === 'rum')
                    <div>
                        <p class="text-2xs font-semibold uppercase tracking-[0.14em] text-brand-mist">{{ __('Fire when, over the last hour') }}</p>
                        <div class="mt-2 space-y-2">
                            @foreach ([
                                ['lcp_enabled', 'lcp_threshold', __('LCP p75 is above'), 'ms', 100, 60000, 50, __('Good: ≤2500ms')],
                                ['err_rate_enabled', 'err_rate_threshold', __('5xx error rate is above'), '%', 0.1, 100, 0.1, __('Healthy: under 1%')],
                                ['err_count_enabled', 'err_count_threshold', __('5xx responses are above'), '/h', 1, 1000000, 1, __('Absolute last-hour total')],
                            ] as [$toggle, $field, $label, $unit, $min, $max, $step, $hint])
                                <div>
                                    <div class="flex items-center gap-3">
                                        <label class="flex flex-1 cursor-pointer items-center gap-3 text-sm text-brand-ink">
                                            <input type="checkbox" wire:model="{{ $toggle }}" class="h-4 w-4 rounded border-brand-ink/30 text-brand-forest focus:ring-brand-forest" />
                                            <span>{{ $label }} <span class="block text-xs text-brand-mist">{{ $hint }}</span></span>
                                        </label>
                                        <label class="sr-only" for="alert-{{ $field }}">{{ $label }}</label>
                                        <input id="alert-{{ $field }}" type="number" min="{{ $min }}" max="{{ $max }}" step="{{ $step }}" wire:model="{{ $field }}" x-bind:disabled="! $wire.{{ $toggle }}" class="{{ $input }}" />
                                        <span class="w-6 text-xs text-brand-mist">{{ $unit }}</span>
                                    </div>
                                    @error($field) <p class="mt-1 text-right text-xs text-rose-600">{{ $message }}</p> @enderror
                                </div>
                            @endforeach
                        </div>
                        <p class="mt-3 text-xs text-brand-moss">{{ __('Checked hourly. At most one alert per kind every 6 hours.') }}</p>
                    </div>
                @endif

                <div class="flex items-center justify-end gap-2">
                    <button type="button" wire:click="cancelRule" class="rounded-lg border border-brand-ink/15 px-4 py-2 text-sm font-medium text-brand-ink hover:bg-brand-sand/40">{{ __('Cancel') }}</button>
                    @can('update', $site)
                        <x-primary-button type="button" wire:click="saveRule" wire:loading.attr="disabled" wire:target="saveRule">{{ __('Save') }}</x-primary-button>
                    @endcan
                </div>
            </div>
        @endif
    </x-modal>

    <details class="group" @if ($hasRepoAlerts) open @endif>
        <summary class="flex cursor-pointer list-none items-center justify-between gap-3 bg-brand-sand/10 px-5 py-3.5 text-sm font-semibold text-brand-ink hover:bg-brand-sand/20 sm:px-6 [&::-webkit-details-marker]:hidden">
            <span class="inline-flex items-center gap-2">
                {{ __('Advanced') }}
                @if ($hasRepoAlerts)
                    <span class="rounded-full bg-brand-sand/60 px-2 py-0.5 font-mono text-2xs font-semibold uppercase tracking-wide text-brand-moss">{{ __('Repo') }}</span>
                @endif
            </span>
            <x-heroicon-m-chevron-down class="h-4 w-4 text-brand-mist transition group-open:rotate-180" />
        </summary>

        <div class="space-y-4 border-t border-brand-ink/10 px-5 py-4 sm:px-6">
            <div class="flex flex-wrap items-center justify-between gap-2">
                <p class="text-2xs font-semibold uppercase tracking-[0.16em] text-brand-mist">{{ __('From :file', ['file' => $sourcePath]) }}</p>
                <a
                    href="{{ route('sites.edge.dply-yaml', ['server' => $site->server_id, 'site' => $site->id]) }}"
                    class="inline-flex items-center gap-1 text-xs font-medium text-brand-sage hover:underline"
                >
                    <x-heroicon-o-arrow-down-tray class="h-3.5 w-3.5" aria-hidden="true" />
                    {{ __('Generate :file', ['file' => $sourcePath]) }}
                </a>
            </div>

            @if ($hasRepoAlerts)
                <ul class="space-y-1 font-mono text-xs text-brand-ink">
                    @foreach (['lcp_p75_ms' => 'LCP p75', 'error_rate' => '5xx rate', 'five_xx_count' => '5xx count'] as $key => $label)
                        @php $m = is_array($repoAlerts[$key] ?? null) ? $repoAlerts[$key] : null; @endphp
                        @if ($m && ($m['enabled'] ?? false))
                            <li>
                                <span class="text-brand-mist">{{ $label }}:</span>
                                {{ $m['threshold'] }}{{ $key === 'error_rate' ? '%' : ($key === 'lcp_p75_ms' ? 'ms' : '') }}
                            </li>
                        @endif
                    @endforeach
                </ul>
                <p class="text-xs text-brand-mist">{{ __('Dashboard overrides merge with the repo on the next check.') }}</p>
            @else
                <p class="text-sm text-brand-moss">{{ __('None declared in :file yet.', ['file' => $sourcePath]) }}</p>
            @endif

            <x-edge-yaml-example :file="$sourcePath" :hint="__('Commit thresholds in the repo, or set them on the real-user metric rule above.')">
alerts:
  lcp_p75_ms:
    enabled: true
    threshold: 2500
  error_rate:
    enabled: true
    threshold: 5
  five_xx_count:
    enabled: true
    threshold: 50
            </x-edge-yaml-example>
        </div>
    </details>

    @include('livewire.partials.create-notification-channel-modal')
</div>
