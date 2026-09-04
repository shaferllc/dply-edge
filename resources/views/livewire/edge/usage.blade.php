@php
    $humanBytes = static function (int $b): string {
        if ($b <= 0) {
            return '0 B';
        }
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $i = (int) min(count($units) - 1, floor(log($b, 1024)));

        return sprintf('%.1f %s', $b / (1024 ** $i), $units[$i]);
    };
    $window = $totals['window'] ?? null;
@endphp

<div class="dply-page-shell space-y-4 pt-6">
    <x-breadcrumb-trail :items="[
        ['label' => __('Dashboard'), 'href' => route('dashboard'), 'icon' => 'home'],
        ['label' => __('Apps'), 'href' => route('edge.index'), 'icon' => 'globe-alt'],
        ['label' => __('Usage'), 'icon' => 'chart-bar'],
    ]" />

    <x-profile-shell
        :title="__('Apps usage')"
        :description="__('Cross-site view of requests, bandwidth, storage, and estimated cost for the current calendar month.')"
        icon="heroicon-o-chart-bar"
    >
        <x-slot:actions>
            <x-outline-link href="{{ route('edge.index') }}" wire:navigate size="sm">
                <x-heroicon-o-arrow-left class="h-4 w-4 shrink-0 opacity-90" aria-hidden="true" />
                {{ __('Back to Edge sites') }}
            </x-outline-link>
        </x-slot:actions>

        @if ($edgeEnabled && $totals)
            {{-- Org-wide totals, same tile language as the Edge index summary. --}}
            <x-slot:stats>
                <dl class="grid grid-cols-2 gap-2 sm:grid-cols-3 lg:grid-cols-5">
                    <div class="rounded-xl border border-brand-ink/10 bg-white/80 px-3 py-2">
                        <dt class="flex items-center gap-1.5 text-2xs font-semibold uppercase tracking-wide text-brand-mist">
                            <x-heroicon-o-globe-alt class="h-3.5 w-3.5 shrink-0 text-brand-sage" aria-hidden="true" />
                            <span class="truncate">{{ __('Apps') }}</span>
                        </dt>
                        <dd class="mt-0.5 font-mono text-lg font-semibold tabular-nums leading-none text-brand-ink">{{ number_format($totals['all_sites']) }}</dd>
                        <p class="mt-1 text-2xs text-brand-mist">{{ __(':n billable', ['n' => number_format($totals['sites'])]) }}</p>
                    </div>
                    <div class="rounded-xl border border-brand-ink/10 bg-white/80 px-3 py-2">
                        <dt class="flex items-center gap-1.5 text-2xs font-semibold uppercase tracking-wide text-brand-mist">
                            <x-heroicon-o-arrow-trending-up class="h-3.5 w-3.5 shrink-0 text-brand-sage" aria-hidden="true" />
                            <span class="truncate">{{ __('Requests (MTD)') }}</span>
                        </dt>
                        <dd class="mt-0.5 font-mono text-lg font-semibold tabular-nums leading-none text-brand-ink">{{ number_format($totals['requests']) }}</dd>
                    </div>
                    <div class="rounded-xl border border-brand-ink/10 bg-white/80 px-3 py-2">
                        <dt class="flex items-center gap-1.5 text-2xs font-semibold uppercase tracking-wide text-brand-mist">
                            <x-heroicon-o-cloud-arrow-down class="h-3.5 w-3.5 shrink-0 text-brand-forest" aria-hidden="true" />
                            <span class="truncate">{{ __('Egress (MTD)') }}</span>
                        </dt>
                        <dd class="mt-0.5 font-mono text-lg font-semibold tabular-nums leading-none text-brand-ink">{{ $humanBytes($totals['bytes_egress']) }}</dd>
                    </div>
                    <div class="rounded-xl border border-brand-ink/10 bg-white/80 px-3 py-2">
                        <dt class="flex items-center gap-1.5 text-2xs font-semibold uppercase tracking-wide text-brand-mist">
                            <x-heroicon-o-circle-stack class="h-3.5 w-3.5 shrink-0 text-brand-sage" aria-hidden="true" />
                            <span class="truncate">{{ __('R2 storage') }}</span>
                        </dt>
                        <dd class="mt-0.5 font-mono text-lg font-semibold tabular-nums leading-none text-brand-ink">{{ $humanBytes($totals['r2_storage_bytes']) }}</dd>
                    </div>
                    <div class="rounded-xl border border-brand-ink/10 bg-white/80 px-3 py-2">
                        <dt class="flex items-center gap-1.5 text-2xs font-semibold uppercase tracking-wide text-brand-mist">
                            <x-heroicon-o-banknotes class="h-3.5 w-3.5 shrink-0 text-brand-gold" aria-hidden="true" />
                            <span class="truncate">{{ __('Est. total / mo') }}</span>
                        </dt>
                        <dd class="mt-0.5 font-mono text-lg font-semibold tabular-nums leading-none text-brand-ink">${{ number_format($totals['total_cents'] / 100, 2) }}</dd>
                        <p class="mt-1 text-2xs text-brand-mist">
                            {{ __(':platform platform + :usage usage', [
                                'platform' => '$'.number_format($totals['platform_cents'] / 100, 2),
                                'usage' => '$'.number_format($totals['usage_cents'] / 100, 2),
                            ]) }}
                        </p>
                    </div>
                </dl>
            </x-slot:stats>
        @endif

        @unless ($edgeEnabled)
            <div class="border-b border-brand-ink/10 bg-amber-50/70 px-3 py-3 text-sm text-amber-900 sm:px-4">
                {{ __('Apps are not enabled for this organization.') }}
            </div>
        @else
            @if (empty($rows))
                <div class="flex flex-col items-center justify-center gap-2 px-6 py-12 text-center">
                    <span class="flex h-12 w-12 items-center justify-center rounded-2xl bg-brand-sand/45 text-brand-mist ring-1 ring-brand-ink/10">
                        <x-heroicon-o-chart-bar class="h-6 w-6" aria-hidden="true" />
                    </span>
                    <p class="mt-2 text-sm text-brand-moss">{{ __('No billable Edge sites with usage in this window yet.') }}</p>
                    <a href="{{ route('edge.index') }}" wire:navigate class="text-xs font-semibold text-brand-forest hover:underline dark:text-brand-sage">
                        {{ __('Go to Edge sites →') }}
                    </a>
                </div>
            @else
                <section>
                    <x-workspace-panel-head
                        dense
                        icon="heroicon-o-table-cells"
                        :title="__('Per-site usage')"
                        :count="count($rows)"
                        :note="$window ? __('Window: :start → :end', ['start' => $window['start'] ?? '', 'end' => $window['end'] ?? '']) : null"
                    />
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-brand-ink/10 text-sm">
                            <thead class="bg-white text-left text-2xs font-semibold uppercase tracking-wide text-brand-mist">
                                <tr>
                                    <th class="px-3 py-2 sm:px-4">{{ __('Site') }}</th>
                                    <th class="px-3 py-2 text-right">{{ __('Requests') }}</th>
                                    <th class="px-3 py-2 text-right">{{ __('Egress') }}</th>
                                    <th class="px-3 py-2 text-right">{{ __('R2 storage') }}</th>
                                    <th class="px-3 py-2 text-right">{{ __('Platform') }}</th>
                                    <th class="px-3 py-2 text-right">{{ __('Usage') }}</th>
                                    <th class="px-3 py-2 text-right">{{ __('Est. total') }}</th>
                                    <th class="px-3 py-2 text-right">{{ __('Actions') }}</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-brand-ink/5 text-brand-ink">
                                @foreach ($rows as $row)
                                    @php
                                        $siteName = $row['name'] ?? $row['site_id'] ?? '—';
                                        $hostname = $row['hostname'] ?? null;
                                        $serverId = $row['server_id'] ?? null;
                                        $siteId = $row['site_id'] ?? null;
                                        $billable = (bool) ($row['billable'] ?? false);
                                        $status = (string) ($row['status'] ?? '');
                                        $statusTone = match ($status) {
                                            'edge_active' => 'bg-emerald-100 text-emerald-800',
                                            'edge_failed' => 'bg-rose-100 text-rose-800',
                                            'edge_provisioning' => 'bg-sky-100 text-sky-800',
                                            default => 'bg-brand-sand/60 text-brand-moss',
                                        };
                                    @endphp
                                    <tr @class(['opacity-60' => ! $billable])>
                                        <td class="px-3 py-2.5 sm:px-4">
                                            <div class="flex flex-wrap items-center gap-2">
                                                <p class="truncate font-semibold text-brand-ink">{{ $siteName }}</p>
                                                @if (! empty($row['is_preview']))
                                                    <span class="rounded-full bg-brand-gold/15 px-2 py-0.5 text-2xs font-semibold uppercase tracking-wide text-brand-olive">{{ __('Preview') }}</span>
                                                @elseif (! $billable && $status !== '')
                                                    <span class="rounded-full px-2 py-0.5 text-2xs font-semibold uppercase tracking-wide {{ $statusTone }}">{{ str_replace('_', ' ', $status) }}</span>
                                                @endif
                                            </div>
                                            @if ($hostname)
                                                <p class="mt-0.5 truncate font-mono text-xs text-brand-moss">{{ $hostname }}</p>
                                            @endif
                                        </td>
                                        <td class="px-3 py-2.5 text-right font-mono tabular-nums">{{ number_format($row['requests'] ?? 0) }}</td>
                                        <td class="px-3 py-2.5 text-right font-mono tabular-nums">{{ $humanBytes((int) ($row['bytes_egress'] ?? 0)) }}</td>
                                        <td class="px-3 py-2.5 text-right font-mono tabular-nums">{{ $humanBytes((int) ($row['r2_storage_bytes'] ?? 0)) }}</td>
                                        <td class="px-3 py-2.5 text-right font-mono tabular-nums">${{ number_format(($row['platform_cents'] ?? 0) / 100, 2) }}</td>
                                        <td class="px-3 py-2.5 text-right font-mono tabular-nums">${{ number_format(($row['usage_cents'] ?? 0) / 100, 2) }}</td>
                                        <td class="px-3 py-2.5 text-right font-mono font-semibold tabular-nums">${{ number_format(($row['total_cents'] ?? 0) / 100, 2) }}</td>
                                        <td class="px-3 py-2.5 text-right">
                                            @if ($serverId && $siteId)
                                                <a
                                                    href="{{ route('sites.show', ['server' => $serverId, 'site' => $siteId, 'section' => 'edge-billing']) }}"
                                                    wire:navigate
                                                    class="inline-flex items-center gap-1 text-xs font-semibold text-brand-forest hover:underline dark:text-brand-sage"
                                                >
                                                    {{ __('Site billing →') }}
                                                </a>
                                            @endif
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </section>
            @endif
        @endunless
    </x-profile-shell>
</div>
