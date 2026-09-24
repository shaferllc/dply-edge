@php
    $traffic = $edgeSiteTraffic ?? null;
    $hasManagedTraffic = $traffic !== null && ! ($traffic['byo_cloudflare'] ?? false);
    $maxRequests = max(1, collect($traffic['daily'] ?? [])->max('requests') ?? 1);
    $maxEgress = max(1, collect($traffic['daily'] ?? [])->max('bytes_egress') ?? 1);
    $trackedHostnames = is_array($traffic['tracked_hostnames'] ?? null) ? $traffic['tracked_hostnames'] : [];
    $peakDay = is_array($traffic['peak_day'] ?? null) ? $traffic['peak_day'] : null;

    $access = $edgeSiteAccess ?? null;
    $perf = is_array($access['performance'] ?? null) ? $access['performance'] : [];
    $vitals = is_array($access['web_vitals'] ?? null) ? $access['web_vitals'] : [];
    $hasWorkerLogs = (bool) ($access['has_worker_logs'] ?? false);
    $hasWebVitals = (bool) ($access['has_web_vitals'] ?? false);
    $cacheHitRatio = $perf['cache_hit_ratio'] ?? null;
    $cacheHitPercent = $cacheHitRatio !== null ? (float) $cacheHitRatio * 100 : null;
    $isHybridForCache = (string) (($edgeRuntimeMode ?? null) ?? 'static') === 'hybrid';
@endphp

