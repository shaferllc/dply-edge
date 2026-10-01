@php
    use Illuminate\Support\Carbon;
    use Illuminate\Support\Number;

    $traffic = $edgeSiteTraffic ?? null;
    $isByo = (bool) ($traffic['byo_cloudflare'] ?? false);
    $hasManagedTraffic = $traffic !== null && ! $isByo;
    $daily = $hasManagedTraffic && is_array($traffic['daily'] ?? null) ? $traffic['daily'] : [];
    $maxRequests = max(1, collect($daily)->max('requests') ?? 1);
    $maxEgress = max(1, collect($daily)->max('bytes_egress') ?? 1);
    $trackedHostnames = is_array($traffic['tracked_hostnames'] ?? null) ? $traffic['tracked_hostnames'] : [];
    $peakDay = is_array($traffic['peak_day'] ?? null) && (int) ($traffic['peak_day']['requests'] ?? 0) > 0 ? $traffic['peak_day'] : null;
    $requests7d = (int) ($traffic['requests_7d'] ?? 0);
    $perDay = (int) ($traffic['avg_requests_per_day_7d'] ?? 0);
    $bytes7d = (int) ($traffic['bytes_egress_7d'] ?? 0);

    $access = $edgeSiteAccess ?? null;
    $perf = is_array($access['performance'] ?? null) ? $access['performance'] : [];
    $vitals = is_array($access['web_vitals'] ?? null) ? $access['web_vitals'] : [];
    $hasWorkerLogs = (bool) ($access['has_worker_logs'] ?? false);
    $hasWebVitals = (bool) ($access['has_web_vitals'] ?? false);
    $avgMs = (int) ($perf['avg_duration_ms'] ?? 0);
    $p95Ms = isset($perf['p95_duration_ms']) ? (int) $perf['p95_duration_ms'] : null;
    $cacheHitPercent = ($perf['cache_hit_ratio'] ?? null) !== null ? (float) $perf['cache_hit_ratio'] * 100 : null;
    $isHybrid = (string) (($edgeRuntimeMode ?? null) ?? 'static') === 'hybrid';

    $today = is_array($access['dataset'] ?? null) ? $access['dataset'] : [];
    $todayAvailable = (bool) ($today['available'] ?? false);
    $todayRequests = (int) ($today['requests'] ?? 0);
    $todayFailed = (int) ($today['status']['4xx'] ?? 0) + (int) ($today['status']['5xx'] ?? 0);

    $lcp = isset($vitals['lcp_p75_ms']) ? (int) $vitals['lcp_p75_ms'] : null;

    $row = 'flex min-h-12 w-full items-center gap-3 border-b border-brand-ink/10 py-3 text-left hover:bg-brand-sand/20';
    $state = 'shrink-0 font-mono text-xs';
    $modalHead = function (string $title, string $sub, string $name): string {
        return '<div class="flex items-start justify-between gap-4"><div><h2 class="text-lg font-semibold text-brand-ink">'.e($title).'</h2>'
            .($sub !== '' ? '<p class="mt-0.5 text-sm text-brand-moss">'.e($sub).'</p>' : '')
            .'</div><button type="button" x-on:click="$dispatch(\'close-modal\', \''.$name.'\')" class="dply-icon-btn h-9 w-9" aria-label="'.e(__('Close')).'">'
            .svg('heroicon-o-x-mark', 'h-5 w-5', ['aria-hidden' => 'true'])->toHtml().'</button></div>';
    };
@endphp

