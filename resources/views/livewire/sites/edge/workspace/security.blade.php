@php
    $controls = collect($security['controls']);
    $byLabel = $controls->keyBy('label');
    $stopped = $security['blocked'] + $security['limited'];
    $bot = $byLabel[__('Bot protection')] ?? null;
    $rate = $byLabel[__('Rate limits')] ?? null;
    $fw = $byLabel[__('Firewall')] ?? null;
    $parts = collect([
        $bot && $bot['on'] ? \Illuminate\Support\Str::lcfirst($bot['sentence']) : null,
        $rate && $rate['on'] ? \Illuminate\Support\Str::lcfirst($rate['sentence']) : null,
        $fw && $fw['on'] ? \Illuminate\Support\Str::lcfirst($fw['sentence']) : null,
    ])->filter()->values();
    $off = $controls->where('on', false)->pluck('label')->map(fn ($l) => \Illuminate\Support\Str::lower($l));
    $domainSentence = fn (array $d): string => match ($d['tlsTone']) {
        'ok' => __(':host has an active certificate', ['host' => $d['hostname']]),
        'bad' => __(':host’s certificate failed', ['host' => $d['hostname']]),
        'warn' => __(':host is getting its certificate', ['host' => $d['hostname']]),
        default => __(':host is waiting for a certificate', ['host' => $d['hostname']]),
    };
    $domainsUrl = route('sites.show', ['server' => $server, 'site' => $site, 'section' => 'routing', 'tab' => 'domains']);
    $trafficUrl = route('sites.show', ['server' => $server, 'site' => $site, 'section' => 'traffic']);
    $row = 'flex min-h-12 w-full items-center gap-3 border-b border-brand-ink/10 py-3 text-left hover:bg-brand-sand/20';
@endphp

