{{-- Daily requests + egress bars for one site. Callers: site Billing tab (sites/partials/edge/billing), inside "Daily activity". --}}
@php
    $maxRequests = max(1, collect($billingDaily)->max('requests') ?? 1);
    $maxEgress = max(1, collect($billingDaily)->max('bytes_egress') ?? 1);
    $billingLastIdx = count($billingDaily) - 1;
    $billingMidIdx = (int) floor($billingLastIdx / 2);
    $maxEgressMb = ($maxEgress / (1024 ** 2));
@endphp
    <div class="grid gap-6 lg:grid-cols-2">
        <section>
            <div class="flex items-baseline justify-between gap-2">
                <p class="text-2xs font-semibold uppercase tracking-[0.16em] text-brand-mist">{{ __('Daily requests') }}</p>
                <span class="font-mono text-2xs text-brand-mist">{{ __('max :n', ['n' => number_format((int) $maxRequests)]) }}</span>
            </div>
            <div class="mt-3 flex h-20 items-end gap-0.5">
                @foreach ($billingDaily as $day)
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
                <span>{{ $billingDaily[0]['label'] ?? '' }}</span>
                @if ($billingMidIdx > 0 && $billingMidIdx < $billingLastIdx)
                    <span>{{ $billingDaily[$billingMidIdx]['label'] ?? '' }}</span>
                @endif
                @if ($billingLastIdx > 0)
                    <span>{{ $billingDaily[$billingLastIdx]['label'] ?? '' }}</span>
                @endif
            </div>
        </section>

        <section>
            <div class="flex items-baseline justify-between gap-2">
                <p class="text-2xs font-semibold uppercase tracking-[0.16em] text-brand-mist">{{ __('Daily egress') }}</p>
                <span class="font-mono text-2xs text-brand-mist">{{ __('max :n MB', ['n' => number_format($maxEgressMb, 1)]) }}</span>
            </div>
            <div class="mt-3 flex h-20 items-end gap-0.5">
                @foreach ($billingDaily as $day)
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
                <span>{{ $billingDaily[0]['label'] ?? '' }}</span>
                @if ($billingMidIdx > 0 && $billingMidIdx < $billingLastIdx)
                    <span>{{ $billingDaily[$billingMidIdx]['label'] ?? '' }}</span>
                @endif
                @if ($billingLastIdx > 0)
                    <span>{{ $billingDaily[$billingLastIdx]['label'] ?? '' }}</span>
                @endif
            </div>
        </section>
    </div>
