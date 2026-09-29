@php
    use App\Models\EdgeAccessLog;

    $tailSiteId = (string) $site->id;
    $pollUrl = route('sites.edge.logs.live', ['server' => $server ?? $site->server, 'site' => $site]);

    $seedRows = array_key_exists('liveSeed', get_defined_vars())
        ? (is_array($liveSeed) ? $liveSeed : [])
        : EdgeAccessLog::query()
            ->where('site_id', $site->id)
            ->orderByDesc('occurred_at')
            ->limit(50)
            ->get()
            ->map(fn (EdgeAccessLog $row) => [
                'occurred_at' => $row->occurred_at?->toIso8601String(),
                'deployment_id' => $row->edge_deployment_id,
                'hostname' => $row->hostname,
                'method' => $row->method,
                'path' => $row->path,
                'status' => $row->status_code,
                'duration_ms' => $row->duration_ms,
                'bytes_egress' => $row->bytes_egress,
                'cache_status' => $row->cache_status,
                'country' => $row->country,
                'referrer' => $row->referrer,
                'user_agent' => $row->user_agent,
            ])
            ->values()
            ->all();
@endphp

{{-- wire:ignore: Alpine owns this DOM (x-for rows). Livewire morph from
     build-log load/poll must not touch it — otherwise sibling build-log
     details get scrambled and disappear. --}}
<section
    wire:ignore
    x-data="edgeLiveTail({{ Js::from([
        'siteId' => $tailSiteId,
        'max' => 200,
        'pollUrl' => $pollUrl,
        'seed' => $seedRows,
    ]) }})"
    x-init="connect()"
    x-on:beforeunload.window="disconnect()"
