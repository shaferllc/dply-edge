@props(['site', 'compact' => false])

@php
    $maxRequests = max(1, collect($site['daily'] ?? [])->max('requests') ?? 1);
@endphp

<article class="rounded-xl border border-brand-ink/10 bg-white/40 overflow-hidden">
    <div class="flex flex-wrap items-start justify-between gap-3 border-b border-brand-ink/10 px-3 py-2.5 sm:px-4">
        <div class="min-w-0">
            <h3 class="font-semibold text-brand-ink truncate">{{ $site['site_name'] }}</h3>
            @if (! empty($site['hostname']))
                <p class="mt-0.5 text-xs font-mono text-brand-moss truncate">{{ $site['hostname'] }}</p>
            @endif
        </div>
        <div class="text-right shrink-0">
            <p class="text-lg font-bold tabular-nums text-brand-ink">${{ number_format(($site['total_cents'] ?? 0) / 100, 2) }}</p>
            <p class="text-xs text-brand-moss">{{ __('this period') }}</p>
        </div>
    </div>

    <div class="grid gap-2.5 px-3 py-2.5 sm:grid-cols-2 sm:px-4">
        <dl class="space-y-2 text-sm">
            <div class="flex justify-between gap-2">
                <dt class="text-brand-moss">{{ __('Requests (MTD)') }}</dt>
                <dd class="tabular-nums text-brand-ink">{{ number_format($site['requests'] ?? 0) }}</dd>
            </div>
            <div class="flex justify-between gap-2">
                <dt class="text-brand-moss">{{ __('Egress (MTD)') }}</dt>
                <dd class="tabular-nums text-brand-ink">{{ number_format(($site['bytes_egress'] ?? 0) / (1024 ** 3), 2) }} GB</dd>
            </div>
        </dl>

        @if (! $compact && ($site['daily'] ?? []) !== [])
            <div>
                <p class="text-xs font-semibold uppercase tracking-wider text-brand-moss">{{ __('Daily requests') }}</p>
                <div class="mt-2 flex items-end gap-0.5 h-16" aria-hidden="true">
                    @foreach ($site['daily'] as $day)
                        <div class="flex-1 min-w-0 group relative">
                            <div
                                class="w-full rounded-t bg-brand-sage/70 hover:bg-brand-forest/80"
                                style="height: {{ max(3, round(($day['requests'] / $maxRequests) * 100)) }}%"
                            ></div>
                        </div>
                    @endforeach
                </div>
            </div>
        @elseif (! ($site['has_snapshots'] ?? false))
            <p class="text-sm text-brand-moss/80">{{ __('No usage snapshots yet this month.') }}</p>
        @endif
    </div>

    @if (! $compact && ($site['daily_compute'] ?? []) !== [])
        <div class="border-t border-brand-ink/10 px-3 py-2.5 sm:px-4">
            @include('livewire.billing.partials.edge-site-daily-compute', ['billing' => $site])
        </div>
    @endif

    <div class="border-t border-brand-ink/10 px-3 sm:px-4">
        @include('livewire.billing.partials.edge-site-usage-lines', ['billing' => $site])
    </div>

    @if (! empty($site['workspace_url']))
        <div class="border-t border-brand-ink/10 px-3 py-2 sm:px-4">
            <a href="{{ $site['workspace_url'] }}" wire:navigate class="inline-flex items-center gap-1 text-xs font-medium text-brand-forest hover:underline dark:text-brand-sage">
                {{ __('Open site workspace') }}
                <x-heroicon-o-arrow-right class="h-4 w-4" />
            </a>
        </div>
    @endif
</article>
