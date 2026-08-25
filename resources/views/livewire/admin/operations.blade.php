@php
    $kv = 'flex flex-wrap items-baseline justify-between gap-2 border-b border-brand-ink/5 py-2 last:border-0';
    $pillOk = 'inline-flex items-center rounded-full bg-emerald-50 px-2 py-0.5 text-xs font-semibold text-emerald-800';
    $pillBad = 'inline-flex items-center rounded-full bg-red-50 px-2 py-0.5 text-xs font-semibold text-red-800';
    $pillNeutral = 'inline-flex items-center rounded-full bg-brand-ink/[0.06] px-2 py-0.5 text-xs font-semibold text-brand-moss';
    $actionButton = 'inline-flex items-center gap-1.5 rounded-lg border border-brand-ink/15 bg-white px-3 py-1.5 text-xs font-medium text-brand-ink shadow-sm transition-colors hover:bg-brand-sand/40';
@endphp

<div>
    <x-breadcrumb-trail :items="[
        ['label' => __('Dashboard'), 'href' => route('dashboard'), 'icon' => 'home'],
        ['label' => __('Platform admin'), 'href' => route('admin.overview'), 'icon' => 'shield-check'],
        ['label' => __('Operations'), 'icon' => 'wrench-screwdriver'],
    ]" />

    <x-profile-shell
        class="mt-4"
        :title="__('Operations')"
        :description="__('Runtime probes, queue health, log tail, exports, and cache maintenance. Horizon and Pulse require separate worker processes.')"
        icon="heroicon-o-wrench-screwdriver"
    >
        <x-slot:actions>
            <x-outline-link href="{{ $horizonUrl }}" size="sm">
                <x-heroicon-o-queue-list class="h-4 w-4 shrink-0 text-brand-moss" aria-hidden="true" />
                {{ __('Horizon') }}
            </x-outline-link>
            <x-outline-link href="{{ $pulseUrl }}" size="sm">
                <x-heroicon-o-chart-bar class="h-4 w-4 shrink-0 text-brand-moss" aria-hidden="true" />
                {{ __('Laravel Pulse') }}
            </x-outline-link>
        </x-slot:actions>

        <x-slot:stats>
            <dl class="grid grid-cols-2 gap-2">
                <div class="rounded-xl border border-brand-ink/10 bg-white/80 px-3 py-2">
                    <dt class="flex items-center gap-1.5 text-2xs font-semibold uppercase tracking-wide text-brand-mist">
                        <x-heroicon-o-queue-list class="h-3.5 w-3.5 shrink-0 text-brand-sage" aria-hidden="true" />
                        <span class="truncate">{{ __('Queue pending') }}</span>
                    </dt>
                    <dd class="mt-0.5 font-mono text-lg font-semibold tabular-nums leading-none text-brand-ink">{{ number_format($counts['pending_jobs']) }}</dd>
                </div>
                <div class="rounded-xl border border-brand-ink/10 bg-white/80 px-3 py-2">
                    <dt class="flex items-center gap-1.5 text-2xs font-semibold uppercase tracking-wide text-brand-mist">
                        <x-heroicon-o-exclamation-triangle class="h-3.5 w-3.5 shrink-0 {{ $counts['failed_jobs'] > 0 ? 'text-rose-600' : 'text-brand-mist' }}" aria-hidden="true" />
                        <span class="truncate">{{ __('Failed jobs') }}</span>
                    </dt>
                    <dd class="mt-0.5 font-mono text-lg font-semibold tabular-nums leading-none text-brand-ink">{{ number_format($counts['failed_jobs']) }}</dd>
                </div>
            </dl>
        </x-slot:stats>

        <section class="border-b border-brand-ink/10">
            <x-workspace-panel-head
                dense
                icon="heroicon-o-cpu-chip"
                :title="__('Runtime & optimization')"
                :note="__('Interpreter, framework, and cached-artifact state.')"
            />
            <dl class="px-3 py-1 text-sm sm:px-4">
                <div class="{{ $kv }}"><dt class="text-brand-moss">{{ __('PHP') }}</dt><dd class="font-mono text-xs text-brand-ink">{{ $system['php'] }}</dd></div>
                <div class="{{ $kv }}"><dt class="text-brand-moss">{{ __('Laravel') }}</dt><dd class="font-mono text-xs text-brand-ink">{{ $system['laravel'] }}</dd></div>
                <div class="{{ $kv }}"><dt class="text-brand-moss">{{ __('Debug') }}</dt><dd><span class="{{ $system['debug'] ? $pillBad : $pillOk }}">{{ $system['debug'] ? __('On') : __('Off') }}</span></dd></div>
                <div class="{{ $kv }}"><dt class="text-brand-moss">{{ __('Config cached') }}</dt><dd><span class="{{ $system['config_cached'] ? $pillOk : $pillNeutral }}">{{ $system['config_cached'] ? __('Yes') : __('No') }}</span></dd></div>
                <div class="{{ $kv }}"><dt class="text-brand-moss">{{ __('Routes cached') }}</dt><dd><span class="{{ $system['routes_cached'] ? $pillOk : $pillNeutral }}">{{ $system['routes_cached'] ? __('Yes') : __('No') }}</span></dd></div>
            </dl>
        </section>

        <section class="border-b border-brand-ink/10">
            <x-workspace-panel-head
                dense
                icon="heroicon-o-signal"
                :title="__('Connectivity & drivers')"
                :note="__('Backing services this node can currently reach.')"
            />
            <dl class="px-3 py-1 text-sm sm:px-4">
                <div class="{{ $kv }}"><dt class="text-brand-moss">{{ __('Database') }}</dt><dd><span class="{{ $system['db_ok'] ? $pillOk : $pillBad }}">{{ $system['db_ok'] ? __('Reachable') : __('Unreachable') }}</span></dd></div>
                <div class="{{ $kv }}">
                    <dt class="text-brand-moss">{{ __('Redis') }}</dt>
                    <dd>
                        @if ($system['redis_ok'] === true)<span class="{{ $pillOk }}">{{ __('OK') }}</span>
                        @elseif ($system['redis_ok'] === false)<span class="{{ $pillBad }}">{{ __('Failed') }}</span>
                        @else<span class="{{ $pillNeutral }}">{{ __('Unknown') }}</span>@endif
                    </dd>
                </div>
                <div class="{{ $kv }}"><dt class="text-brand-moss">{{ __('Queue') }}</dt><dd class="font-mono text-xs text-brand-ink">{{ $system['queue_connection'] }}</dd></div>
                <div class="{{ $kv }}"><dt class="text-brand-moss">{{ __('Cache') }}</dt><dd class="font-mono text-xs text-brand-ink">{{ $system['cache_store'] }}</dd></div>
            </dl>
            @if ($system['disk'])
                <p class="px-3 pb-3 text-xs text-brand-moss sm:px-4">{{ __(':free free of :total (:pct% used)', ['free' => $system['disk']['free'], 'total' => $system['disk']['total'], 'pct' => $system['disk']['used_percent']]) }}</p>
            @endif
        </section>

        <section class="border-b border-brand-ink/10">
            <x-workspace-panel-head
                dense
                icon="heroicon-o-arrow-down-tray"
                :title="__('Data exports')"
                :note="__('CSV snapshots of platform tables.')"
            />
            <div class="flex flex-wrap gap-2 px-3 py-3 sm:px-4">
                <button type="button" wire:click="downloadAuditCsv" wire:loading.attr="disabled" class="{{ $actionButton }}">
                    <x-heroicon-o-arrow-down-tray class="h-4 w-4 shrink-0 text-brand-moss" aria-hidden="true" />
                    {{ __('Export audit log') }}
                </button>
                <button type="button" wire:click="downloadUsersCsv" wire:loading.attr="disabled" class="{{ $actionButton }}">
                    <x-heroicon-o-user-group class="h-4 w-4 shrink-0 text-brand-moss" aria-hidden="true" />
                    {{ __('Export users') }}
                </button>
            </div>
        </section>

        <section class="border-b border-brand-ink/10">
            <x-workspace-panel-head
                dense
                tone="amber"
                icon="heroicon-o-trash"
                :title="__('Cache & queue maintenance')"
                :note="__('Destructive on a live node — output is captured below.')"
            />
            <div class="px-3 py-3 sm:px-4">
                <div class="flex flex-wrap gap-2">
                    <button type="button" wire:click="clearApplicationCache" wire:loading.attr="disabled" class="inline-flex items-center gap-1.5 rounded-lg border border-amber-200 bg-amber-50 px-3 py-1.5 text-xs font-medium text-amber-950 transition-colors hover:bg-amber-100">{{ __('Clear application cache') }}</button>
                    <button type="button" wire:click="clearOptimizedCaches" wire:loading.attr="disabled" class="{{ $actionButton }}">{{ __('Optimize clear') }}</button>
                    <button type="button" wire:click="retryFailedJobs" wire:loading.attr="disabled" class="{{ $actionButton }}">
                        {{ __('Retry failed jobs') }}@if ($failedJobsCount > 0) <span class="ml-1 rounded-full bg-rose-100 px-1.5 py-0.5 text-2xs font-semibold text-rose-700">{{ $failedJobsCount }}</span>@endif
                    </button>
                    <button type="button" wire:click="flushFailedJobs" wire:loading.attr="disabled" wire:confirm="{{ __('Permanently delete all :n failed job(s)?', ['n' => $failedJobsCount]) }}" class="inline-flex items-center gap-1.5 rounded-lg border border-rose-200 bg-white px-3 py-1.5 text-xs font-medium text-rose-700 shadow-sm transition-colors hover:bg-rose-50">{{ __('Flush failed jobs') }}</button>
                </div>

                {{-- Console — captured output of the maintenance commands run above. --}}
                <div class="mt-4">
                    <div class="mb-1.5 flex items-center justify-between">
                        <p class="text-2xs font-semibold uppercase tracking-wide text-brand-mist">{{ __('Console') }}</p>
                        @if ($consoleOutput !== '')
                            <button type="button" wire:click="clearConsole" class="text-xs font-medium text-brand-moss hover:text-brand-ink">{{ __('Clear') }}</button>
                        @endif
                    </div>
                    <pre class="max-h-80 overflow-auto whitespace-pre-wrap break-all rounded-xl bg-brand-ink/95 p-3 font-mono text-xs leading-relaxed text-emerald-100">{{ $consoleOutput !== '' ? $consoleOutput : __('Run a maintenance action above — its output appears here.') }}</pre>
                </div>
            </div>
        </section>

        @if ($recentFailedJobs->isNotEmpty())
            <section class="border-b border-brand-ink/10">
                <x-workspace-panel-head
                    dense
                    tone="danger"
                    icon="heroicon-o-exclamation-triangle"
                    :title="__('Recent failed queue jobs')"
                    :count="$recentFailedJobs->count()"
                />
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-brand-ink/10 text-left text-xs">
                        <thead class="bg-white text-brand-mist">
                            <tr>
                                <th class="px-3 py-2 font-semibold uppercase tracking-wide sm:px-4">{{ __('Failed at') }}</th>
                                <th class="px-3 py-2 font-semibold uppercase tracking-wide">{{ __('Connection') }}</th>
                                <th class="px-3 py-2 font-semibold uppercase tracking-wide">{{ __('Queue') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-brand-ink/5">
                            @foreach ($recentFailedJobs as $fj)
                                <tr>
                                    <td class="px-3 py-2 text-brand-moss sm:px-4">{{ \Illuminate\Support\Carbon::parse($fj->failed_at)->timezone(config('app.timezone'))->format('M j H:i') }}</td>
                                    <td class="px-3 py-2 font-mono text-brand-ink">{{ $fj->connection }}</td>
                                    <td class="px-3 py-2 font-mono text-brand-ink">{{ $fj->queue }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </section>
        @endif

        <section>
            <x-workspace-panel-head
                dense
                icon="heroicon-o-document-text"
                :title="__('Application log tail')"
                :note="__('Last lines of the current log file.')"
            />
            <div class="px-3 py-3 sm:px-4">
                @if ($logTail)
                    <pre class="max-h-[18rem] overflow-auto rounded-xl border border-brand-ink/10 bg-brand-ink/95 p-4 font-mono text-xs text-zinc-100">{{ $logTail }}</pre>
                @else
                    <p class="rounded-xl border border-dashed border-brand-ink/15 px-4 py-6 text-sm text-brand-moss">{{ __('Log file not readable yet.') }}</p>
                @endif
            </div>
        </section>
    </x-profile-shell>
</div>