<div>
    <section class="border-b border-brand-ink/10 px-5 py-4 sm:px-6">
        @include('livewire.sites.edge.workspace.partials.feature-guide', [
            'open' => $outboundCertificate !== null,
            'what' => __('A summary of this app only: its hostnames and TLS, whether firewall, bot protection, and rate limits are on, and requests Edge blocked in the last 7 days.'),
            'steps' => array_values(array_filter([
                __('Read the summary, then each row: your addresses and their certificates, and each protection. Click a row to open its page.'),
                __('Off (in amber) means that check isn’t running; turn it on from its page.'),
                __('Stopped requests come from this app’s request log: 403 is the firewall or another Edge rule, 429 is a rate limit.'),
                ($outboundCertificate !== null)
                    ? __('Calls to other servers: when this app calls another server over HTTPS, that server sees a certificate from this app. Deploy before those calls present it. The other server must trust the certificate.')
                    : null,
            ])),
            'tips' => [
                __('This is not an account-wide scanner. Other apps in the workspace are not included.'),
            ],
        ])
    </section>

    <section class="space-y-8 border-b border-brand-ink/10 px-5 py-8 sm:px-10 sm:py-10">
        <div>
            <p class="text-2xs font-semibold uppercase tracking-[0.16em] text-brand-mist">{{ __('Security') }}</p>
            <p class="mt-3 max-w-3xl text-2xl font-medium leading-snug tracking-tight text-brand-ink sm:text-3xl">
                @if ($security['liveUrl'])
                    {{ __('Your site is served over HTTPS.') }}
                @else
                    {{ __('HTTPS starts with the first deploy.') }}
                @endif
                @if ($parts->isNotEmpty())
                    {{ \Illuminate\Support\Str::ucfirst($parts->count() > 1 ? $parts->slice(0, -1)->implode(', ').' '.__('and').' '.$parts->last() : $parts->first()) }}.
                @endif
                @if ($off->isNotEmpty())
                    <span class="text-amber-600 dark:text-amber-300">{{ __('Off: :list.', ['list' => $off->implode(', ')]) }}</span>
                @endif
                @if ($stopped > 0)
                    {{ __('In the last 7 days,') }}
                    <span class="text-amber-600 dark:text-amber-300">{{ trans_choice(':count request was blocked|:count requests were blocked', $security['blocked']) }}</span>
                    {{ trans_choice('and :count was rate limited.|and :count were rate limited.', $security['limited']) }}
                @else
                    {{ __('Nothing was blocked in the last 7 days.') }}
                @endif
            </p>
        </div>

        <div>
            <p class="border-b border-brand-ink/10 pb-2 text-sm font-semibold text-brand-ink">{{ __('What’s protecting this app') }}</p>
            <a href="{{ $domainsUrl }}" wire:navigate class="{{ $row }}">
                <span class="flex-1 text-sm text-brand-ink sm:text-base">
                    @if ($security['hostname'] !== '')
                        <span class="font-mono">{{ $security['hostname'] }}</span> {{ $security['liveUrl'] ? __('is served over HTTPS') : __('gets HTTPS on its first deploy') }}
                    @else
                        {{ __('Your address is set on the first deploy') }}
                    @endif
                </span>
                <span @class(['shrink-0 text-xs', 'text-brand-sage' => (bool) $security['liveUrl'], 'text-amber-600 dark:text-amber-300' => ! $security['liveUrl']])>{{ $security['tlsLabel'] }}</span>
                <x-heroicon-m-chevron-right class="h-4 w-4 shrink-0 text-brand-mist" aria-hidden="true" />
            </a>
            @foreach ($security['domains'] as $domain)
                <a href="{{ $domainsUrl }}" wire:navigate class="{{ $row }}">
                    <span class="flex-1 text-sm text-brand-ink sm:text-base">{{ $domainSentence($domain) }}</span>
                    <span @class(['shrink-0 text-xs', 'text-brand-sage' => $domain['tlsTone'] === 'ok', 'text-amber-600 dark:text-amber-300' => $domain['tlsTone'] !== 'ok'])>{{ $domain['tlsLabel'] }}</span>
                    <x-heroicon-m-chevron-right class="h-4 w-4 shrink-0 text-brand-mist" aria-hidden="true" />
                </a>
            @endforeach
            @foreach ($security['controls'] as $control)
                <a href="{{ $control['href'] }}" wire:navigate class="{{ $row }}">
                    <span class="flex-1 text-sm text-brand-ink sm:text-base">{{ $control['sentence'] }}</span>
                    <span @class(['shrink-0 text-xs', 'text-brand-sage' => $control['on'], 'text-amber-600 dark:text-amber-300' => ! $control['on']])>{{ $control['on'] ? $control['detail'] : __('Off') }}</span>
                    <x-heroicon-m-chevron-right class="h-4 w-4 shrink-0 text-brand-mist" aria-hidden="true" />
                </a>
            @endforeach
            @if ($outboundCertificate !== null)
                <button type="button" x-on:click="$dispatch('open-modal', 'security-certificate')" class="{{ $row }}">
                    <span class="flex-1 text-sm text-brand-ink sm:text-base">{{ $outboundCertificate === '' ? __('Calls to other servers don’t present a certificate') : __('Calls to other servers present this app’s certificate') }}</span>
                    <span @class(['shrink-0 text-xs', 'text-brand-sage' => $outboundCertificate !== '', 'text-amber-600 dark:text-amber-300' => $outboundCertificate === ''])>{{ $outboundCertificate === '' ? __('Off') : __('On') }}</span>
                    <x-heroicon-m-chevron-right class="h-4 w-4 shrink-0 text-brand-mist" aria-hidden="true" />
                </button>
            @endif
        </div>

        <div>
            <p class="border-b border-brand-ink/10 pb-2 text-sm font-semibold text-brand-ink">{{ __('Stopped requests') }}</p>
            @if ($security['recent'] === [])
                <p class="border-b border-brand-ink/10 py-4 text-sm text-brand-moss">{{ __('No blocked requests in the last 7 days.') }}</p>
            @else
                <button type="button" x-on:click="$dispatch('open-modal', 'security-stopped')" class="{{ $row }}">
                    <span class="flex-1 text-sm text-brand-ink sm:text-base">{{ trans_choice(':count request was stopped this week|:count requests were stopped this week', $stopped) }}</span>
                    <span class="shrink-0 text-xs text-brand-moss">{{ __('See them') }}</span>
                    <x-heroicon-m-chevron-right class="h-4 w-4 shrink-0 text-brand-mist" aria-hidden="true" />
                </button>
            @endif
        </div>
    </section>

    <x-modal name="security-stopped" maxWidth="2xl" overlayClass="bg-brand-ink/40" focusable>
        <div class="space-y-4 p-6 sm:p-7">
            <div class="flex items-start justify-between gap-4">
                <div>
                    <h2 class="text-lg font-semibold text-brand-ink">{{ __('Stopped requests') }}</h2>
                    <p class="mt-0.5 text-sm text-brand-moss">{{ __('Last 7 days · :b blocked (403) · :l rate limited (429)', ['b' => number_format($security['blocked']), 'l' => number_format($security['limited'])]) }}</p>
                </div>
                <button type="button" x-on:click="$dispatch('close-modal', 'security-stopped')" class="dply-icon-btn h-9 w-9" aria-label="{{ __('Close') }}">
                    <x-heroicon-o-x-mark class="h-5 w-5" aria-hidden="true" />
                </button>
            </div>
            <ul class="divide-y divide-brand-ink/10 border-y border-brand-ink/10">
                @foreach ($security['recent'] as $recent)
                    <li class="flex items-center gap-3 py-2.5 text-sm">
                        <span @class(['w-10 shrink-0 font-mono text-xs font-semibold', 'text-rose-600 dark:text-rose-300' => $recent['status'] === 403, 'text-sky-600 dark:text-sky-400' => $recent['status'] === 429])>{{ $recent['status'] }}</span>
                        <span class="shrink-0 font-mono text-xs text-brand-moss">{{ $recent['method'] }}</span>
                        <span class="min-w-0 flex-1 truncate font-mono text-brand-ink" title="{{ $recent['path'] }}">{{ $recent['path'] }}</span>
                        @if ($recent['country'] !== '')
                            <span class="shrink-0 font-mono text-xs text-brand-moss">{{ $recent['country'] }}</span>
                        @endif
                        <span class="w-24 shrink-0 text-right text-xs text-brand-moss">{{ $recent['when'] }}</span>
                    </li>
                @endforeach
            </ul>
            <p class="text-xs text-brand-moss">
                {{ __('403 comes from the firewall or another Edge rule; 429 from a rate limit. Showing the latest :n.', ['n' => count($security['recent'])]) }}
                <a href="{{ $trafficUrl }}" wire:navigate class="font-medium text-brand-sage hover:underline">{{ __('Full request log') }}</a>
            </p>
        </div>
    </x-modal>

    @if ($outboundCertificate !== null)
        <x-modal name="security-certificate" maxWidth="xl" overlayClass="bg-brand-ink/40" focusable>
            <div class="space-y-4 p-6 sm:p-7" x-data="{ confirmRemove: false }">
                <div class="flex items-start justify-between gap-4">
                    <h2 class="text-lg font-semibold text-brand-ink">{{ __('Certificate for calls to other servers') }}</h2>
                    <button type="button" x-on:click="$dispatch('close-modal', 'security-certificate')" class="dply-icon-btn h-9 w-9" aria-label="{{ __('Close') }}">
                        <x-heroicon-o-x-mark class="h-5 w-5" aria-hidden="true" />
                    </button>
                </div>
                <p class="text-sm text-brand-moss">{{ __('When this app calls another server over HTTPS, that server sees this certificate and can tell the call came from this app. It only counts as authenticated if that server trusts the certificate; servers that don’t ask for one ignore it. Visitors still use the certificate on this app’s own address.') }}</p>
                @if ($outboundCertificate !== '')
                    <p class="text-sm font-medium text-brand-ink">{{ __('Deploy this app before those calls present the certificate.') }}</p>
                @endif
                <x-input-error :messages="$errors->get('outboundCertificate')" />
                <div class="flex flex-wrap items-center justify-between gap-2">
                    @if ($outboundCertificate === '')
                        <span></span>
                        @can('update', $site)
                            <x-primary-button type="button" wire:click="enableOutboundCertificate" wire:loading.attr="disabled" wire:target="enableOutboundCertificate">{{ __('Turn on') }}</x-primary-button>
                        @endcan
                    @else
                        @can('update', $site)
                            <span>
                                <button type="button" x-show="! confirmRemove" x-on:click="confirmRemove = true" class="text-sm font-semibold text-red-600 hover:underline">{{ __('Remove certificate') }}</button>
                                <span x-cloak x-show="confirmRemove" class="flex items-center gap-2 text-sm">
                                    <span class="text-brand-moss">{{ __('Calls stop presenting it on the next deploy. Can’t be undone.') }}</span>
                                    <button type="button" wire:click="removeOutboundCertificate" class="font-semibold text-red-600 hover:underline">{{ __('Remove') }}</button>
                                    <button type="button" x-on:click="confirmRemove = false" class="text-brand-moss hover:underline">{{ __('Keep') }}</button>
                                </span>
                            </span>
                        @else
                            <span></span>
                        @endcan
                        @can('deploy', $site)
                            <x-primary-button type="button" wire:click="redeployEdge" wire:loading.attr="disabled" wire:target="redeployEdge">{{ __('Deploy') }}</x-primary-button>
                        @endcan
                    @endif
                </div>
            </div>
        </x-modal>
    @endif
</div>