>
    <div class="flex flex-wrap items-center justify-between gap-2 border-b border-brand-ink/10 px-5 py-3 sm:px-6">
        <div class="min-w-0">
            <p class="inline-flex items-center gap-2 text-2xs font-semibold uppercase tracking-[0.16em] text-brand-mist">
                <span class="relative inline-flex h-2 w-2">
                    <span class="absolute inline-flex h-full w-full rounded-full bg-emerald-400 opacity-75 motion-safe:animate-ping"
                          x-show="status === 'connected'"></span>
                    <span class="relative inline-flex h-2 w-2 rounded-full"
                          :class="{ 'bg-emerald-500': status === 'connected', 'bg-amber-400': status === 'connecting', 'bg-rose-500': status === 'disconnected' }"></span>
                </span>
                {{ __('Live requests') }}
            </p>
            <p class="mt-0.5 text-xs text-brand-moss">
                <span x-show="status === 'connected'">{{ __('Reading requests from edge analytics.') }}</span>
                <span x-show="status === 'connecting'" x-cloak>{{ __('Connecting…') }}</span>
                <span x-show="status === 'disconnected'" x-cloak>{{ __('Disconnected — refresh to reconnect.') }}</span>
            </p>
        </div>
        <div class="flex flex-wrap items-center gap-1.5">
            <select x-model="methodFilter"
                    class="dply-input !mt-0 w-auto cursor-pointer appearance-none rounded-md border border-brand-ink/15 bg-white py-1 pl-2 pr-8 font-mono text-xs text-brand-ink shadow-none dark:border-brand-mist/20 dark:bg-zinc-900">
                <option value="">{{ __('Method') }}</option>
                <option value="GET">GET</option>
                <option value="POST">POST</option>
                <option value="PUT">PUT</option>
                <option value="PATCH">PATCH</option>
                <option value="DELETE">DELETE</option>
                <option value="HEAD">HEAD</option>
                <option value="OPTIONS">OPTIONS</option>
            </select>
            <select x-model="statusFilter"
                    class="dply-input !mt-0 w-auto cursor-pointer appearance-none rounded-md border border-brand-ink/15 bg-white py-1 pl-2 pr-8 font-mono text-xs text-brand-ink shadow-none dark:border-brand-mist/20 dark:bg-zinc-900">
                <option value="">{{ __('Status') }}</option>
                <option value="2xx">2xx</option>
                <option value="3xx">3xx</option>
                <option value="4xx">4xx</option>
                <option value="5xx">5xx</option>
            </select>
            <input type="text"
                   x-model="filter"
                   placeholder="{{ __('Path…') }}"
                   class="w-28 rounded-md border border-brand-ink/15 bg-white px-2 py-1 font-mono text-xs text-brand-ink dark:border-brand-mist/20 dark:bg-zinc-900" />
            <button type="button"
                    x-on:click="paused = ! paused"
                    class="rounded-md border border-brand-ink/15 px-2 py-1 text-xs font-semibold transition"
                    :class="paused ? 'bg-amber-100 text-amber-900' : 'bg-white text-brand-moss hover:bg-brand-sand/40 dark:bg-zinc-900'">
                <span x-show="! paused">{{ __('Pause') }}</span>
                <span x-show="paused" x-cloak>{{ __('Resume') }}</span>
            </button>
            <button type="button"
                    x-on:click="rows = []; lastTickAt = null; seenKeys.clear()"
                    class="rounded-md border border-brand-ink/15 bg-white px-2 py-1 text-xs font-semibold text-brand-moss hover:bg-brand-sand/40 dark:bg-zinc-900">
                {{ __('Clear') }}
            </button>
            <a href="{{ route('sites.edge.logs.csv', ['server' => $server ?? $site->server, 'site' => $site]) }}"
               class="inline-flex items-center gap-1 rounded-md border border-brand-ink/15 bg-white px-2 py-1 text-xs font-semibold text-brand-moss hover:bg-brand-sand/40 dark:bg-zinc-900"
               title="{{ __('Download recent access logs as CSV') }}">
                <x-heroicon-o-arrow-down-tray class="h-3.5 w-3.5" aria-hidden="true" />
                {{ __('CSV') }}
            </a>
            <span class="font-mono text-2xs text-brand-mist" x-text="rows.length + ' / {{ 200 }}'"></span>
        </div>
    </div>

    <div class="overflow-x-auto" style="max-height: 22rem;">
        <table class="min-w-full divide-y divide-brand-ink/8 text-sm">
            <thead class="sticky top-0 z-10 bg-brand-sand/40 text-left text-2xs font-semibold uppercase tracking-[0.14em] text-brand-mist backdrop-blur dark:bg-zinc-900/80">
                <tr>
                    <th class="px-4 py-2 sm:px-5">{{ __('Time') }}</th>
                    <th class="px-2 py-2">{{ __('Method') }}</th>
                    <th class="px-2 py-2 text-right">{{ __('Status') }}</th>
                    <th class="px-2 py-2 text-right">{{ __('ms') }}</th>
                    <th class="px-2 py-2">{{ __('Cache') }}</th>
                    <th class="px-2 py-2">{{ __('Country') }}</th>
                    <th class="px-4 py-2 sm:px-5">{{ __('Path') }}</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-brand-ink/8 text-brand-ink">
                <template x-if="filteredRows.length === 0">
                    <tr>
                        <td colspan="7" class="px-5 py-8 text-center text-xs text-brand-moss">
                            <span x-show="status === 'connected' && rows.length === 0">
                                {{ __('No requests yet today. Visit the live site and they will show up here.') }}
                            </span>
                            <span x-show="status === 'connecting'" x-cloak>{{ __('Connecting…') }}</span>
                            <span x-show="status === 'disconnected'" x-cloak>{{ __('No live stream.') }}</span>
                            <span x-show="rows.length > 0 && filteredRows.length === 0" x-cloak>{{ __('No rows match your filter.') }}</span>
                        </td>
                    </tr>
                </template>
                <template x-for="row in filteredRows" :key="row._id">
                    <tr :class="row._isNew ? 'bg-emerald-50/40 transition-colors duration-700 dark:bg-raw-emerald-950/20' : ''">
                        <td class="px-4 py-1.5 font-mono text-xs text-brand-moss sm:px-5" x-text="row._timeLabel"></td>
                        <td class="px-2 py-1.5 font-mono text-xs font-semibold" x-text="row.method"></td>
                        <td class="px-2 py-1.5 text-right font-mono text-xs font-semibold"
                            :class="row.status >= 500 ? 'text-rose-700 dark:text-rose-400' : (row.status >= 400 ? 'text-amber-700 dark:text-amber-400' : 'text-emerald-700 dark:text-emerald-400')"
                            x-text="row.status || '—'"></td>
                        <td class="px-2 py-1.5 text-right font-mono text-xs text-brand-moss" x-text="row.duration_ms"></td>
                        <td class="px-2 py-1.5 font-mono text-2xs uppercase text-brand-moss" x-text="row.cache_status || '—'"></td>
                        <td class="px-2 py-1.5 font-mono text-xs text-brand-moss" x-text="row.country || '—'"></td>
                        <td class="max-w-[20rem] truncate px-4 py-1.5 font-mono text-xs sm:px-5" :title="row.path" x-text="row.path"></td>
                    </tr>
                </template>
            </tbody>
        </table>
    </div>
</section>
