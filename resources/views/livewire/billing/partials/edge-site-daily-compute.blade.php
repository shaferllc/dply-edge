{{-- Daily container compute cost for one site. Callers: site Billing tab, org billing edge-site-billing-card. --}}
@php
    $computeDaily = $billing['daily_compute'] ?? [];
    $maxComputeCents = max(0.0001, collect($computeDaily)->max('cents') ?? 0);
    $computeLastIdx = count($computeDaily) - 1;
@endphp

@if ($computeDaily !== [])
    <div class="flex items-baseline justify-between gap-2">
        <p class="text-2xs font-semibold uppercase tracking-[0.16em] text-brand-mist">{{ __('Daily compute') }}</p>
        <span class="font-mono text-2xs text-brand-mist">{{ __('max $:n', ['n' => number_format($maxComputeCents / 100, 2)]) }}</span>
    </div>
    <div class="mt-3 flex h-20 items-end gap-0.5">
        @foreach ($computeDaily as $day)
            <div class="group relative flex h-full min-w-0 flex-1 cursor-help items-end">
                <div
                    class="w-full rounded-t bg-amber-500/70 transition-colors group-hover:bg-amber-600"
                    style="height: {{ max(4, round(($day['cents'] / $maxComputeCents) * 100)) }}%"
                ></div>
                <div class="pointer-events-none absolute bottom-full left-1/2 z-20 mb-1 hidden -translate-x-1/2 whitespace-nowrap rounded bg-brand-ink px-2 py-1 text-xs font-medium text-white shadow-lg group-hover:block">
                    <span class="font-semibold">{{ $day['label'] }}</span>
                    · ${{ number_format($day['cents'] / 100, 2) }}
                    · {{ __(':c vCPU-h · :m GiB-h', ['c' => number_format($day['cpu_hours'], 1), 'm' => number_format($day['memory_gib_hours'], 1)]) }}
                </div>
            </div>
        @endforeach
    </div>
    <div class="mt-2 flex justify-between text-2xs text-brand-mist">
        <span>{{ $computeDaily[0]['label'] }}</span>
        @if ($computeLastIdx > 0)
            <span>{{ $computeDaily[$computeLastIdx]['label'] }}</span>
        @endif
    </div>
@endif