<div>
    @if ($traffic !== null && ($traffic['byo_cloudflare'] ?? false))
        <div class="border-b border-brand-ink/10 px-5 py-6 sm:px-6">
            <p class="text-sm font-semibold text-brand-ink">{{ __('Traffic stats live in your connected account') }}</p>
            <p class="mt-1 text-sm text-brand-moss">{{ __('Open your delivery provider dashboard for request and bandwidth analytics on this site’s hostnames.') }}</p>
            @if ($trackedHostnames !== [])
                <p class="mt-2 font-mono text-xs text-brand-mist">{{ implode(', ', $trackedHostnames) }}</p>
            @endif
        </div>
    @elseif (! $hasManagedTraffic)
        <p class="px-5 py-10 text-center text-sm text-brand-moss sm:px-6">
            {{ __('Traffic stats are not available yet.') }}
            <span class="mt-1 block text-xs">{{ __('Preview sites and inactive deployments do not collect visitor metrics.') }}</span>
        </p>
    @else
        <section class="border-b border-brand-ink/10 px-5 py-4 sm:px-6">
            <div class="flex flex-wrap items-baseline justify-between gap-2">
                <p class="text-2xs font-semibold uppercase tracking-[0.16em] text-brand-mist">{{ __('CDN traffic') }}</p>
                @if (($traffic['last_collected_date'] ?? null) !== null)
                    <p class="text-xs text-brand-mist">
                        {{ __('Latest: :date', ['date' => \Illuminate\Support\Carbon::parse($traffic['last_collected_date'])->format('M j, Y')]) }}
                    </p>
                @endif
            </div>
            <p class="mt-1 text-xs text-brand-moss">{{ $traffic['collection_delay_note'] ?? __('Updates daily — same-day visits appear the next day.') }}</p>

            <dl class="mt-4 grid grid-cols-2 gap-3 sm:grid-cols-4">
                <div>
                    <dt class="text-2xs font-semibold uppercase tracking-wide text-brand-mist">{{ __('Requests MTD') }}</dt>
                    <dd class="mt-1 text-xl font-semibold tabular-nums text-brand-ink">{{ number_format($traffic['requests'] ?? 0) }}</dd>
                </div>
                <div>
                    <dt class="text-2xs font-semibold uppercase tracking-wide text-brand-mist">{{ __('Requests 7d') }}</dt>
                    <dd class="mt-1 text-xl font-semibold tabular-nums text-brand-ink">{{ number_format($traffic['requests_7d'] ?? 0) }}</dd>
                    <dd class="text-xs text-brand-mist">{{ __('~:avg / day', ['avg' => number_format($traffic['avg_requests_per_day_7d'] ?? 0)]) }}</dd>
                </div>
                <div>
                    <dt class="text-2xs font-semibold uppercase tracking-wide text-brand-mist">{{ __('Bandwidth MTD') }}</dt>
                    @php
                        $bytesMtd = (int) ($traffic['bytes_egress'] ?? 0);
                        if ($bytesMtd >= 1024 ** 3) {
                            $bandwidthValue = number_format($bytesMtd / (1024 ** 3), 2);
                            $bandwidthUnit = 'GB';
                        } elseif ($bytesMtd >= 1024 ** 2) {
                            $bandwidthValue = number_format($bytesMtd / (1024 ** 2), 1);
                            $bandwidthUnit = 'MB';
                        } else {
                            $bandwidthValue = number_format($bytesMtd / 1024, 0);
                            $bandwidthUnit = 'KB';
                        }
                    @endphp
                    <dd class="mt-1 text-xl font-semibold tabular-nums text-brand-ink">{{ $bandwidthValue }} <span class="text-sm font-medium text-brand-moss">{{ $bandwidthUnit }}</span></dd>
                </div>
                <div>
                    <dt class="text-2xs font-semibold uppercase tracking-wide text-brand-mist">{{ __('Peak day 30d') }}</dt>
                    @if ($peakDay !== null && (int) ($peakDay['requests'] ?? 0) > 0)
                        <dd class="mt-1 text-xl font-semibold tabular-nums text-brand-ink">{{ number_format($peakDay['requests']) }}</dd>
                        <dd class="text-xs text-brand-mist">{{ $peakDay['label'] ?? $peakDay['date'] ?? '' }}</dd>
                    @else
                        <dd class="mt-1 text-xl font-semibold tabular-nums text-brand-ink">—</dd>
                    @endif
                </div>
            </dl>
        </section>

        @if (($traffic['daily'] ?? []) !== [])
            @php
                $tDaily = $traffic['daily'];
                $tLastIdx = count($tDaily) - 1;
                $tMidIdx = (int) floor($tLastIdx / 2);
                $tMaxEgressMb = ($maxEgress / (1024 ** 2));
            @endphp
            <div class="grid border-b border-brand-ink/10 lg:grid-cols-2 lg:divide-x lg:divide-brand-ink/10">
                <section class="px-5 py-4 sm:px-6">
                    <div class="flex items-baseline justify-between gap-2">
                        <p class="text-2xs font-semibold uppercase tracking-[0.16em] text-brand-mist">{{ __('Daily requests') }}</p>
                        <span class="font-mono text-2xs text-brand-mist">{{ __('max :n', ['n' => number_format((int) $maxRequests)]) }}</span>
                    </div>
                    <div class="mt-3 flex h-24 items-end gap-0.5">
                        @foreach ($tDaily as $day)
                            <div class="group relative flex h-full min-w-0 flex-1 cursor-help items-end">
                                <div
                                    class="w-full rounded-t bg-brand-sage/70 transition-colors group-hover:bg-brand-forest"
                                    style="height: {{ max(4, round(($day['requests'] / $maxRequests) * 100)) }}%"
                                ></div>
                                <div class="pointer-events-none absolute bottom-full left-1/2 z-20 mb-1 hidden -translate-x-1/2 whitespace-nowrap rounded bg-brand-ink px-2 py-1 text-xs font-medium text-white shadow-lg group-hover:block">
                                    <span class="font-semibold">{{ $day['label'] ?? '' }}</span> · {{ number_format($day['requests'] ?? 0) }}
                                </div>
                            </div>
                        @endforeach
                    </div>
                    <div class="mt-2 flex justify-between text-2xs text-brand-mist">
                        <span>{{ $tDaily[0]['label'] ?? '' }}</span>
                        @if ($tMidIdx > 0 && $tMidIdx < $tLastIdx)
                            <span>{{ $tDaily[$tMidIdx]['label'] ?? '' }}</span>
                        @endif
                        @if ($tLastIdx > 0)
                            <span>{{ $tDaily[$tLastIdx]['label'] ?? '' }}</span>
                        @endif
                    </div>
                </section>

                <section class="border-t border-brand-ink/10 px-5 py-4 sm:px-6 lg:border-t-0">
                    <div class="flex items-baseline justify-between gap-2">
                        <p class="text-2xs font-semibold uppercase tracking-[0.16em] text-brand-mist">{{ __('Daily bandwidth') }}</p>
                        <span class="font-mono text-2xs text-brand-mist">{{ __('max :n MB', ['n' => number_format($tMaxEgressMb, 1)]) }}</span>
                    </div>
                    <div class="mt-3 flex h-24 items-end gap-0.5">
                        @foreach ($tDaily as $day)
                            <div class="group relative flex h-full min-w-0 flex-1 cursor-help items-end">
                                <div
                                    class="w-full rounded-t bg-sky-500/70 transition-colors group-hover:bg-sky-600"
                                    style="height: {{ max(4, round(($day['bytes_egress'] / $maxEgress) * 100)) }}%"
                                ></div>
                                <div class="pointer-events-none absolute bottom-full left-1/2 z-20 mb-1 hidden -translate-x-1/2 whitespace-nowrap rounded bg-brand-ink px-2 py-1 text-xs font-medium text-white shadow-lg group-hover:block">
                                    <span class="font-semibold">{{ $day['label'] ?? '' }}</span> · {{ number_format(($day['bytes_egress'] ?? 0) / (1024 ** 2), 1) }} MB
                                </div>
                            </div>
                        @endforeach
                    </div>
                    <div class="mt-2 flex justify-between text-2xs text-brand-mist">
                        <span>{{ $tDaily[0]['label'] ?? '' }}</span>
                        @if ($tMidIdx > 0 && $tMidIdx < $tLastIdx)
                            <span>{{ $tDaily[$tMidIdx]['label'] ?? '' }}</span>
                        @endif
                        @if ($tLastIdx > 0)
                            <span>{{ $tDaily[$tLastIdx]['label'] ?? '' }}</span>
                        @endif
                    </div>
                </section>
            </div>
        @else
            <p class="border-b border-brand-ink/10 px-5 py-6 text-sm text-brand-moss sm:px-6">
                {{ __('No daily snapshots yet this month. Stats appear after nightly collection.') }}
            </p>
        @endif

        @if ($trackedHostnames !== [])
            <details class="group border-b border-brand-ink/10">
                <summary class="flex cursor-pointer list-none items-center justify-between gap-3 bg-brand-sand/10 px-5 py-3 text-sm font-semibold text-brand-ink hover:bg-brand-sand/20 sm:px-6 [&::-webkit-details-marker]:hidden">
                    <span>{{ __('Tracked hostnames') }}</span>
                    <x-heroicon-m-chevron-down class="h-4 w-4 text-brand-mist transition group-open:rotate-180" />
                </summary>
                <p class="border-t border-brand-ink/10 px-5 py-3 font-mono text-xs text-brand-moss sm:px-6">{{ implode(', ', $trackedHostnames) }}</p>
            </details>
        @endif
    @endif

    @if ($isHybridForCache)
        <section class="border-b border-brand-ink/10 px-5 py-4 sm:px-6">
            <div class="flex flex-wrap items-baseline justify-between gap-2">
                <p class="text-2xs font-semibold uppercase tracking-[0.16em] text-brand-mist">{{ __('Edge cache') }}</p>
                <a
                    href="{{ route('sites.show', ['server' => $server ?? $site->server, 'site' => $site, 'section' => 'delivery']) }}"
                    wire:navigate
                    class="text-xs font-medium text-brand-sage hover:underline"
                >
                    {{ __('Cache controls') }}
                </a>
            </div>
            <dl class="mt-3 grid gap-3 sm:grid-cols-2">
                <div>
                    <dt class="text-2xs font-semibold uppercase tracking-wide text-brand-mist">{{ __('Hit ratio 7d') }}</dt>
                    @if ($cacheHitPercent !== null)
                        <dd class="mt-1 text-xl font-semibold tabular-nums text-brand-ink">{{ number_format($cacheHitPercent, 1) }}%</dd>
                    @else
                        <dd class="mt-1 text-sm text-brand-moss">{{ __('Waiting for traffic') }}</dd>
                    @endif
                </div>
                <div>
                    <dt class="text-2xs font-semibold uppercase tracking-wide text-brand-mist">{{ __('Served from edge 7d') }}</dt>
                    @if ($cacheHitPercent !== null)
                        @php
                            $reqs7d = (int) ($perf['requests_7d'] ?? 0);
                            $hits = (int) round($reqs7d * ($cacheHitPercent / 100));
                        @endphp
                        <dd class="mt-1 text-xl font-semibold tabular-nums text-brand-ink">{{ number_format($hits) }}</dd>
                        <dd class="text-xs text-brand-mist">{{ __('of :total', ['total' => number_format($reqs7d)]) }}</dd>
                    @else
                        <dd class="mt-1 text-xl font-semibold tabular-nums text-brand-ink">—</dd>
                    @endif
                </div>
            </dl>
        </section>
    @endif

    <section class="border-b border-brand-ink/10 px-5 py-4 sm:px-6">
        <p class="text-2xs font-semibold uppercase tracking-[0.16em] text-brand-mist">{{ __('Performance') }}</p>
        <div class="mt-3 grid gap-4 lg:grid-cols-2">
            <div>
                <p class="text-xs font-semibold text-brand-ink">{{ __('Edge response') }}</p>
                @if ($hasWorkerLogs)
                    <dl class="mt-2 space-y-1.5 text-sm">
                        <div class="flex justify-between gap-2">
                            <dt class="text-brand-moss">{{ __('Avg 7d') }}</dt>
                            <dd class="tabular-nums font-medium text-brand-ink">{{ number_format($perf['avg_duration_ms'] ?? 0) }} ms</dd>
                        </div>
                        <div class="flex justify-between gap-2">
                            <dt class="text-brand-moss">{{ __('P95 7d') }}</dt>
                            <dd class="tabular-nums font-medium text-brand-ink">{{ isset($perf['p95_duration_ms']) ? number_format($perf['p95_duration_ms']).' ms' : '—' }}</dd>
                        </div>
                        <div class="flex justify-between gap-2">
                            <dt class="text-brand-moss">{{ __('Cache hit 7d') }}</dt>
                            <dd class="tabular-nums font-medium text-brand-ink">
                                @if (($perf['cache_hit_ratio'] ?? null) !== null)
                                    {{ number_format(($perf['cache_hit_ratio'] ?? 0) * 100, 1) }}%
                                @else
                                    —
                                @endif
                            </dd>
                        </div>
                        <div class="flex justify-between gap-2">
                            <dt class="text-brand-moss">{{ __('Requests 7d') }}</dt>
                            <dd class="tabular-nums font-medium text-brand-ink">{{ number_format($perf['requests_7d'] ?? 0) }}</dd>
                        </div>
                    </dl>
                @else
                    <p class="mt-1 text-sm text-brand-moss">{{ __('No response times in the last 7 days.') }}</p>
                @endif
            </div>
            <div>
                <p class="text-xs font-semibold text-brand-ink">{{ __('Core Web Vitals') }}</p>
                @if ($hasWebVitals)
                    <dl class="mt-2 space-y-1.5 text-sm">
                        <div class="flex justify-between gap-2">
                            <dt class="text-brand-moss">{{ __('LCP p75') }}</dt>
                            <dd class="tabular-nums font-medium text-brand-ink">{{ isset($vitals['lcp_p75_ms']) ? number_format($vitals['lcp_p75_ms']).' ms' : '—' }}</dd>
                        </div>
                        <div class="flex justify-between gap-2">
                            <dt class="text-brand-moss">{{ __('INP p75') }}</dt>
                            <dd class="tabular-nums font-medium text-brand-ink">{{ isset($vitals['inp_p75_ms']) ? number_format($vitals['inp_p75_ms']).' ms' : '—' }}</dd>
                        </div>
                        <div class="flex justify-between gap-2">
                            <dt class="text-brand-moss">{{ __('CLS p75') }}</dt>
                            <dd class="tabular-nums font-medium text-brand-ink">{{ isset($vitals['cls_p75']) ? number_format($vitals['cls_p75'], 3) : '—' }}</dd>
                        </div>
                        <div class="flex justify-between gap-2">
                            <dt class="text-brand-moss">{{ __('Samples 7d') }}</dt>
                            <dd class="tabular-nums font-medium text-brand-ink">{{ number_format($vitals['samples_7d'] ?? 0) }}</dd>
                        </div>
                    </dl>
                @else
                    <p class="mt-1 text-sm text-brand-moss">{{ __('No browser samples in the last 7 days.') }}</p>
                @endif
            </div>
        </div>
    </section>

    @unless (($traffic['byo_cloudflare'] ?? false))
        @include('livewire.sites.partials.edge.live-request-tail')
    @endunless
</div>
