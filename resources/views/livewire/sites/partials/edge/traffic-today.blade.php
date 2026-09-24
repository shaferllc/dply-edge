@php
    $today = is_array($access['dataset'] ?? null) ? $access['dataset'] : [];
    $todayAvailable = (bool) ($today['available'] ?? false);
    $todayRequests = (int) ($today['requests'] ?? 0);
    $todayBytes = (int) ($today['bytes_egress'] ?? 0);
    $todayStatus = is_array($today['status'] ?? null) ? $today['status'] : [];
    $statusTotal = (int) (($todayStatus['2xx'] ?? 0) + ($todayStatus['3xx'] ?? 0) + ($todayStatus['4xx'] ?? 0) + ($todayStatus['5xx'] ?? 0));
    $todayPaths = is_array($today['paths'] ?? null) ? $today['paths'] : [];
    $pathMax = max(1, (int) ($todayPaths[0]['requests'] ?? 1));
    if ($todayBytes >= 1024 ** 3) {
        $todayBandwidth = number_format($todayBytes / (1024 ** 3), 2).' GB';
    } elseif ($todayBytes >= 1024 ** 2) {
        $todayBandwidth = number_format($todayBytes / (1024 ** 2), 1).' MB';
    } else {
        $todayBandwidth = number_format($todayBytes / 1024, 0).' KB';
    }
    $statusColors = [
        '2xx' => 'bg-emerald-500',
        '3xx' => 'bg-sky-500',
        '4xx' => 'bg-amber-500',
        '5xx' => 'bg-rose-500',
    ];
@endphp

<section class="border-b border-brand-ink/10 px-5 py-4 sm:px-6">
    <div class="flex flex-wrap items-baseline justify-between gap-2">
        <p class="text-2xs font-semibold uppercase tracking-[0.16em] text-brand-mist">{{ __('Today') }}</p>
        <p class="text-xs text-brand-mist">{{ __('UTC') }}</p>
    </div>
    <p class="mt-1 text-xs text-brand-moss">{{ __('Same-day requests counted at the edge. Daily charts above still update the next day.') }}</p>

    @if (! $todayAvailable)
        <p class="mt-3 text-sm text-brand-moss">{{ __('Same-day totals are not available yet.') }}</p>
    @elseif ($todayRequests < 1)
        <p class="mt-3 text-sm text-brand-moss">{{ __('No requests yet today.') }}</p>
    @else
        <dl class="mt-4 grid grid-cols-2 gap-3">
            <div>
                <dt class="text-2xs font-semibold uppercase tracking-wide text-brand-mist">{{ __('Requests today') }}</dt>
                <dd class="mt-1 text-xl font-semibold tabular-nums text-brand-ink">{{ number_format($todayRequests) }}</dd>
            </div>
            <div>
                <dt class="text-2xs font-semibold uppercase tracking-wide text-brand-mist">{{ __('Bandwidth today') }}</dt>
                <dd class="mt-1 text-xl font-semibold tabular-nums text-brand-ink">{{ $todayBandwidth }}</dd>
            </div>
        </dl>

        @if ($statusTotal > 0)
            <div class="mt-4">
                <p class="text-xs font-semibold text-brand-ink">{{ __('Status mix') }}</p>
                <div class="mt-2 flex h-2 overflow-hidden rounded-full bg-brand-ink/10">
                    @foreach ($statusColors as $bucket => $color)
                        @if ((int) ($todayStatus[$bucket] ?? 0) > 0)
                            <div class="{{ $color }}" style="width: {{ max(1, round(((int) $todayStatus[$bucket] / $statusTotal) * 100)) }}%"></div>
                        @endif
                    @endforeach
                </div>
                <dl class="mt-2 grid grid-cols-2 gap-2 text-xs sm:grid-cols-4">
                    @foreach ($statusColors as $bucket => $color)
                        <div class="flex items-center justify-between gap-2">
                            <dt class="inline-flex items-center gap-1.5 text-brand-moss">
                                <span class="inline-block h-2 w-2 rounded-full {{ $color }}"></span>
                                {{ $bucket }}
                            </dt>
                            <dd class="tabular-nums font-medium text-brand-ink">{{ number_format((int) ($todayStatus[$bucket] ?? 0)) }}</dd>
                        </div>
                    @endforeach
                </dl>
            </div>
        @endif

        @if ($todayPaths !== [])
            <div class="mt-4">
                <p class="text-xs font-semibold text-brand-ink">{{ __('Top paths') }}</p>
                <ul class="mt-2 space-y-2">
                    @foreach ($todayPaths as $path)
                        <li>
                            <div class="flex items-baseline justify-between gap-3">
                                <span class="min-w-0 truncate font-mono text-xs text-brand-ink" title="{{ $path['path'] }}">{{ $path['path'] }}</span>
                                <span class="shrink-0 tabular-nums text-xs text-brand-moss">{{ number_format((int) $path['requests']) }}</span>
                            </div>
                            <div class="mt-1 h-1.5 overflow-hidden rounded-full bg-brand-ink/10">
                                <div class="h-full rounded-full bg-brand-sage/80" style="width: {{ max(4, round(((int) $path['requests'] / $pathMax) * 100)) }}%"></div>
                            </div>
                        </li>
                    @endforeach
                </ul>
            </div>
        @endif
    @endif
</section>
