@php
    $traffic = $edgeSiteTraffic ?? null;
    $showTraffic = ! ($edgeIsPreviewChild ?? false);
@endphp

@if ($showTraffic)
    <section class="dply-card overflow-hidden">
        <div class="flex flex-wrap items-start gap-3 border-b border-brand-ink/10 bg-brand-sand/20 px-6 py-5 sm:px-7">
            <x-icon-badge>
                <x-heroicon-o-chart-bar class="h-5 w-5" aria-hidden="true" />
            </x-icon-badge>
            <div class="min-w-0 flex-1">
                <p class="text-xs font-semibold uppercase tracking-[0.16em] text-brand-sage">{{ __('Traffic') }}</p>
                <h3 class="mt-0.5 text-base font-semibold text-brand-ink">{{ __('Traffic & analytics') }}</h3>
                <p class="mt-1 max-w-2xl text-sm leading-relaxed text-brand-moss">
                    @if ($traffic !== null && ($traffic['byo_cloudflare'] ?? false))
                        {{ __('View CDN analytics in your provider dashboard.') }}
                    @elseif ($traffic !== null && (int) ($traffic['requests'] ?? 0) > 0)
                        {{ __(':requests requests MTD · :bandwidth GB bandwidth', [
                            'requests' => number_format($traffic['requests'] ?? 0),
                            'bandwidth' => number_format(($traffic['bytes_egress'] ?? 0) / (1024 ** 3), 2),
                        ]) }}
                    @else
                        {{ __('Daily CDN request and bandwidth stats — not HTTP access logs.') }}
                    @endif
                </p>
            </div>
            <a
                href="{{ route('sites.show', ['server' => $server ?? $site->server, 'site' => $site, 'section' => 'traffic']) }}"
                wire:navigate
                class="inline-flex shrink-0 items-center gap-1 text-sm font-medium text-brand-forest hover:underline dark:text-brand-sage"
            >
                {{ __('View analytics') }}
                <x-heroicon-o-arrow-right class="h-4 w-4" />
            </a>
        </div>
    </section>
@endif