<div>
    <section class="space-y-8 border-b border-brand-ink/10 px-5 py-8 sm:px-10 sm:py-10">
        <div>
            <p class="text-2xs font-semibold uppercase tracking-[0.16em] text-brand-mist">{{ __('Traffic · last 7 days') }}</p>
            <p class="mt-3 max-w-3xl text-2xl font-medium leading-snug tracking-tight text-brand-ink sm:text-3xl">
                @if ($isByo)
                    {{ __('Request and bandwidth stats for this app live in your connected Cloudflare account.') }}
                @elseif (! $hasManagedTraffic)
                    {{ __('Traffic stats are not available yet. Preview sites and inactive deployments do not collect them.') }}
                @elseif ($requests7d > 0)
                    {{ __('Your app answered') }}
                    <span class="text-brand-sage">{{ trans_choice(':count request|:count requests', $requests7d, ['count' => number_format($requests7d)]) }}</span>{{ __(', about :n a day, and sent :size.', ['n' => number_format($perDay), 'size' => Number::fileSize($bytes7d, 1)]) }}
                @else
                    {{ __('No requests have been counted in the last 7 days.') }}
                @endif
                @if ($hasWorkerLogs)
                    {{ __('Responses took') }} <span class="text-brand-sage">{{ number_format($avgMs) }} ms</span> {{ __('on average.') }}
                @endif
                @if ($todayAvailable && $todayRequests > 0)
                    {{ trans_choice('Today so far: :count request|Today so far: :count requests', $todayRequests, ['count' => number_format($todayRequests)]) }}@if ($todayFailed > 0), <span class="text-amber-600 dark:text-amber-300">{{ __(':n failed', ['n' => number_format($todayFailed)]) }}</span>@endif.
                @endif
            </p>
        </div>

        <div>
            <p class="border-b border-brand-ink/10 pb-2 text-sm font-semibold text-brand-ink">{{ __('Look closer') }}</p>

            @if ($hasManagedTraffic)
                <button type="button" x-on:click="$dispatch('open-modal', 'traffic-requests')" class="{{ $row }}">
                    <span class="flex-1 text-sm text-brand-ink sm:text-base">
                        @if ($peakDay)
                            {{ __('Busiest day in the last 30: :day, with :n requests', ['day' => $peakDay['label'] ?? $peakDay['date'] ?? '', 'n' => number_format((int) $peakDay['requests'])]) }}
                        @else
                            {{ __('Requests and bandwidth, day by day') }}
                        @endif
                    </span>
                    <span class="{{ $state }} text-brand-moss">{{ __('~:n / day', ['n' => number_format($perDay)]) }}</span>
                    <x-heroicon-m-chevron-right class="h-4 w-4 shrink-0 text-brand-mist" aria-hidden="true" />
                </button>
            @endif

            @if ($hasManagedTraffic)
                <button type="button" x-on:click="$dispatch('open-modal', 'traffic-today')" class="{{ $row }}">
                    <span class="flex-1 text-sm text-brand-ink sm:text-base">
                        @if (! $todayAvailable)
                            {{ __('Today’s requests aren’t available yet') }}
                        @elseif ($todayFailed > 0)
                            {{ trans_choice(':count request failed today|:count requests failed today', $todayFailed, ['count' => number_format($todayFailed)]) }}
                        @else
                            {{ __('Today’s requests, response codes, and top paths') }}
                        @endif
                    </span>
                    <span @class([$state, 'text-amber-600 dark:text-amber-300' => $todayFailed > 0, 'text-brand-moss' => $todayFailed === 0])>{{ $todayAvailable ? number_format($todayRequests) : '—' }}</span>
                    <x-heroicon-m-chevron-right class="h-4 w-4 shrink-0 text-brand-mist" aria-hidden="true" />
                </button>
            @endif

            <button type="button" x-on:click="$dispatch('open-modal', 'traffic-speed')" class="{{ $row }}">
                <span class="flex-1 text-sm text-brand-ink sm:text-base">
                    @if (! $hasWorkerLogs)
                        {{ __('No response times in the last 7 days') }}
                    @elseif ($p95Ms !== null)
                        {{ __('Responses take :avg ms on average; the slowest 5% take :p95 ms', ['avg' => number_format($avgMs), 'p95' => number_format($p95Ms)]) }}
                    @else
                        {{ __('Responses take :avg ms on average', ['avg' => number_format($avgMs)]) }}
                    @endif
                </span>
                <span class="{{ $state }} text-brand-moss">{{ $cacheHitPercent !== null ? __(':p% cached', ['p' => number_format($cacheHitPercent, 1)]) : ($hasWorkerLogs ? number_format($avgMs).' ms' : '—') }}</span>
                <x-heroicon-m-chevron-right class="h-4 w-4 shrink-0 text-brand-mist" aria-hidden="true" />
            </button>

            <button type="button" x-on:click="$dispatch('open-modal', 'traffic-vitals')" class="{{ $row }}">
                <span class="flex-1 text-sm text-brand-ink sm:text-base">
                    @if (! $hasWebVitals)
                        {{ __('No page-speed readings from visitors’ browsers yet') }}
                    @elseif ($lcp !== null)
                        {{ __('For most visitors, the main content appears within :s s', ['s' => number_format($lcp / 1000, 1)]) }}
                    @else
                        {{ __('How pages feel in visitors’ browsers') }}
                    @endif
                </span>
                <span class="{{ $state }} text-brand-moss">{{ $hasWebVitals ? trans_choice(':count sample|:count samples', (int) ($vitals['samples_7d'] ?? 0), ['count' => number_format((int) ($vitals['samples_7d'] ?? 0))]) : '—' }}</span>
                <x-heroicon-m-chevron-right class="h-4 w-4 shrink-0 text-brand-mist" aria-hidden="true" />
            </button>

            @unless ($isByo)
                <button type="button" x-on:click="$dispatch('open-modal', 'traffic-live')" class="{{ $row }}">
                    <span class="flex-1 text-sm text-brand-ink sm:text-base">{{ __('Watch requests as they arrive') }}</span>
                    <span class="{{ $state }} inline-flex items-center gap-1.5 text-brand-sage"><span class="h-1.5 w-1.5 rounded-full bg-emerald-500"></span>{{ __('Live') }}</span>
                    <x-heroicon-m-chevron-right class="h-4 w-4 shrink-0 text-brand-mist" aria-hidden="true" />
                </button>
            @endunless

            @if ($trackedHostnames !== [])
                <p class="pt-3 text-xs text-brand-moss">{{ __('Counted on') }} <span class="font-mono">{{ implode(', ', $trackedHostnames) }}</span></p>
            @endif
        </div>
    </section>

    @php
        $locations = is_array($access['locations'] ?? null) ? $access['locations'] : ['colos' => [], 'countries' => []];
        $colos = $locations['colos'] ?? [];
        $colosTotal = max(1, array_sum(array_column($colos, 'requests')));
        $network = collect(\App\Modules\Edge\Support\EdgeColos::PLACES)->map(fn ($p) => [$p[1], $p[2]])->values();
    @endphp
    @if ($hasManagedTraffic || $colos !== [])
        <section class="space-y-6 border-b border-brand-ink/10 px-5 py-8 sm:px-10 sm:py-10">
            <div>
                <p class="text-2xs font-semibold uppercase tracking-[0.16em] text-brand-mist">{{ __('Served from · last 24 hours') }}</p>
                <p class="mt-3 max-w-3xl text-2xl font-medium leading-snug tracking-tight text-brand-ink sm:text-3xl">
                    @if ($colos === [])
                        {{ __('Where Cloudflare answers your visitors shows up here once requests come in.') }}
                    @else
                        {{ trans_choice('Answered from :count Cloudflare location|Answered from :count Cloudflare locations', count($colos), ['count' => count($colos)]) }}@if ($colos[0]['city'] ?? null), {{ __('most from') }} <span class="text-brand-sage">{{ $colos[0]['city'] }}</span>@endif.
                    @endif
                </p>
            </div>

            <div class="grid gap-8 lg:grid-cols-[1.6fr_1fr]">
                <div class="min-w-0 border border-brand-ink/10 bg-brand-sand/10"
                    x-data="edgeServedFrom(@js($network), @js(array_values(array_filter($colos, fn ($c) => $c['lat'] !== null))))"
                    x-init="start()">
                    <canvas x-ref="map" class="block aspect-[2/1] w-full" aria-label="{{ __('Map of the Cloudflare locations that served this site') }}" role="img"></canvas>
                </div>

                <div class="min-w-0 space-y-6">
                    @if ($colos === [])
                        <p class="text-sm text-brand-moss">{{ __('Each request is tagged with the data centre that answered it. Locations appear after the next requests reach the site.') }}</p>
                    @else
                        <ol class="divide-y divide-brand-ink/10 border-y border-brand-ink/10">
                            @foreach (array_slice($colos, 0, 8) as $colo)
                                <li class="flex items-center gap-3 py-2 text-sm">
                                    <span class="w-10 shrink-0 font-mono text-xs text-brand-sage">{{ $colo['colo'] }}</span>
                                    <span class="min-w-0 flex-1 truncate text-brand-ink">{{ $colo['city'] ?? __('Unplaced location') }}</span>
                                    <span class="relative h-1 w-16 shrink-0 bg-brand-ink/10"><span class="absolute inset-y-0 left-0 bg-brand-sage" style="width: {{ max(4, round($colo['requests'] / $colosTotal * 100)) }}%"></span></span>
                                    <span class="w-14 shrink-0 text-right font-mono text-xs tabular-nums text-brand-moss">{{ Number::abbreviate($colo['requests'], maxPrecision: 1) }}</span>
                                </li>
                            @endforeach
                        </ol>
                        @if (($locations['countries'] ?? []) !== [])
                            <div>
                                <p class="text-2xs font-semibold uppercase tracking-[0.16em] text-brand-mist">{{ __('Visitors from') }}</p>
                                <div class="mt-2 flex flex-wrap gap-1.5">
                                    @foreach ($locations['countries'] as $country)
                                        <span class="border border-brand-ink/10 px-2 py-0.5 font-mono text-xs text-brand-moss">{{ $country['country'] }} <span class="text-brand-ink">{{ Number::abbreviate($country['requests'], maxPrecision: 1) }}</span></span>
                                    @endforeach
                                </div>
                            </div>
                        @endif
                    @endif
                </div>
            </div>
        </section>

    @endif

    @if ($hasManagedTraffic)
        <x-modal name="traffic-requests" maxWidth="3xl" overlayClass="bg-brand-ink/40" focusable>
            <div class="space-y-5 p-6 sm:p-7">
                {!! $modalHead(__('Requests'), __(':req this month · :bw sent', ['req' => trans_choice(':count request|:count requests', (int) ($traffic['requests'] ?? 0), ['count' => number_format((int) ($traffic['requests'] ?? 0))]), 'bw' => Number::fileSize((int) ($traffic['bytes_egress'] ?? 0), 1)]), 'traffic-requests') !!}

                @if ($daily === [])
                    <p class="text-sm text-brand-moss">{{ __('No daily totals yet this month. They appear after the nightly collection.') }}</p>
                @else
                    @foreach ([
                        ['label' => __('Requests per day'), 'key' => 'requests', 'max' => $maxRequests, 'bar' => 'bg-brand-sage/70 group-hover:bg-brand-forest', 'fmt' => fn ($v) => number_format((int) $v)],
                        ['label' => __('Bandwidth per day'), 'key' => 'bytes_egress', 'max' => $maxEgress, 'bar' => 'bg-sky-500/70 group-hover:bg-sky-600', 'fmt' => fn ($v) => Number::fileSize((int) $v, 1)],
                    ] as $chart)
                        <div>
                            <div class="flex items-baseline justify-between gap-2">
                                <p class="text-xs font-semibold text-brand-ink">{{ $chart['label'] }}</p>
                                <span class="font-mono text-2xs text-brand-mist">{{ __('max :n', ['n' => $chart['fmt']($chart['max'])]) }}</span>
                            </div>
                            <div class="mt-2 flex h-24 items-end gap-0.5">
                                @foreach ($daily as $day)
                                    <div class="group relative flex h-full min-w-0 flex-1 cursor-help items-end">
                                        <div class="w-full rounded-t transition-colors {{ $chart['bar'] }}" style="height: {{ max(4, round(((int) ($day[$chart['key']] ?? 0) / $chart['max']) * 100)) }}%"></div>
                                        <div class="pointer-events-none absolute bottom-full left-1/2 z-20 mb-1 hidden -translate-x-1/2 whitespace-nowrap rounded bg-brand-ink px-2 py-1 text-xs font-medium text-white shadow-lg group-hover:block">
                                            <span class="font-semibold">{{ $day['label'] ?? '' }}</span> · {{ $chart['fmt']($day[$chart['key']] ?? 0) }}
                                        </div>
                                    </div>
                                @endforeach
                            </div>
                            <div class="mt-1.5 flex justify-between text-2xs text-brand-mist">
                                <span>{{ $daily[0]['label'] ?? '' }}</span>
                                <span>{{ $daily[array_key_last($daily)]['label'] ?? '' }}</span>
                            </div>
                        </div>
                    @endforeach
                @endif

                <p class="text-xs text-brand-moss">
                    {{ __('Daily totals update overnight, so today appears tomorrow.') }}
                    @if (($traffic['last_collected_date'] ?? null) !== null)
                        {{ __('Latest day counted: :date.', ['date' => Carbon::parse($traffic['last_collected_date'])->format('M j')]) }}
                    @endif
                </p>
            </div>
        </x-modal>

        <x-modal name="traffic-today" maxWidth="2xl" overlayClass="bg-brand-ink/40" focusable>
            <div class="space-y-5 p-6 sm:p-7">
                {!! $modalHead(__('Today'), __('Counted at the edge as requests arrive · UTC day'), 'traffic-today') !!}
                @include('livewire.sites.partials.edge.traffic-today')
            </div>
        </x-modal>
    @endif

    <x-modal name="traffic-speed" maxWidth="xl" overlayClass="bg-brand-ink/40" focusable>
        <div class="space-y-5 p-6 sm:p-7">
            {!! $modalHead(__('Response time'), __('Last 7 days, measured at the edge'), 'traffic-speed') !!}
            @if (! $hasWorkerLogs)
                <p class="text-sm text-brand-moss">{{ __('No response times in the last 7 days. They appear once your app serves requests.') }}</p>
            @else
                <dl class="divide-y divide-brand-ink/10 border-y border-brand-ink/10 text-sm">
                    @foreach (array_filter([
                        __('Average') => number_format($avgMs).' ms',
                        __('Slowest 5% (p95)') => $p95Ms !== null ? number_format($p95Ms).' ms' : '—',
                        __('Served from the edge cache') => $cacheHitPercent !== null ? number_format($cacheHitPercent, 1).'%' : null,
                        __('Requests measured') => number_format((int) ($perf['requests_7d'] ?? 0)),
                    ], fn ($v) => $v !== null) as $label => $value)
                        <div class="flex justify-between gap-3 py-2.5">
                            <dt class="text-brand-moss">{{ $label }}</dt>
                            <dd class="font-mono tabular-nums text-brand-ink">{{ $value }}</dd>
                        </div>
                    @endforeach
                </dl>
            @endif
            @if ($isHybrid)
                <p class="text-xs text-brand-moss">
                    {{ __('A low cache share on static paths usually means your build sends headers that stop caching.') }}
                    <a href="{{ route('sites.show', ['server' => $server ?? $site->server, 'site' => $site, 'section' => 'delivery']) }}" wire:navigate class="font-medium text-brand-sage hover:underline">{{ __('Cache controls') }}</a>
                </p>
            @endif
        </div>
    </x-modal>

    <x-modal name="traffic-vitals" maxWidth="xl" overlayClass="bg-brand-ink/40" focusable>
        <div class="space-y-5 p-6 sm:p-7">
            {!! $modalHead(__('Page speed in browsers'), __('Core Web Vitals, 75th percentile, last 7 days'), 'traffic-vitals') !!}
            @if ($hasWebVitals)
                <dl class="divide-y divide-brand-ink/10 border-y border-brand-ink/10 text-sm">
                    @foreach ([
                        [__('Main content appears (LCP)'), $lcp !== null ? number_format($lcp).' ms' : '—'],
                        [__('Responds to a tap or click (INP)'), isset($vitals['inp_p75_ms']) ? number_format($vitals['inp_p75_ms']).' ms' : '—'],
                        [__('Layout shift while loading (CLS)'), isset($vitals['cls_p75']) ? number_format($vitals['cls_p75'], 3) : '—'],
                        [__('Samples'), number_format((int) ($vitals['samples_7d'] ?? 0))],
                    ] as [$label, $value])
                        <div class="flex justify-between gap-3 py-2.5">
                            <dt class="text-brand-moss">{{ $label }}</dt>
                            <dd class="font-mono tabular-nums text-brand-ink">{{ $value }}</dd>
                        </div>
                    @endforeach
                </dl>
            @else
                <p class="text-sm text-brand-moss">{{ __('No browser samples in the last 7 days.') }}</p>
            @endif
            <p class="text-xs text-brand-moss">{{ __('dply adds a small script to the HTML pages it serves from your build output; each page view reports back. 75% of visitors saw these numbers or better.') }}</p>
        </div>
    </x-modal>

    @unless ($isByo)
        <x-modal name="traffic-live" maxWidth="5xl" overlayClass="bg-brand-ink/40" focusable>
            <div class="flex items-center justify-end px-4 pt-3">
                <button type="button" x-on:click="$dispatch('close-modal', 'traffic-live')" class="dply-icon-btn h-9 w-9" aria-label="{{ __('Close') }}">
                    <x-heroicon-o-x-mark class="h-5 w-5" aria-hidden="true" />
                </button>
            </div>
            @include('livewire.sites.partials.edge.live-request-tail', [
                'liveSeed' => is_array($today['recent'] ?? null) ? $today['recent'] : [],
            ])
        </x-modal>
    @endunless
</div>
