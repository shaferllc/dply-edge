@php
    $trafficSectionUrl = route('sites.show', ['server' => $server ?? $site->server, 'site' => $site, 'section' => 'traffic']);
    $logsSectionUrl = route('sites.show', ['server' => $server ?? $site->server, 'site' => $site, 'section' => 'logs']);
    $billingSectionUrl = route('sites.show', ['server' => $server ?? $site->server, 'site' => $site, 'section' => 'billing']);
    $active = $activeObservabilitySection ?? null;
@endphp

<section class="rounded-xl border border-brand-ink/10 bg-brand-cream/20 dark:bg-brand-ink/20">
    <div class="border-b border-brand-ink/10 px-5 py-3 sm:px-6">
        <p class="text-xs font-semibold uppercase tracking-wider text-brand-moss">{{ __('Edge observability') }}</p>
    </div>
    <div class="grid gap-3 p-4 sm:grid-cols-3 sm:p-5">
        <a
            href="{{ $trafficSectionUrl }}"
            wire:navigate
            @class([
                'rounded-lg border px-4 py-3 transition-colors',
                'border-brand-sage/50 bg-white/70 dark:bg-brand-ink/40' => $active === 'traffic',
                'border-brand-ink/10 bg-white/40 hover:border-brand-sage/40 dark:bg-brand-ink/20' => $active !== 'traffic',
            ])
        >
            <div class="flex items-center gap-2">
                <x-heroicon-o-signal @class(['h-4 w-4', 'text-brand-forest dark:text-brand-sage' => $active === 'traffic', 'text-brand-moss' => $active !== 'traffic']) />
                <span class="text-sm font-medium text-brand-ink">{{ __('Traffic & analytics') }}</span>
            </div>
            <p class="mt-1 text-xs text-brand-moss">{{ __('CDN requests, bandwidth, daily trends') }}</p>
        </a>
        <a
            href="{{ $logsSectionUrl }}"
            wire:navigate
            @class([
                'rounded-lg border px-4 py-3 transition-colors',
                'border-brand-sage/50 bg-white/70 dark:bg-brand-ink/40' => $active === 'logs',
                'border-brand-ink/10 bg-white/40 hover:border-brand-sage/40 dark:bg-brand-ink/20' => $active !== 'logs',
            ])
        >
            <div class="flex items-center gap-2">
                <x-heroicon-o-clipboard-document-list @class(['h-4 w-4', 'text-brand-forest dark:text-brand-sage' => $active === 'logs', 'text-brand-moss' => $active !== 'logs']) />
                <span class="text-sm font-medium text-brand-ink">{{ __('Build & deploy logs') }}</span>
            </div>
            <p class="mt-1 text-xs text-brand-moss">{{ __('Build output and deployment history') }}</p>
        </a>
        @if (! ($edgeIsPreviewChild ?? false))
            <a
                href="{{ $billingSectionUrl }}"
                wire:navigate
                @class([
                    'rounded-lg border px-4 py-3 transition-colors',
                    'border-brand-sage/50 bg-white/70 dark:bg-brand-ink/40' => $active === 'billing',
                    'border-brand-ink/10 bg-white/40 hover:border-brand-sage/40 dark:bg-brand-ink/20' => $active !== 'billing',
                ])
            >
                <div class="flex items-center gap-2">
                    <x-heroicon-o-chart-bar @class(['h-4 w-4', 'text-brand-forest dark:text-brand-sage' => $active === 'billing', 'text-brand-moss' => $active !== 'billing']) />
                    <span class="text-sm font-medium text-brand-ink">{{ __('Billing & usage') }}</span>
                </div>
                <p class="mt-1 text-xs text-brand-moss">{{ __('Usage this month, plus extra-site and SSR fees') }}</p>
            </a>
        @endif
    </div>
</section>
