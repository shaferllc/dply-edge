<div>
    <section class="px-5 py-5 sm:px-6">
        <p class="text-xs text-brand-moss">
            @if ($isContainer)
                {{ __('Pick a size, cache, and database. A size change applies on the next deploy.') }}
            @else
                {{ __('Attach a database or cache when the app needs one.') }}
            @endif
        </p>

        <div class="mt-4 overflow-x-auto">
            <div @class([
                'grid items-stretch gap-x-2 gap-y-2',
                'min-w-4xl' => $cacheMode !== 'off',
                'min-w-3xl' => $cacheMode === 'off',
            ]) style="grid-template-columns: {{ $cacheMode === 'off' ? 'minmax(12rem, 0.9fr) auto minmax(18rem, 1.3fr) auto minmax(12rem, 0.95fr)' : 'minmax(12rem, 0.9fr) auto minmax(18rem, 1.3fr) auto minmax(12rem, 0.95fr) auto minmax(12rem, 0.95fr)' }}">
                <div class="flex min-w-0 flex-col rounded-xl border border-brand-ink/10 bg-white p-3 dark:bg-zinc-900">
                    <p class="text-xs font-semibold text-brand-ink">{{ __('Edge network') }}</p>
                    <ul class="mt-3 space-y-2 text-xs">
                        <li class="flex items-center justify-between gap-2">
                            <span class="text-brand-moss">{{ __('DDoS protection') }}</span>
                            <span class="inline-flex items-center gap-1 font-medium text-emerald-700 dark:text-emerald-400"><span class="size-1.5 rounded-full bg-current" aria-hidden="true"></span>{{ __('Active') }}</span>
                        </li>
                        <li class="flex items-center justify-between gap-2">
                            <span class="text-brand-moss">{{ __('CDN') }}</span>
                            <span class="inline-flex items-center gap-1 font-medium text-emerald-700 dark:text-emerald-400"><span class="size-1.5 rounded-full bg-current" aria-hidden="true"></span>{{ __('Enabled') }}</span>
                        </li>
                        <li class="flex items-center justify-between gap-2">
                            <span class="text-brand-moss">{{ __('Edge caching') }}</span>
                            <button
                                type="button"
                                role="switch"
                                aria-checked="{{ $cacheMode !== 'off' ? 'true' : 'false' }}"
                                wire:click="toggleEdgeCache({{ $cacheMode === 'off' ? 'true' : 'false' }})"
                                @class([
                                    'inline-flex items-center gap-1 rounded-full border px-2 py-0.5 font-semibold',
                                    'border-emerald-700/30 text-emerald-700 dark:border-emerald-400/40 dark:text-emerald-400' => $cacheMode !== 'off',
                                    'border-brand-ink/15 text-brand-moss' => $cacheMode === 'off',
                                ])
                            >
                                <span @class(['size-1.5 rounded-full', 'bg-current' => $cacheMode !== 'off', 'bg-brand-mist' => $cacheMode === 'off']) aria-hidden="true"></span>
                                {{ $cacheMode === 'off' ? __('Off') : __('Enabled') }}
                            </button>
                        </li>
                    </ul>
                    <a href="{{ route('sites.show', ['server' => $server, 'site' => $site, 'section' => 'routing']) }}" wire:navigate class="mt-auto pt-3 text-xs font-semibold text-brand-ink underline">{{ __('Domains') }}</a>
                </div>

                <div class="flex items-center justify-center self-stretch px-1" aria-hidden="true"><span class="resource-flow resource-flow-x"></span></div>

                <div @class([
                    'flex min-w-0 flex-col rounded-xl border p-3',
                    'border-brand-sage bg-brand-sage/5' => $isContainer,
                    'border-brand-ink/10 bg-white dark:bg-zinc-900' => ! $isContainer,
                ])>
                    <div class="flex items-baseline justify-between gap-2">
                        <p class="text-xs font-semibold text-brand-ink">{{ __('App') }}</p>
                        @if ($isContainer && is_array($settings))
                            <label class="flex items-center gap-1.5 text-xs text-brand-moss">
                                {{ __('Instances') }}
                                <select wire:change="selectInstances($event.target.value)" class="rounded-md border border-brand-ink/15 bg-white py-0.5 ps-1.5 pe-6 text-xs font-semibold text-brand-ink dark:bg-zinc-900">
                                    @foreach ($instanceCounts as $count)
                                        <option value="{{ $count }}" @selected($settings['max_instances'] === $count)>{{ $count }}</option>
                                    @endforeach
                                </select>
                            </label>
                        @endif
                    </div>
                    @if ($isContainer && is_array($settings))
                        <label class="mt-1 flex items-center gap-1.5 text-xs text-brand-moss">
                            {{ __('Sleeps after') }}
                            <select wire:model.live="sleepAfter" class="rounded-md border border-brand-ink/15 bg-white py-0.5 ps-1.5 pe-6 text-xs font-semibold text-brand-ink dark:bg-zinc-900">
                                @foreach (\App\Modules\Edge\Support\EdgeContainerSettings::SLEEP_AFTER as $option)
                                    <option value="{{ $option }}" @selected($sleepAfter === $option)>{{ $option }}</option>
                                @endforeach
                            </select>
                        </label>
                        @php
                            $customSelected = $settings['instance_type'] === 'custom';
                            $chosen = collect($sizes)->firstWhere('key', $settings['instance_type']);
                        @endphp
                        <label class="mt-3 block text-xs text-brand-moss">
                            {{ __('Size') }}
                            <select wire:model.live="draftInstanceType" class="mt-1 block w-full rounded-md border border-brand-ink/15 bg-white py-1.5 ps-2 pe-8 text-xs font-semibold text-brand-ink dark:bg-zinc-900">
                                @foreach ($sizes as $size)
                                    <option value="{{ $size['key'] }}" @selected($draftInstanceType === $size['key'])>{{ $size['label'] }} · {{ $size['vcpu'] }} · {{ $size['memory'] }} · {{ __('up to :price', ['price' => $size['price']]) }}</option>
                                @endforeach
                                <option value="custom" @selected($draftInstanceType === 'custom')>{{ __('Custom · 1–4 vCPU · up to 12 GiB') }}</option>
                            </select>
                        </label>
                        @php
                            $detail = $customSelected ? $customQuote : $chosen;
                        @endphp
                        @if (is_array($detail))
                            <dl class="mt-2 space-y-1 text-xs">
                                <div class="flex justify-between gap-2">
                                    <dt class="text-brand-moss">{{ __('vCPU') }}</dt>
                                    <dd class="font-medium text-brand-ink">{{ $detail['vcpu'] }}</dd>
                                </div>
                                <div class="flex justify-between gap-2">
                                    <dt class="text-brand-moss">{{ __('Memory') }}</dt>
                                    <dd class="font-medium text-brand-ink">{{ $detail['memory'] }}</dd>
                                </div>
                                <div class="flex justify-between gap-2">
                                    <dt class="text-brand-moss">{{ __('Disk') }}</dt>
                                    <dd class="font-medium text-brand-ink">{{ $detail['disk'] }}</dd>
                                </div>
                                <div class="flex justify-between gap-2">
                                    <dt class="text-brand-moss">{{ __('Always on') }}</dt>
                                    <dd class="font-medium text-brand-ink">{{ __('up to :price', ['price' => $detail['price']]) }}</dd>
                                </div>
                                <div class="flex justify-between gap-2">
                                    <dt class="text-brand-moss">{{ __('Instances') }}</dt>
                                    <dd class="font-medium text-brand-ink">{{ $settings['max_instances'] }}</dd>
                                </div>
                            </dl>
                            <p class="mt-2 text-xs text-brand-moss">{{ __('The first start runs only this many, together. A later deploy can briefly run one extra instance so the new version starts before the current one stops. Queues run on these same instances.') }}</p>
                            @if (is_array($quote))
                                <button type="button" wire:click="openPanel('estimate')" x-on:click="$dispatch('open-modal', 'resources-estimate')" class="mt-2 text-left text-xs font-semibold text-brand-ink underline">{{ __('Cost estimate · :price/mo', ['price' => $quote['awakeMonth']]) }}</button>
                            @endif
                        @endif
                        @if ($customSelected)
                            <form wire:submit="saveCustom" class="mt-2 space-y-2 rounded-lg border border-brand-ink/10 p-2">
                                <p class="text-xs text-brand-moss">{{ __('At least 3 GiB of memory per vCPU. Disk at most 2 GB per GiB of memory.') }}</p>
                                <div class="grid grid-cols-3 gap-2">
                                    <label class="text-xs text-brand-moss">
                                        {{ __('vCPU') }}
                                        <input type="number" min="1" max="4" wire:model.live="customVcpu" class="mt-1 block w-full rounded-md border border-brand-ink/15 bg-white px-2 py-1 text-xs text-brand-ink dark:bg-zinc-900" />
                                    </label>
                                    <label class="text-xs text-brand-moss">
                                        {{ __('Memory GiB') }}
                                        <input type="number" min="3" max="12" wire:model.live="customMemoryGib" class="mt-1 block w-full rounded-md border border-brand-ink/15 bg-white px-2 py-1 text-xs text-brand-ink dark:bg-zinc-900" />
                                    </label>
                                    <label class="text-xs text-brand-moss">
                                        {{ __('Disk GB') }}
                                        <input type="number" min="1" max="20" wire:model.live="customDiskGb" class="mt-1 block w-full rounded-md border border-brand-ink/15 bg-white px-2 py-1 text-xs text-brand-ink dark:bg-zinc-900" />
                                    </label>
                                </div>
                                @error('custom')
                                    <p class="text-xs text-red-700 dark:text-red-400">{{ $message }}</p>
                                @enderror
                                <button type="submit" class="rounded-md bg-brand-ink px-2 py-1 text-xs font-semibold text-white">{{ __('Save custom size') }}</button>
                            </form>
                        @endif
                        @php $smaller = \App\Modules\Edge\Support\EdgeContainerSettings::sizeSuggestion($site); @endphp
                        @if ($smaller && $draftInstanceType !== $smaller['type'])
                            <div class="mt-2 rounded-md bg-emerald-50 px-2 py-1.5 text-xs text-emerald-900 dark:bg-emerald-950/40 dark:text-emerald-200">
                                {{ __('Peak memory this week: :peak MB. :size fits with room to spare and saves $:save an hour while awake.', ['peak' => round($smaller['peak_mb']), 'size' => __(ucfirst(str_replace('-', ' ', $smaller['type']))), 'save' => rtrim(rtrim(number_format($smaller['save_per_hour'], 4), '0'), '.')]) }}
                                <button type="button" wire:click="selectSize('{{ $smaller['type'] }}')" class="font-semibold underline">{{ __('Use it') }}</button>
                            </div>
                        @endif
                        @php $placement = $site->edgeMeta()['placement'] ?? null; @endphp
                        @if (is_array($placement) && ($placement['location'] ?? '') !== '')
                            @php $far = ($placement['rtt_ms'] ?? 0) > \App\Modules\Edge\Services\Containers\EdgeContainerDeployer::FAR_FROM_DATABASE_MS; @endphp
                            <p @class(['mt-2 text-xs', 'font-semibold text-amber-800 dark:text-amber-300' => $far, 'text-brand-moss' => ! $far])>
                                {{ __('Running in :location (:region), :ms ms to the database.', ['location' => $placement['location'], 'region' => $placement['region'], 'ms' => rtrim(rtrim(number_format((float) $placement['rtt_ms'], 1), '0'), '.')]) }}
                                @if ($far) {{ __('That is far: redeploy to be placed again.') }} @endif
                            </p>
                        @endif
                        <button type="button" wire:click="openPanel('sleep')" x-on:click="$dispatch('open-modal', 'resources-sleep')" class="mt-auto pt-3 text-left text-xs font-semibold text-brand-ink underline">{{ __('Sleep, region, scheduler') }}</button>
                    @else
                        <p class="mt-2 text-xs text-brand-moss">{{ __('This app is served as :mode. No container size to pick.', ['mode' => $runtimeMode]) }}</p>
                    @endif
                </div>
                <div class="flex min-w-0 flex-col gap-1" style="grid-column: 3; grid-row: 2">
                @if ($isContainer && ($workers['enabled'] ?? false))
                    @php
                        $w = \App\Modules\Edge\Support\EdgeQueueWorkers::normalize($workers);
                        $wField = 'block w-full rounded-md border border-brand-ink/15 bg-white py-1 ps-2 pe-6 text-xs font-semibold text-brand-ink dark:bg-zinc-900';
                    @endphp
                    <div class="flex justify-center py-0.5" aria-hidden="true"><span class="resource-flow resource-flow-y"></span></div>
                    <div class="rounded-xl border border-brand-sage bg-brand-sage/5 p-3" wire:key="queue-workers-card">
                        <div class="flex items-center justify-between gap-2">
                            <p class="flex items-center gap-1.5 text-xs font-semibold text-brand-ink">
                                <x-resource-kind-icon kind="queue" class="h-3.5 w-3.5 shrink-0" />
                                {{ __('Queue workers') }}
                            </p>
                            <span class="flex items-center gap-3">
                                @if (\App\Modules\Edge\Support\EdgeQueueWorkers::for($site)['enabled'])
                                    <button type="button" wire:click="pauseWorkers({{ $w['paused'] ? 'false' : 'true' }})" wire:loading.attr="disabled" wire:target="pauseWorkers" class="text-xs font-semibold text-brand-ink underline">{{ $w['paused'] ? __('Resume') : __('Pause') }}</button>
                                @endif
                                <button type="button" wire:click="removeWorkers" class="text-xs font-semibold text-brand-ink underline">{{ __('Remove') }}</button>
                            </span>
                        </div>
                        @if ($w['paused'])
                            <p class="mt-2 rounded-md bg-amber-50 px-2 py-1.5 text-xs font-semibold text-amber-900 dark:bg-amber-950/40 dark:text-amber-200">{{ __('Paused. Jobs wait on the queue until you resume; deploys and autoscaling leave the workers stopped.') }}</p>
                        @endif
                        @if ($workersUnavailable)
                            <p class="mt-2 text-xs text-brand-ink">{{ $workersUnavailable }}</p>
                        @else
                            <dl class="mt-2 grid grid-cols-[auto_1fr] items-center gap-x-2 gap-y-1.5 text-xs">
                                <dt><label for="workers-autoscale" class="text-brand-moss">{{ __('Autoscale') }}</label></dt>
                                @php $allow = \App\Modules\Edge\Support\EdgeQueueWorkers::allowance($site); @endphp
                                <dd><label class="inline-flex items-center gap-1.5 font-semibold text-brand-ink"><input id="workers-autoscale" type="checkbox" wire:model.live="workers.autoscale" @disabled(! $allow['autoscale']) class="rounded border-brand-ink/20 disabled:opacity-50" /> {{ ! $allow['autoscale'] ? __('On Pro and Team') : ($w['autoscale'] ? __('On, follows the backlog') : __('Off')) }}</label></dd>
                                <dt><label for="workers-instances" class="text-brand-moss">{{ $w['autoscale'] ? __('Always on') : __('Instances') }}</label></dt>
                                <dd><select id="workers-instances" wire:model.live="workers.instances" class="{{ $wField }}">
                                    @for ($i = $w['autoscale'] ? 0 : 1; $i <= \App\Modules\Edge\Support\EdgeQueueWorkers::MAX_INSTANCES; $i++)<option value="{{ $i }}">{{ $i === 0 ? __('0, start when jobs arrive') : $i }}</option>@endfor
                                </select></dd>
                                @if ($w['autoscale'])
                                    <dt><label for="workers-max" class="text-brand-moss">{{ __('Up to') }}</label></dt>
                                    <dd><select id="workers-max" wire:model.live="workers.max_instances" class="{{ $wField }}">
                                        @for ($i = $w['instances']; $i <= \App\Modules\Edge\Support\EdgeQueueWorkers::MAX_INSTANCES; $i++)<option value="{{ $i }}">{{ trans_choice(':count instance|:count instances', $i) }}</option>@endfor
                                    </select></dd>
                                    <dt><label for="workers-scale-per" class="text-brand-moss">{{ __('Add one at') }}</label></dt>
                                    <dd class="flex items-center gap-1.5"><input id="workers-scale-per" type="number" min="1" max="1000" wire:model.live.debounce.500ms="workers.scale_per" class="block w-16 rounded-md border border-brand-ink/15 bg-white px-2 py-1 text-xs font-semibold text-brand-ink dark:bg-zinc-900" /><span class="text-brand-moss">{{ __('jobs waiting per process') }}</span></dd>
                                    <dt><label for="workers-max-wait" class="text-brand-moss">{{ __('Or when') }}</label></dt>
                                    <dd class="flex items-center gap-1.5"><span class="text-brand-moss">{{ __('a job waits') }}</span><input id="workers-max-wait" type="number" min="0" max="3600" wire:model.live.debounce.500ms="workers.max_wait" class="block w-16 rounded-md border border-brand-ink/15 bg-white px-2 py-1 text-xs font-semibold text-brand-ink dark:bg-zinc-900" /><span class="text-brand-moss">{{ __('s (0 = off)') }}</span></dd>
                                @endif
                                <dt><label for="workers-processes" class="text-brand-moss">{{ __('Processes') }}</label></dt>
                                <dd><select id="workers-processes" wire:model.live="workers.processes" class="{{ $wField }}">
                                    @for ($i = 1; $i <= \App\Modules\Edge\Support\EdgeQueueWorkers::MAX_PROCESSES; $i++)<option value="{{ $i }}">{{ trans_choice(':count per instance|:count per instance', $i) }}</option>@endfor
                                </select></dd>
                                <dt><label for="workers-connection" class="text-brand-moss">{{ __('Connection') }}</label></dt>
                                <dd><select id="workers-connection" wire:model.live="workers.connection" class="{{ $wField }}">
                                    <option value="auto">{{ __('Automatic') }}{{ $w['connection'] === 'auto' && $workersConnection ? ' ('.$workersConnection.')' : '' }}</option>
                                    <option value="redis">redis</option>
                                    <option value="database">database</option>
                                </select></dd>
                                <dt><label for="workers-queues" class="text-brand-moss">{{ __('Queues') }}</label></dt>
                                <dd><input id="workers-queues" type="text" wire:model.live.debounce.500ms="workers.queues" placeholder="high,default" class="block w-full rounded-md border border-brand-ink/15 bg-white px-2 py-1 font-mono text-xs text-brand-ink dark:bg-zinc-900" /></dd>
                            </dl>
                            @if ($workersConnection === null)
                                <p class="mt-2 text-xs font-semibold text-red-700 dark:text-red-400">{{ __('This app has no :connection for workers to pull from.', ['connection' => $w['connection']]) }}</p>
                            @elseif ($workersConnection === 'database' && ($site->edgeMeta()['database']['provider'] ?? '') === 'dply' && (int) ($site->edgeMeta()['database']['suspend'] ?? -1) !== -1)
                                <p class="mt-2 text-xs text-brand-ink">
                                    {{ __('Workers check the database every :sleep s, so it will not sleep while they run.', ['sleep' => $w['sleep']]) }}
                                    <button type="button" wire:click="useValkeyForWorkers" x-on:click="$dispatch('open-modal', 'resources-connection')" class="font-semibold underline">{{ __('Queue on dply Valkey instead') }}</button>
                                </p>
                            @endif
                            <div class="mt-3 border-t border-brand-ink/10 pt-2 text-xs">
                                <p class="font-semibold text-brand-ink">{{ __('Groups') }}</p>
                                <p class="mt-0.5 text-brand-moss">{{ __('More workers for other queues, sized and scaled on their own, so a flood on one queue cannot hold up another.') }}</p>
                                @foreach (array_values((array) ($workers['groups'] ?? [])) as $gi => $rawGroup)
                                    @php $g = \App\Modules\Edge\Support\EdgeQueueWorkers::normalizeGroup($rawGroup, $gi); @endphp
                                    <div class="mt-2 rounded-lg border border-brand-ink/10 p-2" wire:key="worker-group-{{ $gi }}">
                                        <div class="flex items-center justify-between gap-2">
                                            <label class="flex min-w-0 flex-1 items-center gap-1.5">
                                                <span class="text-brand-moss">{{ __('Queues') }}</span>
                                                <input type="text" wire:model.live.debounce.500ms="workers.groups.{{ $gi }}.queues" placeholder="high" class="block w-full min-w-0 rounded-md border border-brand-ink/15 bg-white px-2 py-1 font-mono text-xs text-brand-ink dark:bg-zinc-900" />
                                            </label>
                                            <button type="button" wire:click="removeWorkerGroup({{ $gi }})" class="shrink-0 font-semibold text-brand-ink underline">{{ __('Remove') }}</button>
                                        </div>
                                        <div class="mt-1.5 flex flex-wrap items-center gap-x-3 gap-y-1">
                                            <label class="flex items-center gap-1"><span class="text-brand-moss">{{ $g['autoscale'] ? __('Always on') : __('Instances') }}</span>
                                                <select wire:model.live="workers.groups.{{ $gi }}.instances" class="rounded-md border border-brand-ink/15 bg-white py-0.5 ps-1.5 pe-6 text-xs font-semibold text-brand-ink dark:bg-zinc-900">
                                                    @for ($i = $g['autoscale'] ? 0 : 1; $i <= \App\Modules\Edge\Support\EdgeQueueWorkers::MAX_INSTANCES; $i++)<option value="{{ $i }}">{{ $i }}</option>@endfor
                                                </select></label>
                                            <label class="flex items-center gap-1"><span class="text-brand-moss">{{ __('Processes') }}</span>
                                                <select wire:model.live="workers.groups.{{ $gi }}.processes" class="rounded-md border border-brand-ink/15 bg-white py-0.5 ps-1.5 pe-6 text-xs font-semibold text-brand-ink dark:bg-zinc-900">
                                                    @for ($i = 1; $i <= \App\Modules\Edge\Support\EdgeQueueWorkers::MAX_PROCESSES; $i++)<option value="{{ $i }}">{{ $i }}</option>@endfor
                                                </select></label>
                                            <label class="flex items-center gap-1 font-semibold text-brand-ink"><input type="checkbox" wire:model.live="workers.groups.{{ $gi }}.autoscale" class="rounded border-brand-ink/20" /> {{ __('Autoscale') }}</label>
                                            @if ($g['autoscale'])
                                                <label class="flex items-center gap-1"><span class="text-brand-moss">{{ __('up to') }}</span>
                                                    <select wire:model.live="workers.groups.{{ $gi }}.max_instances" class="rounded-md border border-brand-ink/15 bg-white py-0.5 ps-1.5 pe-6 text-xs font-semibold text-brand-ink dark:bg-zinc-900">
                                                        @for ($i = $g['instances']; $i <= \App\Modules\Edge\Support\EdgeQueueWorkers::MAX_INSTANCES; $i++)<option value="{{ $i }}">{{ $i }}</option>@endfor
                                                    </select></label>
                                            @endif
                                        </div>
                                        <p class="mt-1 font-mono text-2xs text-brand-moss">worker-{{ $g['key'] }}-N · queue:work --queue={{ $g['queues'] }}</p>
                                    </div>
                                @endforeach
                                @if (count((array) ($workers['groups'] ?? [])) < min(\App\Modules\Edge\Support\EdgeQueueWorkers::MAX_GROUPS, $allow['groups']))
                                    <button type="button" wire:click="addWorkerGroup" class="mt-2 font-semibold text-brand-ink underline">{{ __('Add a group') }}</button>
                                @elseif ($allow['groups'] === 0)
                                    <p class="mt-1 text-brand-moss">{{ __('Groups are on Pro and Team.') }}</p>
                                @endif
                            </div>
                            <details class="mt-2 text-xs">
                                <summary class="cursor-pointer font-semibold text-brand-ink">{{ __('Worker options') }}</summary>
                                <div class="mt-2 grid grid-cols-2 gap-2">
                                    @foreach (['timeout' => [__('Timeout (s)'), 1, 3600], 'tries' => [__('Tries'), 1, 25], 'sleep' => [__('Sleep when empty (s)'), 1, 60], 'memory' => [__('Memory (MB)'), 64, 2048], 'max_time' => [__('Restart after (s)'), 60, 86400]] as $key => [$label, $min, $max])
                                        <label class="text-brand-moss">{{ $label }}
                                            <input type="number" min="{{ $min }}" max="{{ $max }}" wire:model.live.debounce.500ms="workers.{{ $key }}" class="mt-0.5 block w-full rounded-md border border-brand-ink/15 bg-white px-2 py-1 text-xs font-semibold text-brand-ink dark:bg-zinc-900" />
                                        </label>
                                    @endforeach
                                </div>
                                <p class="mt-2 font-mono text-2xs text-brand-moss">php artisan queue:work {{ $workersConnection ?? '…' }} --queue={{ $w['queues'] }} --tries={{ $w['tries'] }} --timeout={{ $w['timeout'] }} --sleep={{ $w['sleep'] }} --memory={{ $w['memory'] }} --max-time={{ $w['max_time'] }}</p>
                            </details>
                            @php
                                $draftSpan = \App\Modules\Edge\Support\EdgeQueueWorkers::draftInstances($workers);
                                $overPlan = ($allow['instances'] !== null && $draftSpan['max'] > $allow['instances'])
                                    || count((array) ($workers['groups'] ?? [])) > $allow['groups']
                                    || (! $allow['autoscale'] && ($w['autoscale'] || collect($w['groups'])->contains('autoscale', true)));
                            @endphp
                            @if ($overPlan)
                                <p class="mt-2 rounded-md bg-amber-50 px-2 py-1.5 text-xs font-semibold text-amber-900 dark:bg-amber-950/40 dark:text-amber-200">
                                    {{ __(':plan runs :n worker instance(s) per app:autoscale:groups. The rest of these settings are not deployed. Choose a plan on the billing page for more.', [
                                        'plan' => $allow['plan'] ?: __('This plan'),
                                        'n' => $allow['instances'] ?? '∞',
                                        'autoscale' => $allow['autoscale'] ? '' : __(', without autoscaling'),
                                        'groups' => $allow['groups'] > 0 ? __(', :g extra group(s)', ['g' => $allow['groups']]) : __(', no extra groups'),
                                    ]) }}
                                </p>
                            @endif
                            <div class="mt-2 rounded-lg bg-white/70 px-2.5 py-2 text-xs dark:bg-zinc-900/70">
                                @php $span = \App\Modules\Edge\Support\EdgeQueueWorkers::draftInstances($workers); @endphp
                                @if ($span['max'] > $span['min'])
                                    <p class="font-semibold text-brand-ink">{{ __('About $:min–$:max/mo', ['min' => number_format($workersMonthlyCents / 100, 2), 'max' => number_format($workersMaxMonthlyCents / 100, 2)]) }}</p>
                                    <p class="mt-0.5 text-brand-moss">{{ __(':min–:max × :size · the extra ones billed only while running', ['min' => $span['min'], 'max' => $span['max'], 'size' => $settings['instance_type'] ?? 'basic']) }}</p>
                                @else
                                    <p class="font-semibold text-brand-ink">{{ __('About $:total/mo', ['total' => number_format($workersMonthlyCents / 100, 2)]) }}</p>
                                    <p class="mt-0.5 text-brand-moss">{{ __(':instances × :size, always on', ['instances' => $span['min'], 'size' => $settings['instance_type'] ?? 'basic']) }}</p>
                                @endif
                                @foreach ($workerScaling as $scaling)
                                    @include('livewire.sites.edge.workspace.partials.worker-autoscale', $scaling + ['labelled' => count($workerScaling) > 1])
                                @endforeach
                            </div>
                            <div class="mt-2 flex flex-wrap items-center gap-x-3 gap-y-1 text-xs">
                                <button type="button" wire:click="loadWorkersBacklog" wire:loading.attr="disabled" wire:target="loadWorkersBacklog" class="font-semibold text-brand-ink underline disabled:opacity-50">
                                    <span wire:loading.remove wire:target="loadWorkersBacklog">{{ __('Check workers') }}</span>
                                    <span wire:loading wire:target="loadWorkersBacklog">{{ __('Checking…') }}</span>
                                </button>
                                @if (is_array($workersBacklog))
                                    @foreach ($workersBacklog['queues'] as $queue => $waiting)
                                        <span class="tabular-nums text-brand-moss"><span class="font-mono text-brand-ink">{{ $queue }}</span> {{ trans_choice(':count waiting|:count waiting', $waiting) }}</span>
                                    @endforeach
                                    @if (($workersBacklog['failed'] ?? null) !== null)
                                        <span @class(['tabular-nums', 'font-semibold text-red-700 dark:text-red-400' => $workersBacklog['failed'] > 0, 'text-brand-moss' => $workersBacklog['failed'] === 0])>{{ trans_choice(':count failed|:count failed', $workersBacklog['failed']) }}</span>
                                    @endif
                                @endif
                                <button type="button" wire:click="openFailedJobs" x-on:click="$dispatch('open-modal', 'resources-failed-jobs')" class="font-semibold text-brand-ink underline">{{ __('Failed jobs') }}</button>
                                <button type="button" wire:click="openWorkerLogs" x-on:click="$dispatch('open-modal', 'resources-worker-logs')" class="font-semibold text-brand-ink underline">{{ __('Logs') }}</button>
                                <button type="button" wire:click="sendTestJob" wire:loading.attr="disabled" wire:target="sendTestJob" class="font-semibold text-brand-ink underline">{{ __('Send test job') }}</button>
                                @if ($workersBacklogError)
                                    <span class="text-red-700 dark:text-red-400">{{ $workersBacklogError }}</span>
                                @endif
                            </div>
                            @if (is_array($workersStatus) || $workersStatusError)
                                @php
                                    $stoppedWorkers = $w['paused'] ? 0 : collect($workersStatus ?? [])->where('wanted', true)->whereIn('status', ['stopped', 'stopped_with_code'])->count();
                                @endphp
                                <ul class="mt-2 space-y-1 text-xs" wire:key="workers-status">
                                    @foreach ($workersStatus ?? [] as $worker)
                                        @php
                                            $up = in_array($worker['status'], ['running', 'healthy'], true);
                                            $idle = ! $up && (! ($worker['wanted'] ?? true) || ($worker['paused'] ?? false));
                                        @endphp
                                        <li class="flex items-center justify-between gap-2">
                                            <span class="flex items-center gap-1.5 font-mono text-brand-ink">
                                                <span @class(['h-1.5 w-1.5 rounded-full', 'bg-emerald-500' => $up, 'bg-amber-500' => $worker['status'] === 'stopping', 'bg-brand-ink/20' => $idle && $worker['status'] !== 'stopping', 'bg-red-500' => ! $up && ! $idle && $worker['status'] !== 'stopping'])></span>
                                                {{ $worker['name'] }}
                                                @if ($p = $workersPlacement[$worker['name']] ?? null)
                                                    @php $far = max($p['db_ms'] ?? 0, $p['redis_ms'] ?? 0) > \App\Modules\Edge\Services\Containers\EdgeContainerDeployer::FAR_FROM_DATABASE_MS; @endphp
                                                    <span @class(['font-sans', 'text-brand-moss' => ! $far, 'font-semibold text-amber-800 dark:text-amber-300' => $far])>· {{ $p['location'] ?: '?' }}{{ $p['db_ms'] !== null ? ' · db '.$p['db_ms'].' ms' : '' }}{{ $p['redis_ms'] !== null ? ' · redis '.$p['redis_ms'].' ms' : '' }}</span>
                                                @endif
                                            </span>
                                            <span class="text-brand-moss">
                                                {{ $idle && $worker['status'] !== 'stopping' ? (($worker['paused'] ?? false) ? __('Paused') : __('Idle (scaled down)')) : match ($worker['status']) {
                                                    'running', 'healthy' => __('Running'),
                                                    'stopping' => __('Stopping'),
                                                    'stopped_with_code' => __('Exited (code :code)', ['code' => $worker['exit_code'] ?? '?']),
                                                    'stopped' => __('Stopped'),
                                                    default => $worker['status'],
                                                } }}@if ($worker['since']) · {{ \Illuminate\Support\Carbon::createFromTimestamp($worker['since'])->diffForHumans(short: true) }}@endif
                                            </span>
                                        </li>
                                    @endforeach
                                </ul>
                                @if ($stoppedWorkers > 0)
                                    <button type="button" wire:click="startWorkers" wire:loading.attr="disabled" wire:target="startWorkers" class="mt-1 text-xs font-semibold text-brand-ink underline">{{ __('Start stopped workers') }}</button>
                                @endif
                                @if ($workersStatusError)
                                    <p class="mt-1 text-xs text-red-700 dark:text-red-400">{{ $workersStatusError }}</p>
                                @endif
                            @endif
                        @endif
                    </div>
                @endif
                @if ($isContainer && $scheduler)
                    @php $schedulerOnWorker = ($workers['enabled'] ?? false) && \App\Modules\Edge\Support\EdgeQueueWorkers::unavailableReason($site) === null; @endphp
                    <div class="flex justify-center py-0.5" aria-hidden="true"><span class="resource-flow resource-flow-y"></span></div>
                    <div class="rounded-xl border border-brand-sage bg-brand-sage/5 p-3" wire:key="scheduler-card">
                        <div class="flex items-center justify-between gap-2">
                            <p class="flex items-center gap-1.5 text-xs font-semibold text-brand-ink"><x-heroicon-o-clock class="h-3.5 w-3.5 shrink-0" /> {{ __('Scheduler') }}</p>
                            <button type="button" wire:click="removeScheduler" class="text-xs font-semibold text-brand-ink underline">{{ __('Remove') }}</button>
                        </div>
                        <p class="mt-1.5 font-mono text-2xs text-brand-moss">{{ $schedulerOnWorker ? 'php artisan schedule:work' : 'php artisan schedule:run · * * * * *' }}</p>
                        <p class="mt-1 text-xs text-brand-moss">
                            {{ $schedulerOnWorker
                                ? __('Runs in worker-0 beside the queue workers, so the app itself can still sleep. Pausing the workers pauses it too.')
                                : __('A Cron Trigger calls the app every minute, which keeps it from sleeping. Add queue workers and it moves into a worker instead.') }}
                        </p>
                        <div class="mt-2 flex flex-wrap items-center gap-x-3 gap-y-1 text-xs">
                            <button type="button" wire:click="runSchedulerNow" wire:loading.attr="disabled" wire:target="runSchedulerNow" class="font-semibold text-brand-ink underline disabled:opacity-50">
                                <span wire:loading.remove wire:target="runSchedulerNow">{{ __('Run now') }}</span>
                                <span wire:loading wire:target="runSchedulerNow">{{ __('Running…') }}</span>
                            </button>
                        </div>
                        @if ($schedulerOutput)
                            <pre class="mt-2 max-h-40 overflow-auto whitespace-pre-wrap rounded bg-zinc-950 p-2 font-mono text-2xs text-zinc-200">{{ $schedulerOutput }}</pre>
                        @endif
                    </div>
                @endif
                @foreach ($connections as $connection)
                    <div class="flex justify-center py-0.5" aria-hidden="true"><span @class(['resource-flow resource-flow-y', 'resource-flow-asleep' => $connection['asleep']])></span></div>
                    <div @class([
                        'rounded-xl border p-3',
                        'resource-asleep border-dashed border-brand-ink/20 bg-white/70 dark:bg-zinc-900/70' => $connection['asleep'],
                        'border-brand-sage bg-brand-sage/5' => ! $connection['asleep'],
                    ])>
                        <div class="flex flex-col gap-2">
                            <p class="flex items-center gap-1.5 whitespace-nowrap text-xs font-semibold text-brand-ink">
                                <x-resource-kind-icon :kind="$connection['kind']" class="h-3.5 w-3.5 shrink-0" />
                                {{ __($connectionKinds[$connection['kind']]['label']) }}
                                @if ($connection['asleep'])
                                    <span class="font-medium text-brand-moss">{{ __('Asleep') }}</span>
                                    <span class="resource-snore" aria-hidden="true"><span>z</span><span>z</span><span>z</span></span>
                                @endif
                            </p>
                            <span class="flex flex-wrap gap-x-2 gap-y-1">
                                @if ($connection['kind'] === 'service')
                                    <button type="button" wire:click="$set('explainConnectionHost', '{{ $connection['host'] }}')" x-on:click="$dispatch('open-modal', 'resources-service')" class="text-xs font-semibold text-brand-ink underline">{{ __('Open') }}</button>
                                @elseif ($connection['kind'] === 'key_value')
                                    <button type="button" wire:click="openKv('{{ $connection['host'] }}')" wire:loading.attr="disabled" wire:target="openKv" class="text-xs font-semibold text-brand-ink underline disabled:opacity-50">{{ __('Settings') }}</button>
                                @elseif ($connection['kind'] === 'object_storage')
                                    <button type="button" wire:click="openObject('{{ $connection['host'] }}')" x-on:click="$dispatch('open-modal', 'resources-object')" class="text-xs font-semibold text-brand-ink underline">{{ __('Open') }}</button>
                                @elseif ($connection['kind'] === 'redis' && \App\Modules\Edge\Support\EdgeValkey::isTarget($connection['target']))
                                    <button type="button" wire:click="$set('valkeyHost', '{{ $connection['host'] }}')" x-on:click="$dispatch('open-modal', 'resources-valkey')" class="text-xs font-semibold text-brand-ink underline">{{ __('Details') }}</button>
                                @elseif ($connection['kind'] === 'images')
                                    <button type="button" wire:click="$set('imagesHost', '{{ $connection['host'] }}')" x-on:click="$dispatch('open-modal', 'resources-images')" class="text-xs font-semibold text-brand-ink underline">{{ __('Settings') }}</button>
                                @endif
                                <button type="button" wire:click="sleepConnection('{{ $connection['host'] }}', {{ $connection['asleep'] ? 'false' : 'true' }})" class="text-xs font-semibold text-brand-ink underline">{{ $connection['asleep'] ? __('Wake') : __('Sleep') }}</button>
                                <button type="button" wire:click="askDeleteConnection('{{ $connection['host'] }}')" x-on:click="$dispatch('open-modal', 'resources-delete-connection')" class="text-xs font-semibold text-brand-ink underline">{{ __('Delete') }}</button>
                                <button type="button" wire:click="removeConnection('{{ $connection['host'] }}')" class="text-xs font-semibold text-brand-ink underline">{{ __('Detach') }}</button>
                            </span>
                        </div>
                        @if ($connection['kind'] === 'object_storage')
                            <p class="mt-1 text-xs text-brand-moss">{{ __('Bucket :name', ['name' => $connection['target']]) }}</p>
                        @elseif ($connection['kind'] === 'redis' && \App\Modules\Edge\Support\EdgeValkey::isTarget($connection['target']) && ! $cardOnFile)
                            <p class="mt-1 text-xs text-brand-moss">{{ __('Add a card to keep using this Redis. It stays off the app until then.') }}</p>
                            @if ($site->organization)
                                <a href="{{ route('billing.show', $site->organization) }}" class="text-xs font-semibold text-brand-ink underline">{{ __('Billing') }}</a>
                            @endif
                        @elseif ($connection['kind'] === 'key_value' && $connection['asleep'])
                            <p class="mt-1 font-mono text-xs text-brand-moss">{{ $connection['host'] }}</p>
                            <p class="mt-1 text-xs text-brand-moss">{{ __('Asleep. The address comes off the app on the next deploy. Not billed until you wake it.') }}</p>
                        @elseif ($connection['kind'] !== 'redis')
                            <p class="mt-1 font-mono text-xs text-brand-moss">{{ $isWorker ? 'env.'.$connection['name'] : $connection['host'] }}</p>
                        @endif
                        @if ($isWorker && ! in_array($connection['kind'], $allowedKinds, true))
                            <p class="mt-1 text-xs font-semibold text-red-700 dark:text-red-400">{{ __('This app runs as a Worker. This resource needs a container app, so it is not attached.') }}</p>
                        @endif
                        @if ($connection['kind'] === 'redis' && \App\Modules\Edge\Support\EdgeValkey::isTarget($connection['target']))
                            @php
                                $valkeyClass = \App\Modules\Edge\Support\EdgeValkey::CLASSES[$connection['plan']] ?? \App\Modules\Edge\Support\EdgeValkey::CLASSES[\App\Modules\Edge\Support\EdgeValkey::DEFAULT_CLASS];
                                $valkeySleepNow = (int) ($site->edgeMeta()['valkey_sleep'][$connection['target']] ?? ($valkeyClass['sleeps'] ? \App\Modules\Edge\Support\EdgeValkey::DEFAULT_SLEEP : 0));
                            @endphp
                            @php
                                $valkeyPlan = isset(\App\Modules\Edge\Support\EdgeValkey::CLASSES[$connection['plan']]) ? $connection['plan'] : \App\Modules\Edge\Support\EdgeValkey::DEFAULT_CLASS;
                                $valkeyField = 'block w-full rounded-md border border-brand-ink/15 bg-white py-1 ps-2 pe-6 text-xs font-semibold text-brand-ink disabled:opacity-60 dark:bg-zinc-900';
                            @endphp
                            <dl class="mt-2 grid grid-cols-[auto_1fr] items-center gap-x-2 gap-y-1.5 text-xs" wire:key="valkey-card-{{ md5($connection['host']) }}">
                                <dt><label for="valkey-size-{{ $loop->index }}" class="text-brand-moss">{{ __('Size') }}</label></dt>
                                <dd>
                                    <select id="valkey-size-{{ $loop->index }}" wire:change="saveValkey('{{ $connection['host'] }}', $event.target.value, {{ $valkeySleepNow }})" wire:loading.attr="disabled" wire:target="saveValkey" class="{{ $valkeyField }}">
                                        @foreach (\App\Modules\Edge\Support\EdgeValkey::offered() as $classId => $class)
                                            <option value="{{ $classId }}" @selected($valkeyPlan === $classId)>{{ __($class['label']) }} · {{ __('up to $:price/mo', ['price' => number_format($class['cap_cents'] / 100, 0)]) }}</option>
                                        @endforeach
                                    </select>
                                </dd>
                                <dt><label for="valkey-sleep-{{ $loop->index }}" class="text-brand-moss">{{ __('Sleep') }}</label></dt>
                                <dd>
                                    @if ($valkeyClass['sleeps'])
                                        <select id="valkey-sleep-{{ $loop->index }}" wire:change="saveValkey('{{ $connection['host'] }}', '{{ $valkeyPlan }}', $event.target.value)" wire:loading.attr="disabled" wire:target="saveValkey" class="{{ $valkeyField }}">
                                            @foreach (\App\Modules\Edge\Support\EdgeValkey::SLEEPS as $seconds => $label)
                                                <option value="{{ $seconds }}" @selected($valkeySleepNow === $seconds)>{{ $seconds > 0 ? __('After :time idle', ['time' => __($label)]) : __($label) }}</option>
                                            @endforeach
                                        </select>
                                    @else
                                        <span class="font-semibold text-brand-ink">{{ __('Stays on (Pro sizes do not sleep)') }}</span>
                                    @endif
                                </dd>
                            </dl>
                        @endif
                        @if ($connection['kind'] === 'queue' && isset($queueOwners[$connection['target']]))
                            <p class="mt-1 text-xs text-brand-moss">{{ __('Sends only. :app runs these jobs.', ['app' => $queueOwners[$connection['target']]]) }}</p>
                        @endif
                        @if (in_array($connection['name'], $overriddenByRepo, true))
                            <p class="mt-1 text-xs font-semibold text-brand-ink">{{ __('Overridden by wrangler.toml. The repo binding is used.') }}</p>
                        @endif
                        @if (isset($connectionEstimates[$connection['host']]) && $connection['kind'] === 'redis' && \App\Modules\Edge\Support\EdgeValkey::isTarget($connection['target']))
                            <p class="mt-1 text-xs font-semibold tabular-nums text-brand-ink">{{ __('$:price so far this month · up to $:cap/mo', ['price' => \App\Modules\Edge\Support\EdgeValkey::money($connectionEstimates[$connection['host']]), 'cap' => number_format($valkeyClass['cap_cents'] / 100, 0)]) }}</p>
                        @elseif (isset($connectionEstimates[$connection['host']]))
                            <p class="mt-1 text-xs font-semibold tabular-nums text-brand-ink">{{ __('Cost estimate · $:price', ['price' => number_format($connectionEstimates[$connection['host']] / 100, 2)]) }}</p>
                        @endif
                    </div>
                @endforeach
                <div class="flex justify-center py-0.5" aria-hidden="true"><span class="resource-flow resource-flow-y"></span></div>
                @if ($hasCode)
                    <button type="button" wire:click="openConnectionBuilder" x-on:click="$dispatch('open-modal', 'resources-connection')" class="rounded-xl border border-dashed border-brand-ink/25 px-3 py-2 text-left text-xs font-semibold text-brand-ink">{{ __('Add resource') }}</button>
                @else
                    <p class="rounded-xl border border-dashed border-brand-ink/25 px-3 py-2 text-xs text-brand-moss">{{ __('Resources need server code. Switch to SSR or a container app to attach them.') }}</p>
                @endif
                </div>

                @if ($cacheMode !== 'off')
                    <div class="flex items-center justify-center self-stretch px-1" aria-hidden="true"><span class="resource-flow resource-flow-x"></span></div>

                    <div class="flex min-w-0 flex-col rounded-xl border border-brand-sage bg-brand-sage/5 p-3">
                        <p class="text-xs font-semibold text-brand-ink">{{ __('Cache') }}</p>
                        <p class="mt-1 text-xs text-brand-moss">{{ __('Stored at the edge.') }}</p>
                        <div class="mt-3 grid gap-1.5" role="radiogroup" aria-label="{{ __('Cache') }}">
                            @foreach ($cacheModes as $mode => $label)
                                <button
                                    type="button"
                                    wire:click="selectCache('{{ $mode }}')"
                                    @class([
                                        'rounded-lg border px-2.5 py-1.5 text-left text-xs font-semibold',
                                        'border-brand-sage bg-white text-brand-ink dark:bg-zinc-900' => $cacheMode === $mode,
                                        'border-brand-ink/10 bg-white/70 text-brand-ink dark:bg-zinc-900/70' => $cacheMode !== $mode,
                                    ])
                                >{{ $label }}</button>
                            @endforeach
                        </div>
                        <button type="button" wire:click="openPanel('cache')" x-on:click="$dispatch('open-modal', 'resources-cache')" class="mt-auto pt-3 text-left text-xs font-semibold text-brand-ink underline">{{ __('Cache settings') }}</button>
                    </div>
                @endif

                @if ($databaseVisible)
                <div class="flex items-center justify-center self-stretch px-1" aria-hidden="true"><span class="resource-flow resource-flow-x"></span></div>

                <div @class([
                    'flex min-w-0 flex-col rounded-xl border p-3',
                    'border-brand-sage bg-brand-sage/5' => $databaseEngine !== 'none',
                    'border-dashed border-brand-ink/20 bg-white dark:bg-zinc-900' => $databaseEngine === 'none',
                ])>
                    @php
                        $dplyEngine = in_array($databaseEngine, ['postgres', 'mongodb', 'mysql'], true);
                        $postgresLocked = ! $cardOnFile;
                        $fieldClass = 'block w-full rounded-md border border-brand-ink/15 bg-white py-1 ps-2 pe-6 text-xs font-semibold text-brand-ink disabled:opacity-60 dark:bg-zinc-900';
                    @endphp
                    <label for="database-engine" class="text-xs font-semibold text-brand-ink">{{ __('Database') }}</label>
                    <select id="database-engine" wire:change="selectDatabase($event.target.value)" class="mt-1 {{ $fieldClass }}">
                        <option value="none" @selected($databaseEngine === 'none')>{{ __('None') }}</option>
                        @foreach (['postgres' => __('Postgres'), 'mongodb' => __('MongoDB'), 'mysql' => __('MySQL'), 'sql' => __('SQLite')] as $engine => $label)
                            @if ($engine !== 'sql' && ! $dplyDatabases)
                                <option value="{{ $engine }}" disabled>{{ $label }} · {{ __('Coming soon') }}</option>
                            @elseif ($engine !== 'sql' && ! $cardOnFile)
                                <option value="{{ $engine }}" disabled>{{ $label }} · {{ __('Add a card') }}</option>
                            @else
                                <option value="{{ $engine }}" @selected($databaseEngine === $engine)>{{ $label }}</option>
                            @endif
                        @endforeach
                    </select>
                    @if ($dplyEngine)
                        <dl class="mt-3 grid grid-cols-[auto_1fr] items-center gap-x-2 gap-y-1.5 text-xs">
                            <dt><label for="database-size" class="text-brand-moss">{{ __('Size') }}</label></dt>
                            <dd>
                                <select id="database-size" wire:change="selectPostgresSize($event.target.value)" @disabled($postgresLocked) class="{{ $fieldClass }}">
                                    @foreach ($postgresSizes as $key => $size)
                                        <option value="{{ $key }}" @selected($postgresSize === (string) $key)>{{ $size['cpu'] }} · {{ $size['memory'] }}</option>
                                    @endforeach
                                </select>
                            </dd>
                            <dt><label for="database-sleep" class="text-brand-moss">{{ __('Sleep') }}</label></dt>
                            <dd>
                                <select id="database-sleep" wire:change="selectPostgresSuspend($event.target.value)" @disabled($postgresLocked) class="{{ $fieldClass }}">
                                    @foreach ($postgresSleeps as $seconds => $label)
                                        <option value="{{ $seconds }}" @selected($postgresSuspend === $seconds)>{{ $seconds === -1 ? __($label) : __('After :time idle', ['time' => __($label)]) }}</option>
                                    @endforeach
                                </select>
                            </dd>
                            <dt><label for="postgres-disk" class="text-brand-moss">{{ __('Disk') }}</label></dt>
                            <dd>
                                <select id="postgres-disk" wire:change="selectPostgresDisk($event.target.value)" @disabled($postgresLocked) class="{{ $fieldClass }}">
                                    @foreach ($postgresDisks as $gb => $label)
                                        <option value="{{ $gb }}" @selected($postgresDisk === $gb)>{{ __($label) }}</option>
                                    @endforeach
                                </select>
                            </dd>
                            @if ($postgresSuspend !== -1)
                                <dt><label for="postgres-awake" class="text-brand-moss">{{ __('Awake') }}</label></dt>
                                <dd class="flex items-center gap-1.5">
                                    <input id="postgres-awake" type="number" min="0" max="24" wire:model.live.debounce.400ms="awakeHours" @disabled($postgresLocked) class="w-14 rounded-md border border-brand-ink/15 bg-white px-2 py-1 text-xs font-semibold text-brand-ink disabled:opacity-60 dark:bg-zinc-900" />
                                    <span class="text-brand-moss">{{ __('hours a day') }}</span>
                                </dd>
                            @endif
                        </dl>
                        <div class="mt-3 rounded-lg bg-white/70 px-2.5 py-2 text-xs dark:bg-zinc-900/70">
                            <p class="font-semibold text-brand-ink">{{ __('About $:total/mo', ['total' => number_format((float) str_replace(',', '', $postgresSizes[$postgresSize]['month']) + (float) $postgresGigabyte * $postgresDisk, 2)]) }}</p>
                            <p class="mt-0.5 text-brand-moss">{{ __('Compute $:compute · disk $:disk', ['compute' => $postgresSizes[$postgresSize]['month'], 'disk' => number_format((float) $postgresGigabyte * $postgresDisk, 2)]) }}</p>
                            <p class="mt-0.5 text-brand-moss">{{ __('$:hour/hour awake · disk $:gigabyte/GB', ['hour' => $postgresSizes[$postgresSize]['hour'], 'gigabyte' => $postgresGigabyte]) }}</p>
                            @if ($databaseEngine === 'postgres' && $postgresSuspend !== -1 && $postgresSize !== '0.25')
                                <p class="mt-0.5 text-brand-moss">{{ __('Scales from 1/4 vCPU · 1 GB; priced at full size.') }}</p>
                            @endif
                        </div>
                        <p class="mt-2 text-xs text-brand-moss">{{ __('dply :engine · New York', ['engine' => ['mongodb' => 'MongoDB', 'mysql' => 'MySQL'][$databaseEngine] ?? 'Postgres']) }}</p>
                    @elseif ($databaseEngine === 'sql')
                        <p class="mt-2 text-xs text-brand-moss">{{ __('A file inside the app, saved while it runs and restored when it wakes.') }}</p>
                    @endif
                    @error('database')
                        <p class="mt-2 text-xs text-brand-ink">{{ $message }}</p>
                    @enderror
                    @if (! $cardOnFile)
                        <p class="mt-2 text-xs text-brand-ink">{{ __('Add a card before starting a database. It is billed to that card. SQLite does not need one.') }}</p>
                        @if ($site->organization)
                            <a href="{{ route('billing.show', $site->organization) }}" class="mt-1 inline-block text-xs font-semibold text-brand-ink underline">{{ __('Billing') }}</a>
                        @endif
                    @endif
                    <div class="mt-auto flex gap-3 pt-3">
                        <button type="button" x-on:click="$dispatch('database-tab', 'how'); $dispatch('open-modal', 'resources-app-database')" class="text-left text-xs font-semibold text-brand-ink underline">{{ __('How to use') }}</button>
                        @if ($databaseEngine !== 'none')
                            <button type="button" x-on:click="$dispatch('database-tab', @js($dplyEngine ? 'overview' : 'settings')); $dispatch('open-modal', 'resources-app-database')" class="text-left text-xs font-semibold text-brand-ink underline">{{ $dplyEngine ? __('Stats & backups') : __('Tools') }}</button>
                        @endif
                    </div>
                </div>
                @endif
            </div>
        </div>
    </section>

    @if ($showBrowser)
        <section class="border-t border-brand-ink/10 px-5 py-4 sm:px-6">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <p class="text-xs font-semibold uppercase tracking-[0.16em] text-brand-sage">{{ __('Browser') }}</p>
                    <p class="mt-1 text-xs text-brand-moss">{{ $browserOn ? ($isWorker ? 'env.BROWSER' : $browserHost) : __('Off') }}</p>
                </div>
                <button type="button" wire:click="openPanel('browser')" x-on:click="$dispatch('open-modal', 'resources-browser')" class="rounded-md bg-brand-ink px-3 py-1.5 text-xs font-semibold text-white">{{ __('Open') }}</button>
            </div>
        </section>
    @endif

    <section class="border-t border-brand-ink/10 px-5 py-3 sm:px-6">
        <p class="text-xs text-brand-moss">{{ __('CSS, JavaScript, and images in the app are served automatically. Add a resource on the map only when the app needs something else.') }}</p>
    </section>

    @php
        $explained = collect($connections)->firstWhere('host', $explainConnectionHost);
        $explainedPeer = is_array($explained) ? ($servicePeers[$explained['target']] ?? null) : null;
        $explainedHost = is_array($explained) ? $explained['host'] : $explainConnectionHost;
    @endphp
    <x-modal name="resources-service" :show="$explainConnectionHost !== ''" maxWidth="3xl" focusable>
        <div class="max-h-[80vh] overflow-y-auto bg-white p-5 dark:bg-zinc-900">
            <div class="flex items-start justify-between gap-3">
                <h2 class="text-xs font-semibold uppercase tracking-[0.16em] text-brand-sage">{{ __('Another app') }}</h2>
                <button type="button" wire:click="$set('explainConnectionHost', '')" x-on:click="$dispatch('close-modal', 'resources-service')" class="text-xs font-semibold text-brand-ink underline">{{ __('Close') }}</button>
            </div>
            @if (is_array($explainedPeer))
                <p class="mt-3 max-w-xl text-xs text-brand-moss">{{ __('This app calls :name. The address below belongs only to this app.', ['name' => $explainedPeer['label']]) }}</p>
            @else
                <p class="mt-3 max-w-xl text-xs text-brand-moss">{{ __('This app calls another app in this workspace. The address below belongs only to this app.') }}</p>
            @endif
            <div class="mt-4" x-data="{ tab: 'how' }">
                <div class="flex gap-4 border-b border-brand-ink/10" role="tablist">
                    <button type="button" role="tab" x-on:click="tab = 'how'" :aria-selected="tab === 'how'" :class="tab === 'how' ? 'border-brand-sage text-brand-ink' : 'border-transparent text-brand-moss'" class="-mb-px border-b-2 pb-2 text-xs font-semibold">{{ __('How it works') }}</button>
                    <button type="button" role="tab" x-on:click="tab = 'implementation'" :aria-selected="tab === 'implementation'" :class="tab === 'implementation' ? 'border-brand-sage text-brand-ink' : 'border-transparent text-brand-moss'" class="-mb-px border-b-2 pb-2 text-xs font-semibold">{{ __('Implementation') }}</button>
                </div>
                <div x-show="tab === 'how'" class="mt-4">
                    <ol class="list-decimal space-y-1 pl-4 text-xs text-brand-ink">
                        <li>{{ __('App code sends any method to http://:host/path.', ['host' => $explainedHost]) }}</li>
                        <li>{{ __('The worker for this app receives that call. No other app can.') }}</li>
                        @if (is_array($explainedPeer))
                            <li>{{ __('The same path is sent to :name at :origin.', ['name' => $explainedPeer['label'], 'origin' => $explainedPeer['origin']]) }}</li>
                        @else
                            <li>{{ __('The same path is sent to the other app on its own address.') }}</li>
                        @endif
                        <li>{{ __('The other app’s response comes back to this app.') }}</li>
                        <li>{{ __('This starts working after the next deploy.') }}</li>
                    </ol>
                </div>
                <div x-show="tab === 'implementation'" x-cloak class="mt-4">
                    <p class="text-xs font-semibold text-brand-ink">{{ __('Laravel') }}</p>
                    <p class="mt-1 max-w-xl text-xs text-brand-moss">{{ __('Call this address from the app. It is available after the next deploy.') }}</p>
                    <pre class="mt-2 overflow-x-auto rounded-lg bg-brand-sand/40 p-3 text-xs text-brand-ink dark:bg-zinc-950">{{ "Http::get('http://{$explainedHost}/health');" }}</pre>
                    <p class="mt-4 text-xs font-semibold text-brand-ink">{{ __('Rails') }}</p>
                    <p class="mt-1 max-w-xl text-xs text-brand-moss">{{ __('Call this address from the app. It is available after the next deploy.') }}</p>
                    <pre class="mt-2 overflow-x-auto rounded-lg bg-brand-sand/40 p-3 text-xs text-brand-ink dark:bg-zinc-950">{{ "Faraday.get('http://{$explainedHost}/health')" }}</pre>
                    <p class="mt-4 text-xs font-semibold text-brand-ink">{{ __('HTTP') }}</p>
                    <pre class="mt-2 overflow-x-auto rounded-lg bg-brand-sand/40 p-3 text-xs text-brand-ink dark:bg-zinc-950">{{ "curl http://{$explainedHost}/health" }}</pre>
                </div>
            </div>
            <div class="mt-4 border-t border-brand-ink/10 pt-4">
                <p class="text-xs font-semibold text-brand-ink">{{ __('Demo') }}</p>
                <p class="mt-1 max-w-xl text-xs text-brand-moss">{{ __('Call one path on the other app from here. This does not call this app.') }}</p>
                <label class="mt-3 block text-xs text-brand-moss">
                    {{ __('Path') }}
                    <input type="text" wire:model="serviceDemoPath" spellcheck="false" class="mt-1 block w-full rounded-lg border border-brand-ink/15 bg-white px-3 py-2 font-mono text-sm text-brand-ink dark:bg-zinc-900" />
                </label>
                <button type="button" wire:click="runServiceDemo" wire:loading.attr="disabled" wire:target="runServiceDemo" class="mt-2 rounded-md border border-brand-ink/15 px-3 py-1.5 text-xs font-semibold text-brand-ink disabled:opacity-50">{{ __('Try') }}</button>
                <p wire:loading wire:target="runServiceDemo" class="mt-2 text-xs text-brand-moss">{{ __('Calling the other app…') }}</p>
                @if ($serviceDemoLog !== [])
                    <ol class="mt-3 space-y-1 rounded-lg bg-brand-sand/40 p-3 font-mono text-xs text-brand-ink dark:bg-zinc-950">
                        @foreach ($serviceDemoLog as $line)
                            <li>{{ $line }}</li>
                        @endforeach
                    </ol>
                @endif
                @if ($serviceDemoPreview !== '')
                    <pre class="mt-3 max-h-40 overflow-auto rounded-lg bg-brand-sand/40 p-3 text-xs text-brand-ink dark:bg-zinc-950">{{ $serviceDemoPreview }}</pre>
                @endif
            </div>
        </div>
    </x-modal>

    @php
        $kvConnection = collect($connections)->firstWhere('host', $kvHost);
        $kvHostName = is_array($kvConnection) ? $kvConnection['host'] : $kvHost;
        $kvStore = is_array($kvConnection) ? strtolower((string) $kvConnection['name']) : 'store';
    @endphp
    <x-modal name="resources-kv" :show="$kvHost !== ''" maxWidth="3xl" focusable>
        <div class="max-h-[80vh] overflow-y-auto bg-white p-5 dark:bg-zinc-900">
            <div class="flex items-start justify-between gap-3">
                <h2 class="text-xs font-semibold uppercase tracking-[0.16em] text-brand-sage">{{ __('Key-value store') }}</h2>
                <button type="button" wire:click="$set('kvHost', '')" x-on:click="$dispatch('close-modal', 'resources-kv')" class="text-xs font-semibold text-brand-ink underline">{{ __('Close') }}</button>
            </div>
            @if ($kvHostName === '')
                <p class="mt-4 text-xs text-brand-moss">{{ __('Loading the store…') }}</p>
            @else
            <p class="mt-3 max-w-xl text-xs text-brand-moss">{{ __('This app keeps short values at http://:host/. The address belongs only to this app.', ['host' => $kvHostName]) }}</p>
            <div class="mt-4" x-data="{ tab: 'how' }">
                <div class="flex gap-4 overflow-x-auto border-b border-brand-ink/10" role="tablist">
                    <button type="button" role="tab" x-on:click="tab = 'how'" :aria-selected="tab === 'how'" :class="tab === 'how' ? 'border-brand-sage text-brand-ink' : 'border-transparent text-brand-moss'" class="-mb-px shrink-0 border-b-2 pb-2 text-xs font-semibold">{{ __('How it works') }}</button>
                    <button type="button" role="tab" x-on:click="tab = 'implementation'" :aria-selected="tab === 'implementation'" :class="tab === 'implementation' ? 'border-brand-sage text-brand-ink' : 'border-transparent text-brand-moss'" class="-mb-px shrink-0 border-b-2 pb-2 text-xs font-semibold">{{ __('Implementation') }}</button>
                    <button type="button" role="tab" x-on:click="tab = 'keys'" :aria-selected="tab === 'keys'" :class="tab === 'keys' ? 'border-brand-sage text-brand-ink' : 'border-transparent text-brand-moss'" class="-mb-px shrink-0 border-b-2 pb-2 text-xs font-semibold">{{ __('Keys') }}</button>
                    <button type="button" role="tab" x-on:click="tab = 'usage'" :aria-selected="tab === 'usage'" :class="tab === 'usage' ? 'border-brand-sage text-brand-ink' : 'border-transparent text-brand-moss'" class="-mb-px shrink-0 border-b-2 pb-2 text-xs font-semibold">{{ __('Usage') }}</button>
                    <button type="button" role="tab" x-on:click="tab = 'costs'" :aria-selected="tab === 'costs'" :class="tab === 'costs' ? 'border-brand-sage text-brand-ink' : 'border-transparent text-brand-moss'" class="-mb-px shrink-0 border-b-2 pb-2 text-xs font-semibold">{{ __('Costs') }}</button>
                    <button type="button" role="tab" x-on:click="tab = 'settings'" :aria-selected="tab === 'settings'" :class="tab === 'settings' ? 'border-brand-sage text-brand-ink' : 'border-transparent text-brand-moss'" class="-mb-px shrink-0 border-b-2 pb-2 text-xs font-semibold">{{ __('Settings') }}</button>
                </div>
                <div x-show="tab === 'how'" class="mt-4">
                    <ol class="list-decimal space-y-1 pl-4 text-xs text-brand-ink">
                        <li>{{ __('GET http://:host/ lists up to 100 keys.', ['host' => $kvHostName]) }}</li>
                        <li>{{ __('GET http://:host/key reads one value. A missing key is a 404.', ['host' => $kvHostName]) }}</li>
                        <li>{{ __('PUT http://:host/key stores the request body. DELETE removes it.', ['host' => $kvHostName]) }}</li>
                        <li>{{ __('The worker for this app receives those calls. No other app can.') }}</li>
                        <li>{{ __('This starts working after the next deploy.') }}</li>
                    </ol>
                    <p class="mt-3 text-xs text-brand-moss">{{ __('Reads are $1 per million. Writes, deletes, and lists are $10 per million. Storage is $1 per GB-month after the first 1 GB.') }}</p>
                </div>
                <div x-show="tab === 'implementation'" x-cloak class="mt-4">
                    <p class="text-xs font-semibold text-brand-ink">{{ __('Laravel') }}</p>
                    <p class="mt-1 max-w-xl text-xs text-brand-moss">{{ __('The next deploy adds dply/laravel when this app does not already have it, sets DPLY_KV_HOST, and registers a cache store named :store.', ['store' => $kvStore]) }}</p>
                    <pre class="mt-2 overflow-x-auto rounded-lg bg-brand-sand/40 p-3 text-xs text-brand-ink dark:bg-zinc-950">{{ "Cache::store('{$kvStore}')->put('session', 'hello');\nCache::store('{$kvStore}')->get('session');\nCache::store('{$kvStore}')->forget('session');" }}</pre>
                    <p class="mt-4 text-xs font-semibold text-brand-ink">{{ __('Rails') }}</p>
                    <p class="mt-1 max-w-xl text-xs text-brand-moss">{{ __('Add dply-rails. The next deploy sets DPLY_KV_HOST. Rails.cache uses this store.') }}</p>
                    <pre class="mt-2 overflow-x-auto rounded-lg bg-brand-sand/40 p-3 text-xs text-brand-ink dark:bg-zinc-950">gem "dply-rails"</pre>
                    <pre class="mt-2 overflow-x-auto rounded-lg bg-brand-sand/40 p-3 text-xs text-brand-ink dark:bg-zinc-950">{{ "Rails.cache.write('session', 'hello')\nRails.cache.read('session')\nRails.cache.delete('session')" }}</pre>
                    <p class="mt-4 text-xs font-semibold text-brand-ink">{{ __('HTTP') }}</p>
                    <p class="mt-1 max-w-xl text-xs text-brand-moss">{{ __('The same address works without either package. It is on the app after the next deploy.') }}</p>
                    <pre class="mt-2 overflow-x-auto rounded-lg bg-brand-sand/40 p-3 text-xs text-brand-ink dark:bg-zinc-950">{{ "curl -X PUT http://{$kvHostName}/session -d 'hello'\ncurl http://{$kvHostName}/session\ncurl -X DELETE http://{$kvHostName}/session" }}</pre>
                </div>
                <div x-show="tab === 'keys'" x-cloak class="mt-4">
                    <div class="flex items-center justify-between gap-3">
                        <p class="text-xs font-semibold text-brand-ink">{{ __('Keys') }}</p>
                        <button type="button" wire:click="refreshKv" class="text-xs font-semibold text-brand-ink underline">{{ __('Refresh') }}</button>
                    </div>
                    @if ($kvKeys === [])
                        <p class="mt-2 text-xs text-brand-moss">{{ __('No keys yet.') }}</p>
                    @else
                        <ul class="mt-2 divide-y divide-brand-ink/10 text-xs">
                            @foreach ($kvKeys as $key)
                                <li class="py-1.5">
                                    <button type="button" wire:click="pickKvKey({{ \Illuminate\Support\Js::from($key) }})" class="truncate font-mono text-brand-ink underline">{{ $key }}</button>
                                </li>
                            @endforeach
                        </ul>
                    @endif
                    <p class="mt-4 text-xs font-semibold text-brand-ink">{{ __('Try a key') }}</p>
                    <p class="mt-1 max-w-xl text-xs text-brand-moss">{{ __('Read and write one key in this store from here. This does not call the app.') }}</p>
                    <label class="mt-3 block text-xs text-brand-moss">
                        {{ __('Key') }}
                        <input type="text" wire:model="kvDemoKey" spellcheck="false" class="mt-1 block w-full rounded-lg border border-brand-ink/15 bg-white px-3 py-2 font-mono text-sm text-brand-ink dark:bg-zinc-900" />
                    </label>
                    <label class="mt-3 block text-xs text-brand-moss">
                        {{ __('Value') }}
                        <textarea wire:model="kvDemoValue" rows="3" class="mt-1 block w-full rounded-lg border border-brand-ink/15 bg-white px-3 py-2 font-mono text-sm text-brand-ink dark:bg-zinc-900"></textarea>
                    </label>
                    <div class="mt-2 flex flex-wrap gap-2">
                        <button type="button" wire:click="runKvDemo('write')" wire:loading.attr="disabled" wire:target="runKvDemo" class="rounded-md border border-brand-ink/15 px-3 py-1.5 text-xs font-semibold text-brand-ink disabled:opacity-50">{{ __('Write') }}</button>
                        <button type="button" wire:click="runKvDemo('read')" wire:loading.attr="disabled" wire:target="runKvDemo" class="rounded-md border border-brand-ink/15 px-3 py-1.5 text-xs font-semibold text-brand-ink disabled:opacity-50">{{ __('Read') }}</button>
                        <button type="button" wire:click="runKvDemo('delete')" wire:loading.attr="disabled" wire:target="runKvDemo" class="rounded-md border border-brand-ink/15 px-3 py-1.5 text-xs font-semibold text-brand-ink disabled:opacity-50">{{ __('Delete key') }}</button>
                    </div>
                    <x-input-error :messages="$errors->get('kvDemo')" class="mt-2" />
                    @if ($kvDemoLog !== [])
                        <ol class="mt-3 space-y-1 rounded-lg bg-brand-sand/40 p-3 font-mono text-xs text-brand-ink dark:bg-zinc-950">
                            @foreach ($kvDemoLog as $line)
                                <li>{{ $line }}</li>
                            @endforeach
                        </ol>
                    @endif
                    @if ($kvDemoPreview !== '')
                        <pre class="mt-3 max-h-40 overflow-auto rounded-lg bg-brand-sand/40 p-3 text-xs text-brand-ink dark:bg-zinc-950">{{ $kvDemoPreview }}</pre>
                    @endif
                </div>
                <div x-show="tab === 'usage'" x-cloak class="mt-4">
                    <div class="grid grid-cols-2 gap-3 sm:grid-cols-3">
                        <div>
                            <p class="text-xs text-brand-moss">{{ __('Reads this month') }}</p>
                            <p class="mt-1 text-xs font-semibold tabular-nums text-brand-ink">{{ number_format($kvReads) }}</p>
                        </div>
                        <div>
                            <p class="text-xs text-brand-moss">{{ __('Writes this month') }}</p>
                            <p class="mt-1 text-xs font-semibold tabular-nums text-brand-ink">{{ number_format($kvWrites) }}</p>
                        </div>
                        <div>
                            <p class="text-xs text-brand-moss">{{ __('Deletes this month') }}</p>
                            <p class="mt-1 text-xs font-semibold tabular-nums text-brand-ink">{{ number_format($kvDeletes) }}</p>
                        </div>
                        <div>
                            <p class="text-xs text-brand-moss">{{ __('Lists this month') }}</p>
                            <p class="mt-1 text-xs font-semibold tabular-nums text-brand-ink">{{ number_format($kvLists) }}</p>
                        </div>
                        <div>
                            <p class="text-xs text-brand-moss">{{ __('Storage') }}</p>
                            <p class="mt-1 text-xs font-semibold tabular-nums text-brand-ink">{{ $kvStorageBytes >= 1024 ** 3 ? number_format($kvStorageBytes / 1024 ** 3, 2).' GB' : ($kvStorageBytes >= 1024 ** 2 ? number_format($kvStorageBytes / 1024 ** 2, 1).' MB' : number_format($kvStorageBytes).' B') }}</p>
                        </div>
                    </div>
                    <p class="mt-3 max-w-xl text-xs text-brand-moss">{{ __('Collected through today. A list of keys counts as a list.') }}</p>
                </div>
                <div x-show="tab === 'costs'" x-cloak class="mt-4">
                    @if (is_array($kvConnection) && $kvConnection['asleep'])
                        <p class="max-w-xl text-xs text-brand-moss">{{ __('Asleep. Reads, writes, and storage are not billed until you wake this store.') }}</p>
                    @else
                        <p class="text-xs text-brand-moss">{{ __('This month') }}</p>
                        <p class="mt-1 text-xl font-semibold tabular-nums text-brand-ink">${{ number_format($kvMonthCents / 100, 2) }}</p>
                        <p class="mt-2 max-w-xl text-xs text-brand-moss">{{ __('Reads are $1 per million. Writes, deletes, and lists are $10 per million. Storage is $1 per GB-month after the first 1 GB.') }}</p>
                        @unless ($cardOnFile)
                            <p class="mt-2 max-w-xl text-xs text-brand-moss">{{ __('This counts against the usage credit until a card is on the account.') }}</p>
                        @endunless
                    @endif
                </div>
                <div x-show="tab === 'settings'" x-cloak class="mt-4 space-y-3">
                    <x-input-error :messages="$errors->get('kvSettings')" />
                    <label class="block text-xs text-brand-moss">
                        {{ __('Name') }}
                        <input type="text" wire:model="kvName" class="mt-1 block w-full rounded-lg border border-brand-ink/15 bg-white px-3 py-2 text-sm text-brand-ink dark:bg-zinc-900" />
                    </label>
                    @php $kvNext = \App\Modules\Edge\Support\EdgeContainerConnections::identity($kvName, $site); @endphp
                    @if (is_array($kvNext))
                        <p class="text-xs text-brand-moss">{{ __('The app uses http://:host/ after the next deploy.', ['host' => $kvNext['host']]) }}</p>
                    @else
                        <p class="text-xs text-brand-moss">{{ __('Name the store. Letters and numbers only, starting with a letter.') }}</p>
                    @endif
                    <button type="button" wire:click="saveKvSettings" class="rounded-md bg-brand-ink px-3 py-1.5 text-xs font-semibold text-white">{{ __('Save settings') }}</button>
                </div>
            </div>
            @endif
        </div>
    </x-modal>

    @php
        $objectConnection = collect($connections)->firstWhere('host', $objectHost);
        $objectHostName = is_array($objectConnection) ? $objectConnection['host'] : $objectHost;
        $objectBucket = is_array($objectConnection) ? (string) $objectConnection['target'] : '';
        $objectDisk = is_array($objectConnection) ? strtolower((string) $objectConnection['name']) : 'uploads';
    @endphp
    <x-modal name="resources-object" :show="$objectHost !== ''" maxWidth="3xl" focusable>
        <div class="max-h-[80vh] overflow-y-auto bg-white p-5 dark:bg-zinc-900">
            <div class="flex items-start justify-between gap-3">
                <h2 class="text-xs font-semibold uppercase tracking-[0.16em] text-brand-sage">{{ __('Object storage') }}</h2>
                <button type="button" wire:click="$set('objectHost', '')" x-on:click="$dispatch('close-modal', 'resources-object')" class="text-xs font-semibold text-brand-ink underline">{{ __('Close') }}</button>
            </div>
            <p class="mt-3 max-w-xl text-xs text-brand-moss">{{ __('This app stores files at http://:host/. The address belongs only to this app. Bucket :bucket.', ['host' => $objectHostName, 'bucket' => $objectBucket]) }}</p>
            <div class="mt-4" x-data="{ tab: 'how' }">
                <div class="flex gap-4 border-b border-brand-ink/10" role="tablist">
                    <button type="button" role="tab" x-on:click="tab = 'how'" :aria-selected="tab === 'how'" :class="tab === 'how' ? 'border-brand-sage text-brand-ink' : 'border-transparent text-brand-moss'" class="-mb-px border-b-2 pb-2 text-xs font-semibold">{{ __('How it works') }}</button>
                    <button type="button" role="tab" x-on:click="tab = 'implementation'" :aria-selected="tab === 'implementation'" :class="tab === 'implementation' ? 'border-brand-sage text-brand-ink' : 'border-transparent text-brand-moss'" class="-mb-px border-b-2 pb-2 text-xs font-semibold">{{ __('Implementation') }}</button>
                </div>
                <div x-show="tab === 'how'" class="mt-4">
                    <ol class="list-decimal space-y-1 pl-4 text-xs text-brand-ink">
                        <li>{{ __('GET http://:host/ lists objects.', ['host' => $objectHostName]) }}</li>
                        <li>{{ __('GET http://:host/path reads one file. A missing file is a 404.', ['host' => $objectHostName]) }}</li>
                        <li>{{ __('PUT http://:host/path stores the request body. DELETE removes it.', ['host' => $objectHostName]) }}</li>
                        <li>{{ __('The worker for this app receives those calls. No other app can.') }}</li>
                        <li>{{ __('The app address starts working after the next deploy. Files you add here are in the bucket now.') }}</li>
                    </ol>
                </div>
                <div x-show="tab === 'implementation'" x-cloak class="mt-4">
                    <p class="text-xs font-semibold text-brand-ink">{{ __('Laravel') }}</p>
                    <p class="mt-1 max-w-xl text-xs text-brand-moss">{{ __('The next deploy adds dply/laravel when this app does not already have it, and sets DPLY_STORAGE_HOST and FILESYSTEM_DISK to :disk. Storage::put writes here. A saved AWS key keeps your own s3 disk. The disk name is the store name.', ['disk' => $objectDisk]) }}</p>
                    <pre class="mt-2 overflow-x-auto rounded-lg bg-brand-sand/40 p-3 text-xs text-brand-ink dark:bg-zinc-950">{{ "Storage::disk('{$objectDisk}')->put('uploads/photo.jpg', \$bytes);\nStorage::disk('{$objectDisk}')->get('uploads/photo.jpg');\nStorage::disk('{$objectDisk}')->delete('uploads/photo.jpg');" }}</pre>
                    <p class="mt-4 text-xs font-semibold text-brand-ink">{{ __('Rails') }}</p>
                    <p class="mt-1 max-w-xl text-xs text-brand-moss">{{ __('Add dply-rails. The next deploy sets DPLY_STORAGE_HOST. Dply::Rails::Storage writes to this bucket.') }}</p>
                    <pre class="mt-2 overflow-x-auto rounded-lg bg-brand-sand/40 p-3 text-xs text-brand-ink dark:bg-zinc-950">gem "dply-rails"</pre>
                    <pre class="mt-2 overflow-x-auto rounded-lg bg-brand-sand/40 p-3 text-xs text-brand-ink dark:bg-zinc-950">{{ "Dply::Rails::Storage.put('uploads/photo.jpg', bytes)\nDply::Rails::Storage.get('uploads/photo.jpg')\nDply::Rails::Storage.delete('uploads/photo.jpg')" }}</pre>
                    <p class="mt-4 text-xs font-semibold text-brand-ink">{{ __('HTTP') }}</p>
                    <p class="mt-1 max-w-xl text-xs text-brand-moss">{{ __('The same address works without either package. It is on the app after the next deploy.') }}</p>
                    <pre class="mt-2 overflow-x-auto rounded-lg bg-brand-sand/40 p-3 text-xs text-brand-ink dark:bg-zinc-950">{{ "curl -X PUT http://{$objectHostName}/uploads/photo.jpg --data-binary @photo.jpg\ncurl http://{$objectHostName}/uploads/photo.jpg\ncurl -X DELETE http://{$objectHostName}/uploads/photo.jpg" }}</pre>
                </div>
            </div>
            <div class="mt-4 border-t border-brand-ink/10 pt-4">
                <div class="flex items-center justify-between gap-3">
                    <p class="text-xs font-semibold text-brand-ink">{{ __('Files') }}</p>
                    <button type="button" wire:click="refreshObjectList" class="text-xs font-semibold text-brand-ink underline">{{ __('Refresh') }}</button>
                </div>
                <x-input-error :messages="$errors->get('object')" class="mt-2" />
                @if ($objectList === [])
                    <p class="mt-2 text-xs text-brand-moss">{{ __('No files yet.') }}</p>
                @else
                    <ul class="mt-2 divide-y divide-brand-ink/10 text-xs">
                        @foreach ($objectList as $object)
                            <li class="flex items-baseline justify-between gap-3 py-1.5">
                                <button type="button" wire:click="pickObject({{ \Illuminate\Support\Js::from($object['key']) }})" class="truncate font-mono text-brand-ink underline">{{ $object['key'] }}</button>
                                <span class="shrink-0 text-brand-moss">{{ number_format($object['size']) }} {{ __('B') }}</span>
                            </li>
                        @endforeach
                    </ul>
                @endif
                <label class="mt-3 block text-xs text-brand-moss">
                    {{ __('Object name') }}
                    <input type="text" wire:model="objectKey" spellcheck="false" class="mt-1 block w-full rounded-lg border border-brand-ink/15 bg-white px-3 py-2 font-mono text-sm text-brand-ink dark:bg-zinc-900" />
                </label>
                <label class="mt-3 block text-xs text-brand-moss">
                    {{ __('Contents') }}
                    <textarea wire:model="objectBody" rows="3" class="mt-1 block w-full rounded-lg border border-brand-ink/15 bg-white px-3 py-2 font-mono text-sm text-brand-ink dark:bg-zinc-900"></textarea>
                </label>
                <div class="mt-2 flex flex-wrap gap-2">
                    <button type="button" wire:click="runObject('write')" wire:loading.attr="disabled" wire:target="runObject,refreshObjectList" class="rounded-md border border-brand-ink/15 px-3 py-1.5 text-xs font-semibold text-brand-ink disabled:opacity-50">{{ __('Upload') }}</button>
                    <button type="button" wire:click="runObject('read')" wire:loading.attr="disabled" wire:target="runObject" class="rounded-md border border-brand-ink/15 px-3 py-1.5 text-xs font-semibold text-brand-ink disabled:opacity-50">{{ __('Read') }}</button>
                    <button type="button" wire:click="runObject('delete')" wire:loading.attr="disabled" wire:target="runObject,refreshObjectList" class="rounded-md border border-brand-ink/15 px-3 py-1.5 text-xs font-semibold text-brand-ink disabled:opacity-50">{{ __('Delete file') }}</button>
                </div>
                @if ($objectLog !== [])
                    <ol class="mt-3 space-y-1 rounded-lg bg-brand-sand/40 p-3 font-mono text-xs text-brand-ink dark:bg-zinc-950">
                        @foreach ($objectLog as $line)
                            <li>{{ $line }}</li>
                        @endforeach
                    </ol>
                @endif
                @if ($objectPreview !== '')
                    <pre class="mt-3 max-h-40 overflow-auto rounded-lg bg-brand-sand/40 p-3 text-xs text-brand-ink dark:bg-zinc-950">{{ $objectPreview }}</pre>
                @endif
            </div>
        </div>
    </x-modal>

    @php
        $imagesConnection = collect($connections)->firstWhere('host', $imagesHost);
        $imagesHostName = is_array($imagesConnection) ? $imagesConnection['host'] : $imagesHost;
    @endphp
    <x-modal name="resources-images" :show="$imagesHost !== ''" maxWidth="3xl" focusable>
        <div class="max-h-[80vh] overflow-y-auto bg-white p-5 dark:bg-zinc-900">
            <div class="flex items-start justify-between gap-3">
                <h2 class="text-xs font-semibold uppercase tracking-[0.16em] text-brand-sage">{{ __('Images') }}</h2>
                <button type="button" wire:click="$set('imagesHost', '')" x-on:click="$dispatch('close-modal', 'resources-images')" class="text-xs font-semibold text-brand-ink underline">{{ __('Close') }}</button>
            </div>
            <p class="mt-3 max-w-xl text-xs text-brand-moss">{{ __('This app reads and resizes pictures at http://:host/. The address belongs only to this app. Send the image bytes. The reply is either the details or a new image.', ['host' => $imagesHostName]) }}</p>
            <div class="mt-4" x-data="{ tab: 'how' }">
                <div class="flex gap-4 border-b border-brand-ink/10" role="tablist">
                    <button type="button" role="tab" x-on:click="tab = 'how'" :aria-selected="tab === 'how'" :class="tab === 'how' ? 'border-brand-sage text-brand-ink' : 'border-transparent text-brand-moss'" class="-mb-px border-b-2 pb-2 text-xs font-semibold">{{ __('How it works') }}</button>
                    <button type="button" role="tab" x-on:click="tab = 'implementation'" :aria-selected="tab === 'implementation'" :class="tab === 'implementation' ? 'border-brand-sage text-brand-ink' : 'border-transparent text-brand-moss'" class="-mb-px border-b-2 pb-2 text-xs font-semibold">{{ __('Implementation') }}</button>
                </div>
                <div x-show="tab === 'how'" class="mt-4">
                    <ol class="list-decimal space-y-1 pl-4 text-xs text-brand-ink">
                        <li>{{ __('POST the image to http://:host/info. The reply is format, width, height, and file size.', ['host' => $imagesHostName]) }}</li>
                        <li>{{ __('POST the image to http://:host/ with width, height, fit, format, and quality. The reply is the new image.', ['host' => $imagesHostName]) }}</li>
                        <li>{{ __('Width and height are pixels, up to 8000. Fit is scale-down, contain, cover, crop, or pad.') }}</li>
                        <li>{{ __('Format is jpeg, png, webp, avif, or gif. Quality is 1 to 100. A call with no format comes back as webp.') }}</li>
                        <li>{{ __('An image can be up to 20 MB. This starts working after the next deploy.') }}</li>
                    </ol>
                </div>
                <div x-show="tab === 'implementation'" x-cloak class="mt-4">
                    @if ($site->isLaravelFrameworkDetected())
                        <p class="text-xs font-semibold text-brand-ink">{{ __('Laravel') }}</p>
                        <p class="mt-1 max-w-xl text-xs text-brand-moss">{{ __('Send the file bytes. The address is on the app after the next deploy.') }}</p>
                        <pre class="mt-2 overflow-x-auto rounded-lg bg-brand-sand/40 p-3 text-xs text-brand-ink dark:bg-zinc-950">{{ "\$bytes = file_get_contents(\$path);\n\$info = Http::withBody(\$bytes, 'application/octet-stream')->post('http://{$imagesHostName}/info')->json();\n\$image = Http::withBody(\$bytes, 'application/octet-stream')->post('http://{$imagesHostName}/?width=800&format=webp')->body();" }}</pre>
                    @elseif ($site->isRailsFrameworkDetected())
                        <p class="text-xs font-semibold text-brand-ink">{{ __('Rails') }}</p>
                        <p class="mt-1 max-w-xl text-xs text-brand-moss">{{ __('Send the file bytes. The address is on the app after the next deploy.') }}</p>
                        <pre class="mt-2 overflow-x-auto rounded-lg bg-brand-sand/40 p-3 text-xs text-brand-ink dark:bg-zinc-950">{{ "bytes = File.binread(path)\nFaraday.post('http://{$imagesHostName}/info', bytes, 'Content-Type' => 'application/octet-stream')\nFaraday.post('http://{$imagesHostName}/?width=800&format=webp', bytes, 'Content-Type' => 'application/octet-stream').body" }}</pre>
                    @endif
                    <p class="mt-4 text-xs font-semibold text-brand-ink">{{ __('HTTP') }}</p>
                    <pre class="mt-2 overflow-x-auto rounded-lg bg-brand-sand/40 p-3 text-xs text-brand-ink dark:bg-zinc-950">{{ "curl -X POST http://{$imagesHostName}/info --data-binary @photo.jpg\ncurl -X POST 'http://{$imagesHostName}/?width=800&height=600&fit=cover&format=webp&quality=80' --data-binary @photo.jpg -o photo.webp" }}</pre>
                </div>
            </div>
        </div>
    </x-modal>

    @php
        $valkeyConnection = collect($connections)->firstWhere('host', $valkeyHost);
        $valkeySpec = is_array($valkeyConnection)
            ? (\App\Modules\Edge\Support\EdgeValkey::CLASSES[$valkeyConnection['plan']] ?? \App\Modules\Edge\Support\EdgeValkey::CLASSES[\App\Modules\Edge\Support\EdgeValkey::DEFAULT_CLASS])
            : null;
        $valkeyModalSleep = is_array($valkeyConnection)
            ? (int) ($site->edgeMeta()['valkey_sleep'][$valkeyConnection['target']] ?? ($valkeySpec['sleeps'] ? \App\Modules\Edge\Support\EdgeValkey::DEFAULT_SLEEP : 0))
            : 0;
    @endphp
    <x-modal name="resources-valkey" :show="$valkeyHost !== ''" maxWidth="3xl" focusable>
        <div class="max-h-[80vh] overflow-y-auto bg-white p-5 dark:bg-zinc-900">
            <div class="flex items-start justify-between gap-3">
                <h2 class="flex items-center gap-2 text-xs font-semibold uppercase tracking-[0.16em] text-brand-sage"><x-resource-kind-icon kind="redis" />{{ __('dply Valkey') }}</h2>
                <button type="button" wire:click="$set('valkeyHost', '')" x-on:click="$dispatch('close-modal', 'resources-valkey')" class="text-xs font-semibold text-brand-ink underline">{{ __('Close') }}</button>
            </div>
            {{-- Opening sets valkeyHost on the server (and refreshes awake time from the gateway), so the body waits on that round trip. --}}
            <div wire:loading.block wire:target="valkeyHost" class="mt-4 hidden" aria-live="polite" aria-busy="true">
                <div class="flex items-center gap-2 text-xs font-medium text-brand-moss">
                    <x-spinner variant="forest" />
                    <span>{{ __('Loading this database…') }}</span>
                </div>
                <div class="mt-4 flex gap-4 border-b border-brand-ink/10 pb-2" aria-hidden="true">
                    @foreach (['w-16', 'w-14', 'w-20', 'w-10', 'w-12', 'w-14'] as $width)
                        <span class="{{ $width }} h-3 animate-pulse rounded bg-brand-ink/10"></span>
                    @endforeach
                </div>
                <div class="mt-4 grid gap-2 sm:grid-cols-2" aria-hidden="true">
                    @foreach (range(1, 4) as $placeholder)
                        <div class="rounded-lg border border-brand-ink/10 p-3">
                            <span class="block h-2 w-16 animate-pulse rounded bg-brand-ink/10"></span>
                            <span class="mt-2 block h-4 w-28 animate-pulse rounded bg-brand-ink/10"></span>
                            <span class="mt-2 block h-3 w-44 animate-pulse rounded bg-brand-ink/10"></span>
                        </div>
                    @endforeach
                </div>
            </div>
            @if (is_array($valkeyConnection) && is_array($valkeySpec))
                <div wire:loading.remove wire:target="valkeyHost">
                @php
                    $valkeyAddress = \App\Modules\Edge\Support\EdgeValkey::address($valkeyConnection['target']);
                    $valkeySpent = $connectionEstimates[$valkeyConnection['host']] ?? 0;
                    $valkeySleepLabel = $valkeyModalSleep > 0 ? __(\App\Modules\Edge\Support\EdgeValkey::SLEEPS[$valkeyModalSleep] ?? '5 minutes') : null;
                @endphp
                <p class="mt-3 max-w-xl text-xs text-brand-moss">{{ __('Redis-compatible and private to this app. :size, :sleep.', ['size' => __($valkeySpec['label']), 'sleep' => $valkeySleepLabel ? __('sleeps after :time idle', ['time' => $valkeySleepLabel]) : __('stays on')]) }}</p>
                <div class="mt-4" x-data="{ tab: 'overview' }">
                    <div class="flex gap-4 overflow-x-auto border-b border-brand-ink/10" role="tablist">
                        @foreach (['overview' => __('Overview'), 'connect' => __('Connect'), 'stats' => __('Statistics'), 'test' => __('Test'), 'costs' => __('Costs')] as $tabKey => $tabLabel)
                            <button type="button" role="tab" x-on:click="tab = '{{ $tabKey }}'; {{ $tabKey === 'stats' ? '$wire.loadValkeyStatus()' : '' }}" :aria-selected="tab === '{{ $tabKey }}'" :class="tab === '{{ $tabKey }}' ? 'border-brand-sage text-brand-ink' : 'border-transparent text-brand-moss'" class="-mb-px shrink-0 border-b-2 pb-2 text-xs font-semibold">{{ $tabLabel }}</button>
                        @endforeach
                    </div>

                    <div x-show="tab === 'overview'" class="mt-4">
                        @php
                            $awakeH = intdiv($valkeyAwakeSeconds, 3600);
                            $awakeM = intdiv($valkeyAwakeSeconds % 3600, 60);
                            $overviewCards = [
                                [__('Size'), __($valkeySpec['label']), __(':mb MB of memory for keys', ['mb' => number_format($valkeySpec['memory_mb'])])],
                                [__('Sleep'), $valkeySleepLabel ? __('After :time idle', ['time' => $valkeySleepLabel]) : __('Stays on'), $valkeySleepLabel ? __('Keys are saved and come back on the next connection, with their expiry.') : __('Keys are written to disk.')],
                                [__('Awake this month'), $awakeH > 0 ? __(':h h :m min', ['h' => $awakeH, 'm' => $awakeM]) : __(':m min', ['m' => $awakeM]), __('$:spent so far · never more than $:cap/mo', ['spent' => \App\Modules\Edge\Support\EdgeValkey::money($valkeySpent), 'cap' => number_format($valkeySpec['cap_cents'] / 100, 0)])],
                                [__('When it is full'), __('Writes are refused'), __('Nothing is evicted. Pick a larger size on the card.')],
                            ];
                        @endphp
                        <div class="mb-2 flex justify-end">
                            <button type="button" wire:click="refreshValkeyAwake" wire:loading.attr="disabled" wire:target="refreshValkeyAwake" class="inline-flex items-center gap-1.5 text-xs font-semibold text-brand-ink underline disabled:opacity-50">
                                <x-spinner size="sm" wire:loading wire:target="refreshValkeyAwake" />
                                {{ __('Refresh awake time') }}
                            </button>
                        </div>
                        <div class="grid gap-2 sm:grid-cols-2">
                            @foreach ($overviewCards as [$cardLabel, $cardValue, $cardNote])
                                <div class="rounded-lg border border-brand-ink/10 p-3">
                                    <p class="text-2xs font-semibold uppercase tracking-wide text-brand-mist">{{ $cardLabel }}</p>
                                    <p class="mt-1 text-sm font-semibold text-brand-ink">{{ $cardValue }}</p>
                                    <p class="mt-1 text-xs text-brand-moss">{{ $cardNote }}</p>
                                </div>
                            @endforeach
                        </div>
                    </div>

                    <div x-show="tab === 'connect'" class="mt-4 space-y-3">
                        <dl class="grid grid-cols-[auto_1fr] gap-x-4 gap-y-2 text-xs">
                            <dt class="text-brand-moss">{{ __('Address') }}</dt>
                            <dd class="break-all font-mono text-brand-ink">{{ $valkeyAddress }}</dd>
                            <dt class="text-brand-moss">{{ __('Username') }}</dt>
                            <dd class="font-mono text-brand-ink">default <span class="font-sans text-brand-moss">{{ __('(on this app\'s own instance)') }}</span></dd>
                            <dt class="text-brand-moss">{{ __('Password') }}</dt>
                            <dd x-data="{ password: '' }" class="flex flex-wrap items-center gap-2">
                                <span class="break-all font-mono text-brand-ink" x-text="password || '••••••••••••••••'"></span>
                                <button type="button" x-show="! password" x-on:click="password = await $wire.valkeyPassword(@js($valkeyConnection['host']))" class="text-xs font-semibold text-brand-ink underline">{{ __('Show') }}</button>
                                <button type="button" x-show="password" x-on:click="navigator.clipboard.writeText(password)" class="text-xs font-semibold text-brand-ink underline">{{ __('Copy') }}</button>
                            </dd>
                            <dt class="text-brand-moss">{{ __('Encryption') }}</dt>
                            <dd class="text-brand-ink">{{ __('TLS required (rediss://).') }}</dd>
                            <dt class="text-brand-moss">{{ __('On the app') }}</dt>
                            <dd class="text-brand-ink">{{ __('REDIS_URL is set on the next deploy. It holds the password.') }}</dd>
                        </dl>
                        <div>
                            <p class="text-xs font-semibold text-brand-ink">{{ __('Laravel') }}</p>
                            <p class="mt-1 text-xs text-brand-moss">{{ __('dply sets REDIS_URL, REDIS_CLIENT=phpredis and CACHE_STORE=redis for you. Sessions can use it too:') }}</p>
                            <pre class="mt-1 overflow-x-auto rounded-lg bg-brand-sand/40 p-3 text-xs text-brand-ink dark:bg-zinc-950">SESSION_DRIVER=redis</pre>
                        </div>
                        <div>
                            <p class="text-xs font-semibold text-brand-ink">{{ __('Node') }}</p>
                            <pre class="mt-1 overflow-x-auto rounded-lg bg-brand-sand/40 p-3 text-xs text-brand-ink dark:bg-zinc-950">{{ "import { createClient } from 'redis';\nconst redis = await createClient({ url: process.env.REDIS_URL }).connect();" }}</pre>
                        </div>
                        <div>
                            <p class="text-xs font-semibold text-brand-ink">{{ __('Rails') }}</p>
                            <pre class="mt-1 overflow-x-auto rounded-lg bg-brand-sand/40 p-3 text-xs text-brand-ink dark:bg-zinc-950">{{ "config.cache_store = :redis_cache_store, { url: ENV['REDIS_URL'] }" }}</pre>
                        </div>
                    </div>

                    <div x-show="tab === 'stats'" class="mt-4 space-y-3">
                        @if ($valkeyStatsError)
                            <p class="text-xs font-semibold text-red-700 dark:text-red-400">{{ $valkeyStatsError }}</p>
                        @endif
                        @if (is_array($valkeyStatus))
                            <dl class="grid grid-cols-[auto_1fr] gap-x-4 gap-y-2 text-xs">
                                <dt class="text-brand-moss">{{ __('State') }}</dt>
                                <dd class="font-semibold text-brand-ink">{{ ($valkeyStatus['awake'] ?? false) ? __('Awake') : __('Asleep') }}</dd>
                                @if ($valkeyStatus['awake'] ?? false)
                                    <dt class="text-brand-moss">{{ __('Idle for') }}</dt>
                                    <dd class="tabular-nums text-brand-ink">{{ __(':s s since the last connection', ['s' => (int) ($valkeyStatus['idle_seconds'] ?? 0)]) }}</dd>
                                @endif
                                <dt class="text-brand-moss">{{ __('Saved keys') }}</dt>
                                <dd class="text-brand-ink">{{ ($valkeyStatus['has_snapshot'] ?? false) ? __('A snapshot is stored and restores on wake.') : __('No snapshot yet.') }}</dd>
                            </dl>
                        @else
                            <p class="text-xs text-brand-moss" wire:loading wire:target="loadValkeyStatus">{{ __('Loading…') }}</p>
                        @endif
                        <div class="flex flex-wrap items-center gap-3">
                            <x-secondary-button type="button" wire:click="loadValkeyStats" wire:loading.attr="disabled" wire:target="loadValkeyStats">
                                <span wire:loading.remove wire:target="loadValkeyStats">{{ is_array($valkeyStats) ? __('Refresh live stats') : __('Load live stats') }}</span>
                                <span wire:loading wire:target="loadValkeyStats">{{ __('Loading…') }}</span>
                            </x-secondary-button>
                            <span class="text-xs text-brand-moss">{{ __('Connects to the database, so it wakes it if it is asleep.') }}</span>
                        </div>
                        @if (is_array($valkeyStats))
                            @php
                                $usedPct = $valkeyStats['max_memory'] > 0 ? min(100, round($valkeyStats['used_memory'] / $valkeyStats['max_memory'] * 100, 1)) : null;
                            @endphp
                            <div class="grid gap-2 sm:grid-cols-3">
                                @foreach ([
                                    [__('Keys'), number_format($valkeyStats['keys'])],
                                    [__('Memory used'), number_format($valkeyStats['used_memory'] / 1048576, 1).' MB'.($usedPct !== null ? ' · '.$usedPct.'%' : '')],
                                    [__('Hit rate'), $valkeyStats['hit_rate'] !== null ? $valkeyStats['hit_rate'].'%' : '—'],
                                    [__('Commands'), number_format($valkeyStats['commands'])],
                                    [__('Ops per second'), number_format($valkeyStats['ops_per_sec'])],
                                    [__('Clients connected'), number_format($valkeyStats['clients'])],
                                    [__('Evicted keys'), number_format($valkeyStats['evicted_keys'])],
                                    [__('Expired keys'), number_format($valkeyStats['expired_keys'])],
                                    [__('Up for'), \Carbon\CarbonInterval::seconds($valkeyStats['uptime_seconds'])->cascade()->forHumans(['short' => true, 'parts' => 2])],
                                ] as [$statLabel, $statValue])
                                    <div class="rounded-lg border border-brand-ink/10 p-2">
                                        <p class="text-2xs font-semibold uppercase tracking-wide text-brand-mist">{{ $statLabel }}</p>
                                        <p class="mt-0.5 font-mono text-sm font-semibold tabular-nums text-brand-ink">{{ $statValue }}</p>
                                    </div>
                                @endforeach
                            </div>
                            @if ($usedPct !== null)
                                <div class="h-1.5 w-full overflow-hidden rounded-full bg-brand-ink/10" role="progressbar" aria-valuenow="{{ $usedPct }}" aria-valuemin="0" aria-valuemax="100" aria-label="{{ __('Memory used') }}">
                                    <div @class(['h-full', 'bg-brand-sage' => $usedPct < 80, 'bg-amber-500' => $usedPct >= 80 && $usedPct < 95, 'bg-red-600' => $usedPct >= 95]) style="width: {{ $usedPct }}%"></div>
                                </div>
                            @endif
                            <p class="text-xs text-brand-moss">{{ __('Hits :hits · misses :misses · Valkey :version. Counts reset when it sleeps. When full it evicts keys that expire (cache entries), never queued jobs.', ['hits' => number_format($valkeyStats['hits']), 'misses' => number_format($valkeyStats['misses']), 'version' => $valkeyStats['version']]) }}</p>
                            @if (is_array($valkeySlowlog))
                                <div>
                                    <p class="text-xs font-semibold text-brand-ink">{{ __('Slowest recent commands') }}</p>
                                    @if ($valkeySlowlog === [])
                                        <p class="mt-1 text-xs text-brand-moss">{{ __('None over 10 ms since it last woke.') }}</p>
                                    @else
                                        <table class="mt-1 w-full text-left text-xs">
                                            <tbody class="divide-y divide-brand-ink/5">
                                                @foreach ($valkeySlowlog as $slow)
                                                    <tr>
                                                        <td class="py-1 pe-2 font-mono font-semibold text-brand-ink">{{ $slow['command'] }}</td>
                                                        <td class="max-w-[16rem] truncate py-1 pe-2 font-mono text-brand-moss">{{ $slow['key'] }}</td>
                                                        <td class="py-1 pe-2 text-right tabular-nums text-brand-ink">{{ number_format($slow['micros'] / 1000, 1) }} ms</td>
                                                        <td class="py-1 text-right text-brand-moss">{{ \Illuminate\Support\Carbon::createFromTimestamp($slow['at'])->diffForHumans(short: true) }}</td>
                                                    </tr>
                                                @endforeach
                                            </tbody>
                                        </table>
                                    @endif
                                </div>
                            @endif
                        @endif
                    </div>

                    <div x-show="tab === 'test'" class="mt-4 space-y-3">
                        <p class="max-w-xl text-xs text-brand-moss">{{ __('Connects the way the app does (TLS, user default, this app\'s password), wakes it if it is asleep, then writes, reads and deletes a test key and sends 10 PINGs. It runs from the dply server, so times include the trip from there to the database; an app running nearby sees less.') }}</p>
                        <div class="flex flex-wrap gap-2">
                            <x-secondary-button type="button" wire:click="testValkey" wire:loading.attr="disabled" wire:target="testValkey">
                                <span wire:loading.remove wire:target="testValkey">{{ __('Run test from dply') }}</span>
                                <span wire:loading wire:target="testValkey">{{ __('Testing…') }}</span>
                            </x-secondary-button>
                            <x-secondary-button type="button" wire:click="testValkeyFromApp" wire:loading.attr="disabled" wire:target="testValkeyFromApp">
                                <span wire:loading.remove wire:target="testValkeyFromApp">{{ __('Run test from the app') }}</span>
                                <span wire:loading wire:target="testValkeyFromApp">{{ __('Testing…') }}</span>
                            </x-secondary-button>
                        </div>
                        @if (is_array($valkeyAppTest))
                            <div class="rounded-lg border border-brand-ink/10 p-3">
                                <p @class(['text-xs font-semibold', 'text-brand-sage' => $valkeyAppTest['ok'] ?? false, 'text-red-700 dark:text-red-400' => ! ($valkeyAppTest['ok'] ?? false)])>
                                    {{ ($valkeyAppTest['ok'] ?? false) ? __('From the app: working.') : __('From the app: :error', ['error' => $valkeyAppTest['error'] ?? __('failed')]) }}
                                </p>
                                @if (($valkeyAppTest['steps'] ?? []) !== [])
                                    <table class="mt-2 w-full max-w-lg text-xs">
                                        <tbody>
                                            @foreach ($valkeyAppTest['steps'] as $step)
                                                <tr class="border-b border-brand-ink/5">
                                                    <td class="py-1 pr-3 text-brand-moss">{{ $step['step'] }}</td>
                                                    <td class="py-1 pr-3 text-right tabular-nums text-brand-ink">{{ number_format((float) $step['ms'], 1) }} ms</td>
                                                    <td class="py-1 font-mono text-brand-ink">{{ \Illuminate\Support\Str::limit((string) $step['result'], 24) }}</td>
                                                </tr>
                                            @endforeach
                                            @if (isset($valkeyAppTest['ping_median_ms']))
                                                <tr>
                                                    <td class="py-1 pr-3 text-brand-moss">{{ __('PING ×10') }}</td>
                                                    <td class="py-1 pr-3 text-right tabular-nums text-brand-ink">{{ number_format((float) $valkeyAppTest['ping_median_ms'], 1) }} ms</td>
                                                    <td class="py-1 text-brand-moss">{{ __('median · slowest :max ms', ['max' => number_format((float) $valkeyAppTest['ping_max_ms'], 1)]) }}</td>
                                                </tr>
                                            @endif
                                        </tbody>
                                    </table>
                                    <p class="mt-2 text-xs text-brand-moss">
                                        {{ __('Client :client · persistent connections :persistent', ['client' => $valkeyAppTest['client'] ?? '?', 'persistent' => ($valkeyAppTest['persistent'] ?? false) ? __('on') : __('off')]) }}{{ ($valkeyAppTest['region'] ?? '') !== '' ? ' · '.__('app runs in :region', ['region' => $valkeyAppTest['region']]) : '' }}
                                    </p>
                                @endif
                            </div>
                        @endif
                        @if (is_array($valkeyTest))
                            <p @class(['text-xs font-semibold', 'text-brand-sage' => $valkeyTest['ok'], 'text-red-700 dark:text-red-400' => ! $valkeyTest['ok']])>
                                {{ $valkeyTest['ok'] ? __('Working. Every command answered.') : __('Failed: :error', ['error' => $valkeyTest['error']]) }}
                            </p>
                            @if ($valkeyTest['steps'] !== [])
                                <table class="w-full max-w-lg text-xs">
                                    <tbody>
                                        @foreach ($valkeyTest['steps'] as $step)
                                            <tr class="border-b border-brand-ink/5">
                                                <td class="py-1 pr-3 text-brand-moss">{{ $step['step'] }}</td>
                                                <td class="py-1 pr-3 text-right tabular-nums text-brand-ink">{{ number_format($step['ms'], 1) }} ms</td>
                                                <td @class(['py-1 font-mono', 'text-brand-ink' => $step['result'] !== 'failed', 'text-red-700 dark:text-red-400' => $step['result'] === 'failed'])>{{ \Illuminate\Support\Str::limit($step['result'], 24) }}</td>
                                            </tr>
                                        @endforeach
                                        @if ($valkeyTest['ping_median_ms'] !== null)
                                            <tr>
                                                <td class="py-1 pr-3 text-brand-moss">{{ __('PING ×10') }}</td>
                                                <td class="py-1 pr-3 text-right tabular-nums text-brand-ink">{{ number_format($valkeyTest['ping_median_ms'], 1) }} ms</td>
                                                <td class="py-1 text-brand-moss">{{ __('median · slowest :max ms', ['max' => number_format($valkeyTest['ping_max_ms'], 1)]) }}</td>
                                            </tr>
                                        @endif
                                    </tbody>
                                </table>
                            @endif
                        @endif
                    </div>

                    <div x-show="tab === 'costs'" class="mt-4">
                        <dl class="grid grid-cols-[auto_1fr] gap-x-4 gap-y-2 text-xs">
                            <dt class="text-brand-moss">{{ __('So far this month') }}</dt>
                            <dd class="font-semibold tabular-nums text-brand-ink">{{ '$'.\App\Modules\Edge\Support\EdgeValkey::money($valkeySpent) }}</dd>
                            <dt class="text-brand-moss">{{ __('Rate') }}</dt>
                            <dd class="tabular-nums text-brand-ink">{{ __('$:hour per hour while awake', ['hour' => number_format($valkeySpec['per_second'] * 3600, 4)]) }}</dd>
                            <dt class="text-brand-moss">{{ __('Monthly cap') }}</dt>
                            <dd class="tabular-nums text-brand-ink">{{ __('$:cap. Never more than this, even if it never sleeps.', ['cap' => number_format($valkeySpec['cap_cents'] / 100, 0)]) }}</dd>
                            <dt class="text-brand-moss">{{ __('Asleep') }}</dt>
                            <dd class="text-brand-ink">{{ $valkeySpec['sleeps'] ? __('Not billed.') : __('Pro sizes do not sleep.') }}</dd>
                        </dl>
                        <div class="mt-3 flex flex-wrap items-center gap-3">
                            <button type="button" wire:click="refreshValkeyAwake" wire:loading.attr="disabled" wire:target="refreshValkeyAwake" class="inline-flex items-center gap-1.5 text-xs font-semibold text-brand-ink underline disabled:opacity-50">
                                <x-spinner size="sm" wire:loading wire:target="refreshValkeyAwake" />
                                {{ __('Refresh awake time') }}
                            </button>
                            <span class="text-xs text-brand-moss">{{ __('Shown exactly. The invoice rounds the month\'s total once, to the nearest cent.') }}</span>
                        </div>
                    </div>

                </div>
                </div>
            @endif
        </div>
    </x-modal>

    @if ($showBrowser)
        <x-modal name="resources-browser" :show="$panel === 'browser'" maxWidth="3xl" focusable>
            <div class="max-h-[80vh] overflow-y-auto bg-white p-5 dark:bg-zinc-900">
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <h2 class="text-xs font-semibold uppercase tracking-[0.16em] text-brand-sage">{{ __('Browser') }}</h2>
                    @if ($browserOn)
                        <div class="flex items-center gap-3">
                            <span class="rounded-full bg-emerald-100 px-2 py-0.5 text-xs font-semibold text-emerald-800 dark:bg-emerald-950/40 dark:text-emerald-300">{{ __('On') }}</span>
                            @unless ($browserDeployed)
                                <button type="button" wire:click="redeployEdge" class="rounded-md bg-brand-ink px-3 py-1.5 text-xs font-semibold text-white">{{ __('Deploy') }}</button>
                            @endunless
                            <button type="button" wire:click="askRemoveBrowser" x-on:click="$dispatch('open-modal', 'resources-remove-browser')" class="text-xs font-semibold text-brand-ink underline">{{ __('Remove') }}</button>
                        </div>
                    @else
                        <button type="button" wire:click="enableBrowser" class="rounded-md bg-brand-ink px-3 py-1.5 text-xs font-semibold text-white">{{ __('Turn on') }}</button>
                    @endif
                </div>
                <p class="mt-3 max-w-xl text-xs text-brand-moss">{{ __('This app gets its own browser at an address starting with dply, so it does not clash with a name the app already uses. Another app gets a different address.') }}</p>
                @if ($browserOn && $isWorker)
                    <p class="mt-4 max-w-xl text-xs text-brand-moss">{{ __('Your code reads it as env.BROWSER. Use it with @cloudflare/puppeteer: puppeteer.launch(env.BROWSER). It is added on the next deploy.') }}</p>
                @elseif ($browserOn)
                    <div class="mt-4" x-data="{ tab: 'how' }">
                        <div class="flex gap-4 border-b border-brand-ink/10" role="tablist">
                            <button type="button" role="tab" x-on:click="tab = 'how'" :aria-selected="tab === 'how'" :class="tab === 'how' ? 'border-brand-sage text-brand-ink' : 'border-transparent text-brand-moss'" class="-mb-px border-b-2 pb-2 text-xs font-semibold">{{ __('How it works') }}</button>
                            <button type="button" role="tab" x-on:click="tab = 'implementation'" :aria-selected="tab === 'implementation'" :class="tab === 'implementation' ? 'border-brand-sage text-brand-ink' : 'border-transparent text-brand-moss'" class="-mb-px border-b-2 pb-2 text-xs font-semibold">{{ __('Implementation') }}</button>
                        </div>
                        <div x-show="tab === 'how'" class="mt-4">
                            <p class="max-w-xl text-xs text-brand-moss">{{ __('Post {"url":"https://example.com"}. /content returns the page, /screenshot returns a PNG, /pdf returns a PDF.') }}</p>
                            <ul class="mt-2 space-y-1 font-mono text-xs text-brand-moss">
                                <li>http://{{ $browserHost }}/content</li>
                                <li>http://{{ $browserHost }}/screenshot</li>
                                <li>http://{{ $browserHost }}/pdf</li>
                            </ul>
                            @unless ($browserDeployed)
                                <p class="mt-2 text-xs font-semibold text-brand-ink">{{ __('Deploy this app before those calls work. Only this app can use this address.') }}</p>
                            @endunless
                        </div>
                        <div x-show="tab === 'implementation'" x-cloak class="mt-4">
                            <p class="text-xs font-semibold text-brand-ink">{{ __('Laravel') }}</p>
                            <p class="mt-1 max-w-xl text-xs text-brand-moss">{{ __('Call this address from the app. It is available after the next deploy.') }}</p>
                            <pre class="mt-2 overflow-x-auto rounded-lg bg-brand-sand/40 p-3 text-xs text-brand-ink dark:bg-zinc-950">{{ "Http::post('http://{$browserHost}/screenshot', [\n    'url' => 'https://example.com',\n]);" }}</pre>
                            <p class="mt-4 text-xs font-semibold text-brand-ink">{{ __('Rails') }}</p>
                            <p class="mt-1 max-w-xl text-xs text-brand-moss">{{ __('Call this address from the app. It is available after the next deploy.') }}</p>
                            <pre class="mt-2 overflow-x-auto rounded-lg bg-brand-sand/40 p-3 text-xs text-brand-ink dark:bg-zinc-950">{{ "Faraday.post('http://{$browserHost}/screenshot', { url: 'https://example.com' }.to_json, 'Content-Type' => 'application/json')" }}</pre>
                            <p class="mt-4 text-xs font-semibold text-brand-ink">{{ __('HTTP') }}</p>
                            <pre class="mt-2 overflow-x-auto rounded-lg bg-brand-sand/40 p-3 text-xs text-brand-ink dark:bg-zinc-950">{{ "curl -X POST http://{$browserHost}/screenshot \\\n  -H 'content-type: application/json' \\\n  -d '{\"url\":\"https://example.com\"}'" }}</pre>
                        </div>
                    </div>
                @endif
                <div class="mt-4 border-t border-brand-ink/10 pt-4">
                    <p class="text-xs font-semibold text-brand-ink">{{ __('Demo') }}</p>
                    <p class="mt-1 max-w-xl text-xs text-brand-moss">{{ __('Open a public page here and see the same three results. This does not change the app.') }}</p>
                    <label class="mt-3 block text-xs text-brand-moss">
                        {{ __('Page address') }}
                        <input type="url" wire:model="browserDemoUrl" class="mt-1 block w-full rounded-lg border border-brand-ink/15 bg-white px-3 py-2 text-sm text-brand-ink dark:bg-zinc-900" />
                    </label>
                    <div class="mt-2 flex flex-wrap gap-2">
                        <button type="button" wire:click="runBrowserDemo('content')" wire:loading.attr="disabled" wire:target="runBrowserDemo" class="rounded-md border border-brand-ink/15 px-3 py-1.5 text-xs font-semibold text-brand-ink disabled:opacity-50">{{ __('Show page') }}</button>
                        <button type="button" wire:click="runBrowserDemo('screenshot')" wire:loading.attr="disabled" wire:target="runBrowserDemo" class="rounded-md border border-brand-ink/15 px-3 py-1.5 text-xs font-semibold text-brand-ink disabled:opacity-50">{{ __('Show picture') }}</button>
                        <button type="button" wire:click="runBrowserDemo('pdf')" wire:loading.attr="disabled" wire:target="runBrowserDemo" class="rounded-md border border-brand-ink/15 px-3 py-1.5 text-xs font-semibold text-brand-ink disabled:opacity-50">{{ __('Show PDF') }}</button>
                    </div>
                    <p wire:loading wire:target="runBrowserDemo" class="mt-2 text-xs text-brand-moss">{{ __('Opening the page…') }}</p>
                    @if ($browserDemoLog !== [])
                        <ol class="mt-3 space-y-1 rounded-lg bg-brand-sand/40 p-3 font-mono text-xs text-brand-ink dark:bg-zinc-950">
                            @foreach ($browserDemoLog as $line)
                                <li>{{ $line }}</li>
                            @endforeach
                        </ol>
                    @elseif ($browserOn)
                        <ol class="mt-3 space-y-1 rounded-lg bg-brand-sand/40 p-3 font-mono text-xs text-brand-ink dark:bg-zinc-950">
                            <li>{{ __('App code posts {"url":"https://example.com"} to http://:host/screenshot.', ['host' => $browserHost]) }}</li>
                            <li>{{ __('The worker for this app receives that call. No other app can.') }}</li>
                            <li>{{ __('The worker opens the page and returns a PNG, the HTML, or a PDF to the app.') }}</li>
                            <li>{{ __('This demo does the same open from here. It does not call the app.') }}</li>
                        </ol>
                    @endif
                    <x-input-error :messages="$errors->get('browserDemo')" class="mt-2" />
                    @if ($browserDemoKind === 'content' && $browserDemoPreview !== '')
                        <pre class="mt-3 max-h-48 overflow-auto rounded-lg bg-brand-sand/40 p-3 text-xs text-brand-ink dark:bg-zinc-950">{{ $browserDemoPreview }}</pre>
                    @elseif ($browserDemoKind === 'screenshot' && $browserDemoPreview !== '')
                        <img src="data:image/png;base64,{{ $browserDemoPreview }}" alt="{{ __('Picture of the page') }}" class="mt-3 max-h-64 max-w-full rounded-lg border border-brand-ink/10" />
                    @elseif ($browserDemoKind === 'pdf' && $browserDemoPreview !== '')
                        <a href="data:application/pdf;base64,{{ $browserDemoPreview }}" download="page.pdf" class="mt-3 inline-block text-xs font-semibold text-brand-ink underline">{{ __('Download PDF') }}</a>
                    @endif
                </div>
            </div>
        </x-modal>
    @endif

    <x-modal name="resources-remove-browser" :show="$confirmRemoveBrowser" maxWidth="md" focusable>
        <div class="space-y-4 bg-white p-5 dark:bg-zinc-900">
            <h2 class="text-sm font-semibold text-brand-ink">{{ __('Remove the browser?') }}</h2>
            <p class="text-xs text-brand-moss">{{ __('The app will stop being able to open pages, save pictures, and save PDFs after the next deploy.') }}</p>
            <div class="flex justify-end gap-2">
                <button type="button" wire:click="$set('confirmRemoveBrowser', false)" x-on:click="$dispatch('close-modal', 'resources-remove-browser')" class="rounded-md border border-brand-ink/15 px-3 py-1.5 text-xs font-semibold text-brand-ink">{{ __('Cancel') }}</button>
                <button type="button" wire:click="removeBrowser" class="rounded-md bg-red-700 px-3 py-1.5 text-xs font-semibold text-white">{{ __('Remove browser') }}</button>
            </div>
        </div>
    </x-modal>

    <x-modal name="resources-delete-connection" :show="$panel === 'delete-connection'" maxWidth="md" focusable>
        <div class="space-y-4 bg-white p-5 dark:bg-zinc-900">
            <h2 class="text-sm font-semibold text-brand-ink">{{ __('Delete this resource?') }}</h2>
            <p class="text-xs text-brand-moss">{{ __('This destroys it, not just the link on this app. A key-value store, bucket, database, or queue is removed. Redis removes REDIS_URL. A Redis started here is deleted. A pasted address is left where it is. Anything else is only detached. This cannot be undone.') }}</p>
            <x-input-error :messages="$errors->get('connectionDelete')" />
            <div class="flex justify-end gap-2">
                <button type="button" wire:click="$set('panel', '')" x-on:click="$dispatch('close-modal', 'resources-delete-connection')" class="rounded-md border border-brand-ink/15 px-3 py-1.5 text-xs font-semibold text-brand-ink">{{ __('Cancel') }}</button>
                <button type="button" wire:click="deleteConnection" class="rounded-md bg-red-700 px-3 py-1.5 text-xs font-semibold text-white">{{ __('Delete resource') }}</button>
            </div>
        </div>
    </x-modal>

    <x-modal name="resources-worker-logs" :show="$panel === 'worker-logs'" maxWidth="4xl" focusable>
        <div class="space-y-4 p-6">
            <div class="flex items-start justify-between gap-4">
                <div>
                    <h2 class="text-sm font-semibold text-brand-ink">{{ __('Worker logs') }}</h2>
                    <p class="mt-1 text-xs text-brand-moss">{{ __('The last hour of queue worker output, newest first: each job as it runs and finishes, and each worker starting, restarting and stopping. Logs reach here about a minute after they happen.') }}</p>
                </div>
                <button type="button" class="text-xs font-semibold text-brand-ink underline" x-on:click="$dispatch('close-modal', 'resources-worker-logs')" wire:click="openPanel('')">{{ __('Close') }}</button>
            </div>
            <div class="flex items-center gap-2 text-xs">
                <button type="button" wire:click="loadWorkerLogs" wire:loading.attr="disabled" wire:target="loadWorkerLogs,openWorkerLogs" class="rounded-md border border-brand-ink/15 px-2.5 py-1.5 font-semibold text-brand-ink disabled:opacity-50">{{ __('Refresh') }}</button>
                <span wire:loading wire:target="loadWorkerLogs,openWorkerLogs" class="text-brand-moss">{{ __('Loading…') }}</span>
            </div>
            @if ($workerLogsError)
                <p class="text-xs text-red-700 dark:text-red-400">{{ $workerLogsError }}</p>
            @elseif (is_array($workerLogs))
                @if ($workerLogs === [])
                    <p class="rounded-lg border border-dashed border-brand-ink/15 px-4 py-6 text-center text-xs text-brand-moss">{{ __('No worker output in the last hour.') }}</p>
                @else
                    <ol class="max-h-[28rem] overflow-auto rounded-lg bg-zinc-950 p-3 font-mono text-2xs leading-relaxed text-zinc-200">
                        @foreach ($workerLogs as $line)
                            <li @class(['whitespace-pre-wrap break-words', 'text-red-400' => str_ends_with(rtrim($line['message']), 'FAIL') || $line['level'] === 'error', 'text-emerald-400' => str_ends_with(rtrim($line['message']), 'DONE'), 'text-sky-300' => str_starts_with($line['message'], '[dply-worker')])><span class="text-zinc-500">{{ $line['at'] ? \Illuminate\Support\Carbon::parse($line['at'])->format('H:i:s') : '' }}</span> {{ $line['message'] }}</li>
                        @endforeach
                    </ol>
                @endif
            @endif
        </div>
    </x-modal>

    <x-modal name="resources-failed-jobs" :show="$panel === 'failed-jobs'" maxWidth="3xl" focusable>
        <div class="space-y-4 p-6">
            <div class="flex items-start justify-between gap-4">
                <div>
                    <h2 class="text-sm font-semibold text-brand-ink">{{ __('Failed jobs') }}</h2>
                    <p class="mt-1 text-xs text-brand-moss">{{ __('From the app\'s own failed-job store. Retry puts a job back on its queue for the workers; Delete removes it for good.') }}</p>
                </div>
                <button type="button" class="text-xs font-semibold text-brand-ink underline" x-on:click="$dispatch('close-modal', 'resources-failed-jobs')" wire:click="openPanel('')">{{ __('Close') }}</button>
            </div>
            <div class="flex flex-wrap items-center gap-2 text-xs">
                <button type="button" wire:click="loadFailedJobs" wire:loading.attr="disabled" wire:target="loadFailedJobs,retryFailedJobs,forgetFailedJob,flushFailedJobs" class="rounded-md border border-brand-ink/15 px-2.5 py-1.5 font-semibold text-brand-ink disabled:opacity-50">{{ __('Refresh') }}</button>
                @if (($failedJobs['total'] ?? 0) > 0)
                    <button type="button" wire:click="retryFailedJobs" wire:loading.attr="disabled" wire:target="loadFailedJobs,retryFailedJobs,forgetFailedJob,flushFailedJobs" class="rounded-md border border-brand-ink/15 px-2.5 py-1.5 font-semibold text-brand-ink disabled:opacity-50">{{ __('Retry all') }}</button>
                    <button type="button" wire:click="flushFailedJobs" wire:loading.attr="disabled" wire:target="loadFailedJobs,retryFailedJobs,forgetFailedJob,flushFailedJobs" @class(['rounded-md border px-2.5 py-1.5 font-semibold disabled:opacity-50', 'border-red-600 bg-red-600 text-white' => $confirmFlushFailed, 'border-brand-ink/15 text-red-700 dark:text-red-400' => ! $confirmFlushFailed])>{{ $confirmFlushFailed ? __('Delete all :count? Click again', ['count' => $failedJobs['total']]) : __('Delete all') }}</button>
                @endif
                <span wire:loading wire:target="loadFailedJobs,retryFailedJobs,forgetFailedJob,flushFailedJobs,openFailedJobs" class="text-brand-moss">{{ __('Asking the app…') }}</span>
            </div>
            @if ($failedJobsNotice)
                <p class="text-xs font-semibold text-emerald-700 dark:text-emerald-400">{{ $failedJobsNotice }}</p>
            @endif
            @if ($failedJobsError)
                <p class="text-xs text-red-700 dark:text-red-400">{{ $failedJobsError }}</p>
            @elseif (is_array($failedJobs))
                @if ($failedJobs['jobs'] === [])
                    <p class="rounded-lg border border-dashed border-brand-ink/15 px-4 py-6 text-center text-xs text-brand-moss">{{ __('No failed jobs.') }}</p>
                @else
                    @if ($failedJobs['total'] > count($failedJobs['jobs']))
                        <p class="text-xs text-brand-moss">{{ __('Showing the newest :shown of :total.', ['shown' => count($failedJobs['jobs']), 'total' => $failedJobs['total']]) }}</p>
                    @endif
                    <ul class="divide-y divide-brand-ink/10 rounded-lg border border-brand-ink/10 text-xs">
                        @foreach ($failedJobs['jobs'] as $job)
                            <li class="space-y-1 px-3 py-2.5" wire:key="failed-job-{{ $job['id'] }}">
                                <div class="flex flex-wrap items-center justify-between gap-2">
                                    <p class="min-w-0 truncate font-mono font-semibold text-brand-ink">{{ $job['name'] ?: __('Unknown job') }}</p>
                                    <div class="flex shrink-0 gap-3">
                                        <button type="button" wire:click="retryFailedJobs('{{ $job['id'] }}')" wire:loading.attr="disabled" class="font-semibold text-brand-ink underline">{{ __('Retry') }}</button>
                                        <button type="button" wire:click="forgetFailedJob('{{ $job['id'] }}')" wire:loading.attr="disabled" class="font-semibold text-red-700 underline dark:text-red-400">{{ __('Delete') }}</button>
                                    </div>
                                </div>
                                <p class="text-brand-moss">{{ implode(' · ', array_filter([$job['queue'] ?? '', $job['connection'] ?? '', ($job['failed_at'] ?? '') !== '' ? \Illuminate\Support\Carbon::parse($job['failed_at'])->diffForHumans() : '', ($job['attempts'] ?? 0) > 0 ? trans_choice(':count attempt|:count attempts', $job['attempts']) : ''])) }}</p>
                                <p class="break-words text-red-700 dark:text-red-400">{{ $job['error'] }}</p>
                                <details>
                                    <summary class="cursor-pointer text-brand-moss">{{ __('Stack trace') }}</summary>
                                    <pre class="mt-1 max-h-64 overflow-auto whitespace-pre-wrap rounded bg-brand-ink/5 p-2 font-mono text-2xs text-brand-ink">{{ $job['trace'] }}</pre>
                                </details>
                            </li>
                        @endforeach
                    </ul>
                @endif
            @endif
        </div>
    </x-modal>

    <x-modal name="resources-connection" :show="$panel === 'connection'" maxWidth="lg" focusable>
        <div class="space-y-4 bg-white p-5 dark:bg-zinc-900">
            @if ($connectionKind === '')
                <div>
                    <h2 class="text-sm font-semibold text-brand-ink">{{ __('Add a resource') }}</h2>
                </div>
                <div class="grid gap-2 sm:grid-cols-3">
                    @if ($isContainer && ! $scheduler && $site->isLaravelFrameworkDetected())
                    <button type="button" wire:click="addScheduler" x-on:click="$dispatch('close-modal', 'resources-connection')" class="flex items-center gap-2 rounded-xl border border-brand-ink/10 px-3 py-2 text-left text-xs font-semibold text-brand-ink hover:border-brand-sage">
                        <x-heroicon-o-clock class="h-4 w-4 shrink-0" />
                        {{ __('Scheduler') }}
                    </button>
                    @endif
                    @if ($isContainer && ! ($workers['enabled'] ?? false))
                    <button type="button" wire:click="addWorkers" x-on:click="$dispatch('close-modal', 'resources-connection')" class="flex items-center gap-2 rounded-xl border border-brand-ink/10 px-3 py-2 text-left text-xs font-semibold text-brand-ink hover:border-brand-sage">
                        <x-resource-kind-icon kind="queue" />
                        {{ __('Queue workers') }}
                    </button>
                    @endif
                    @unless ($databaseVisible)
                    <button type="button" wire:click="addDatabase" x-on:click="$dispatch('close-modal', 'resources-connection')" class="flex items-center gap-2 rounded-xl border border-brand-ink/10 px-3 py-2 text-left text-xs font-semibold text-brand-ink hover:border-brand-sage">
                        <x-resource-kind-icon kind="database" />
                        {{ __('Database') }}
                    </button>
                    @endunless
                    @foreach ($connectionKinds as $key => $kind)
                        @continue($key === 'http_delivery' || ! in_array($key, $allowedKinds, true))
                        @continue($key === 'redis' && collect($connections)->contains('kind', 'redis'))
                        <button type="button" wire:click="chooseConnectionKind('{{ $key }}')" class="flex items-center gap-2 rounded-xl border border-brand-ink/10 px-3 py-2 text-left text-xs font-semibold text-brand-ink hover:border-brand-sage">
                            <x-resource-kind-icon :kind="$key" />
                            {{ __($kind['label']) }}
                        </button>
                    @endforeach
                </div>
            @else
                <div>
                    <button type="button" wire:click="$set('connectionKind', '')" class="text-xs font-semibold text-brand-ink underline">{{ __('All types') }}</button>
                    <h2 class="mt-2 flex items-center gap-2 text-sm font-semibold text-brand-ink">
                        <x-resource-kind-icon :kind="$connectionKind" />
                        {{ __($connectionKinds[$connectionKind]['label']) }}
                    </h2>
                    @if ($isWorker)
                        <p class="mt-1 text-xs text-brand-moss">{{ __(\App\Modules\Edge\Support\EdgeContainerConnections::WORKER_HINTS[$connectionKind] ?? 'Your code reads it as env.NAME, where NAME is the name you give it here.') }}</p>
                    @else
                        <p class="mt-1 text-xs text-brand-moss">{{ __($connectionKinds[$connectionKind]['hint']) }}</p>
                    @endif
                </div>
                @if ($connectionKind === 'queue' && $queueStyle === '')
                    <div class="grid grid-cols-2 gap-2">
                        <button type="button" wire:click="$set('queueStyle', 'jobs')" class="rounded-lg border border-brand-ink/10 px-3 py-2 text-left text-xs font-semibold text-brand-ink">{{ __('Jobs') }}<span class="mt-1 block font-normal text-brand-moss">{{ __('Run in this app.') }}</span></button>
                        <button type="button" wire:click="chooseConnectionKind('http_delivery')" class="rounded-lg border border-brand-ink/10 px-3 py-2 text-left text-xs font-semibold text-brand-ink">{{ __('HTTP delivery') }}<span class="mt-1 block font-normal text-brand-moss">{{ __('Call a URL later.') }}</span></button>
                    </div>
                @else
                @if (in_array($connectionKind, \App\Modules\Edge\Support\EdgeContainerConnections::CREATABLE, true) || $connectionKind === 'redis')
                    <div class="grid grid-cols-2 gap-2">
                        <button type="button" wire:click="setConnectionMode('create')" @class(['rounded-lg border px-3 py-2 text-xs font-semibold', 'border-brand-sage bg-brand-sage/10 text-brand-ink' => $connectionMode === 'create', 'border-brand-ink/10 text-brand-ink' => $connectionMode !== 'create'])>{{ __('Create new') }}</button>
                        <button type="button" wire:click="setConnectionMode('attach')" @class(['rounded-lg border px-3 py-2 text-xs font-semibold', 'border-brand-sage bg-brand-sage/10 text-brand-ink' => $connectionMode === 'attach', 'border-brand-ink/10 text-brand-ink' => $connectionMode !== 'attach'])>{{ __('Attach existing') }}</button>
                    </div>
                @endif
                <form wire:submit="saveConnection" class="space-y-3">
                    @if ($connectionMode === 'create' && $connectionKind === 'key_value' && ! $cardOnFile)
                        <p class="text-xs text-brand-moss">{{ __('Add a card before starting a key-value store. Reads, writes, and storage are billed to that card.') }}</p>
                        @if ($site->organization)
                            <a href="{{ route('billing.show', $site->organization) }}" class="text-xs font-semibold text-brand-ink underline">{{ __('Billing') }}</a>
                        @endif
                    @elseif ($connectionMode === 'create' && in_array($connectionKind, \App\Modules\Edge\Support\EdgeContainerConnections::CREATABLE, true))
                        <label class="block text-xs text-brand-moss">
                            {{ __('Name') }}
                            <input type="text" wire:model="connectionLabel" placeholder="{{ __('Uploads') }}" class="mt-1 block w-full rounded-lg border border-brand-ink/15 bg-white px-3 py-2 text-sm text-brand-ink dark:bg-zinc-900" />
                        </label>
                        <p class="text-xs text-brand-moss">{{ __('This creates the resource and gives the app a private host on the next deploy.') }}</p>
                    @elseif (in_array($connectionKind, \App\Modules\Edge\Support\EdgeContainerConnections::CREATABLE, true))
                        <label class="block text-xs text-brand-moss">
                            {{ __('Existing :type', ['type' => __(\App\Modules\Edge\Support\EdgeContainerConnections::targetLabel($connectionKind))]) }}
                            <select wire:model="connectionPick" class="mt-1 block w-full rounded-lg border border-brand-ink/15 bg-white px-3 py-2 text-sm text-brand-ink dark:bg-zinc-900">
                                <option value="">{{ __('Choose one') }}</option>
                                @foreach ($connectionOptions as $option)
                                    <option value="{{ $option['id'] }}">{{ $option['label'] }}</option>
                                @endforeach
                            </select>
                        </label>
                    @elseif ($connectionKind === 'http_delivery' && ! $cardOnFile)
                        <p class="text-xs text-brand-moss">{{ __('Add a card before starting HTTP delivery. Messages are billed to that card.') }}</p>
                        @if ($site->organization)
                            <a href="{{ route('billing.show', $site->organization) }}" class="text-xs font-semibold text-brand-ink underline">{{ __('Billing') }}</a>
                        @endif
                    @elseif ($connectionKind === 'http_delivery')
                        <label class="block text-xs text-brand-moss">
                            {{ __('Name') }}
                            <input type="text" wire:model="connectionLabel" placeholder="{{ __('Hooks') }}" class="mt-1 block w-full rounded-lg border border-brand-ink/15 bg-white px-3 py-2 text-sm text-brand-ink dark:bg-zinc-900" />
                        </label>
                        <p class="text-xs text-brand-moss">{{ __('Messages are $2 per 100,000. Bandwidth is $0.10 per GB after the first 1 GB. The next deploy gives the app a private host.') }}</p>
                    @elseif ($connectionKind === 'redis' && $connectionMode === 'create' && ! $cardOnFile)
                        <p class="text-xs text-brand-moss">{{ __('Add a card before starting dply Valkey. Usage is billed to that card.') }}</p>
                        @if ($site->organization)
                            <a href="{{ route('billing.show', $site->organization) }}" class="text-xs font-semibold text-brand-ink underline">{{ __('Billing') }}</a>
                        @endif
                    @elseif ($connectionKind === 'redis' && $connectionMode === 'create')
                        <label class="block text-xs text-brand-moss">
                            {{ __('Name') }}
                            <input type="text" wire:model="connectionLabel" placeholder="{{ __('Cache') }}" class="mt-1 block w-full rounded-lg border border-brand-ink/15 bg-white px-3 py-2 text-sm text-brand-ink dark:bg-zinc-900" />
                        </label>
                        <label class="block text-xs text-brand-moss">
                            {{ __('Size') }}
                            <select wire:model.live="valkeyClass" class="mt-1 block w-full rounded-lg border border-brand-ink/15 bg-white px-3 py-2 text-sm text-brand-ink dark:bg-zinc-900">
                                @foreach (\App\Modules\Edge\Support\EdgeValkey::offered() as $id => $class)
                                    <option value="{{ $id }}">{{ __($class['label']) }} · {{ __('up to $:price/mo', ['price' => number_format($class['cap_cents'] / 100, 0)]) }}</option>
                                @endforeach
                            </select>
                        </label>
                        @if (\App\Modules\Edge\Support\EdgeValkey::CLASSES[$valkeyClass]['sleeps'] ?? false)
                            <label class="block text-xs text-brand-moss">
                                {{ __('Sleep when idle for') }}
                                <select wire:model="valkeySleep" class="mt-1 block w-full rounded-lg border border-brand-ink/15 bg-white px-3 py-2 text-sm text-brand-ink dark:bg-zinc-900">
                                    @foreach (\App\Modules\Edge\Support\EdgeValkey::SLEEPS as $seconds => $label)
                                        <option value="{{ $seconds }}">{{ __($label) }}</option>
                                    @endforeach
                                </select>
                            </label>
                            <p class="text-xs text-brand-moss">{{ __('Billed per second while awake, up to the monthly price. Asleep, it is not billed. Its data is saved and comes back on the next connection, which takes a few seconds.') }}</p>
                        @else
                            <p class="text-xs text-brand-moss">{{ __('Stays on and writes every change to disk. Billed per second, up to the monthly price.') }}</p>
                        @endif
                        <p class="text-xs text-brand-moss">{{ __('The address is set as REDIS_URL on the next deploy and is not shown.') }}</p>
                    @elseif ($connectionKind === 'redis')
                        <label class="block text-xs text-brand-moss">
                            {{ __('Name') }}
                            <input type="text" wire:model="connectionLabel" placeholder="{{ __('Cache') }}" class="mt-1 block w-full rounded-lg border border-brand-ink/15 bg-white px-3 py-2 text-sm text-brand-ink dark:bg-zinc-900" />
                        </label>
                        <label class="block text-xs text-brand-moss">
                            {{ __('Address') }}
                            <input type="password" wire:model="connectionPick" autocomplete="off" spellcheck="false" placeholder="rediss://default:secret@cache.example:6379" class="mt-1 block w-full rounded-lg border border-brand-ink/15 bg-white px-3 py-2 text-sm text-brand-ink dark:bg-zinc-900" />
                        </label>
                        <p class="text-xs text-brand-moss">{{ __('The app connects straight to this address. The address is encrypted and is not shown again. A pasted address is not billed here.') }}</p>
                        @if ($site->isLaravelFrameworkDetected())
                            <p class="text-xs text-brand-moss">{{ __('The next deploy also sets CACHE_STORE=redis unless that is already set.') }}</p>
                        @endif
                    @elseif ($connectionKind === 'durable_object')
                        <label class="block text-xs text-brand-moss">
                            {{ __('Name') }}
                            <input type="text" wire:model="connectionLabel" placeholder="{{ __('Visits') }}" class="mt-1 block w-full rounded-lg border border-brand-ink/15 bg-white px-3 py-2 text-sm text-brand-ink dark:bg-zinc-900" />
                        </label>
                        <p class="text-xs text-brand-moss">{{ __('One object keeps these keys. Counters and locks stay exact. It applies on the next deploy.') }}</p>
                    @elseif ($connectionKind === 'service')
                        <label class="block text-xs text-brand-moss">
                            {{ __('Name') }}
                            <input type="text" wire:model="connectionLabel" placeholder="{{ __('Billing') }}" class="mt-1 block w-full rounded-lg border border-brand-ink/15 bg-white px-3 py-2 text-sm text-brand-ink dark:bg-zinc-900" />
                        </label>
                        <label class="block text-xs text-brand-moss">
                            {{ __('App') }}
                            <select wire:model="connectionPick" class="mt-1 block w-full rounded-lg border border-brand-ink/15 bg-white px-3 py-2 text-sm text-brand-ink dark:bg-zinc-900">
                                <option value="">{{ __('Choose an app') }}</option>
                                @foreach ($connectionOptions as $option)
                                    <option value="{{ $option['id'] }}">{{ $option['label'] }}</option>
                                @endforeach
                            </select>
                        </label>
                        @if ($connectionOptions === [])
                            <p class="text-xs text-brand-moss">{{ __('No other apps in this workspace yet.') }}</p>
                        @endif
                    @else
                        <label class="block text-xs text-brand-moss">
                            {{ __('Name') }}
                            <input type="text" wire:model="connectionLabel" placeholder="{{ __('Main') }}" class="mt-1 block w-full rounded-lg border border-brand-ink/15 bg-white px-3 py-2 text-sm text-brand-ink dark:bg-zinc-900" />
                        </label>
                        <label class="block text-xs text-brand-moss">
                            {{ __(\App\Modules\Edge\Support\EdgeContainerConnections::targetLabel($connectionKind)) }}
                            <input type="text" wire:model="connectionPick" class="mt-1 block w-full rounded-lg border border-brand-ink/15 bg-white px-3 py-2 text-sm text-brand-ink dark:bg-zinc-900" />
                        </label>
                    @endif
                    <x-input-error :messages="$errors->get('connection')" />
                    @if (! (($connectionKind === 'redis' && $connectionMode === 'create' || $connectionKind === 'http_delivery' || $connectionKind === 'key_value' && $connectionMode === 'create') && ! $cardOnFile))
                    <button type="submit" wire:loading.attr="disabled" wire:target="saveConnection" class="inline-flex items-center gap-2 rounded-md bg-brand-ink px-3 py-1.5 text-xs font-semibold text-white disabled:opacity-60">
                        <span wire:loading wire:target="saveConnection" class="inline-block h-3 w-3 animate-spin rounded-full border-2 border-white/40 border-t-white" aria-hidden="true"></span>
                        <span wire:loading.remove wire:target="saveConnection">{{ $connectionMode === 'attach' ? __('Attach') : __('Create') }}</span>
                        <span wire:loading wire:target="saveConnection">{{ $connectionKind === 'redis' && $connectionMode === 'create' ? __('Starting Redis…') : __('Working…') }}</span>
                    </button>
                    @endif
                </form>
                @endif
            @endif
        </div>
    </x-modal>

    <section class="border-t border-brand-ink/10 px-5 py-4 sm:px-6">
        <p class="text-xs font-semibold text-brand-ink">{{ __('Latest deploys') }}</p>
        @if ($deployments->isEmpty())
            <p class="mt-2 text-xs text-brand-moss">{{ __('No deploys yet.') }}</p>
        @else
            <ul class="mt-2 divide-y divide-brand-ink/10 text-xs">
                @foreach ($deployments as $deployment)
                    <li class="flex flex-wrap items-baseline justify-between gap-2 py-2">
                        <span class="font-mono text-brand-ink">{{ $deployment->status }}</span>
                        <span class="text-brand-moss">{{ $deployment->git_branch }} · {{ $deployment->created_at?->diffForHumans() }}</span>
                    </li>
                @endforeach
            </ul>
        @endif
    </section>

    @if (is_array($quote) && is_array($settings))
        <x-modal name="resources-estimate" :show="$panel === 'estimate'" maxWidth="lg" focusable>
            <div class="space-y-4 bg-white p-5 dark:bg-zinc-900">
                <div class="flex items-start justify-between gap-3">
                    <div>
                        <h2 class="text-sm font-semibold text-brand-ink">{{ __('Cost estimate') }}</h2>
                        <p class="mt-1 text-xs text-brand-moss">{{ __('While it is running, for :count. This is an estimate, not your bill. It assumes every vCPU stays busy. Sleeping time is not billed, and changing the hours does not change the app.', ['count' => trans_choice(':count instance|:count instances', $settings['max_instances'])]) }}</p>
                    </div>
                    <button type="button" class="text-xs font-semibold text-brand-ink underline" x-on:click="$dispatch('close-modal', 'resources-estimate')">{{ __('Close') }}</button>
                </div>
                <dl class="grid grid-cols-2 gap-x-6 gap-y-1 text-xs sm:grid-cols-3">
                    <div class="flex justify-between gap-2"><dt class="text-brand-moss">{{ __('Per second') }}</dt><dd class="font-medium text-brand-ink">{{ $quote['second'] }}</dd></div>
                    <div class="flex justify-between gap-2"><dt class="text-brand-moss">{{ __('Per minute') }}</dt><dd class="font-medium text-brand-ink">{{ $quote['minute'] }}</dd></div>
                    <div class="flex justify-between gap-2"><dt class="text-brand-moss">{{ __('Per hour') }}</dt><dd class="font-medium text-brand-ink">{{ $quote['hour'] }}</dd></div>
                    <div class="flex justify-between gap-2"><dt class="text-brand-moss">{{ __('Per day') }}</dt><dd class="font-medium text-brand-ink">{{ $quote['day'] }}</dd></div>
                    <div class="flex justify-between gap-2"><dt class="text-brand-moss">{{ __('Always on') }}</dt><dd class="font-medium text-brand-ink">{{ __(':price/mo', ['price' => $quote['month']]) }}</dd></div>
                    <div class="flex justify-between gap-2"><dt class="text-brand-moss">{{ __('Instances') }}</dt><dd class="font-medium text-brand-ink">{{ $settings['max_instances'] }}</dd></div>
                </dl>
                <label class="block text-xs text-brand-moss">
                    {{ __('Hours awake each day') }}
                    <input type="number" min="0" max="24" wire:model.live="awakeHours" class="mt-1 block w-full max-w-xs rounded-md border border-brand-ink/15 bg-white px-2 py-1 text-xs text-brand-ink dark:bg-zinc-900" />
                </label>
                <dl class="space-y-1 text-xs">
                    <div class="flex justify-between gap-2">
                        <dt class="text-brand-moss">{{ __('This schedule') }}</dt>
                        <dd class="font-medium text-brand-ink">{{ __(':price/mo', ['price' => $quote['awakeMonth']]) }}</dd>
                    </div>
                    <div class="flex justify-between gap-2">
                        <dt class="text-brand-moss">{{ __('Saved by sleeping') }}</dt>
                        <dd class="font-medium text-brand-ink">{{ __(':price/mo', ['price' => $quote['saved']]) }}</dd>
                    </div>
                </dl>
                <p class="text-xs text-brand-moss">{{ __('Always on is 730 hours a month. This schedule is that price times the hours awake out of 24. Saved by sleeping is the difference.') }}</p>
            </div>
        </x-modal>
    @endif

    <x-modal name="resources-sleep" :show="$panel === 'sleep'" maxWidth="lg" focusable>
        <form wire:submit="saveRuntime" class="space-y-4 bg-white p-5 dark:bg-zinc-900">
            <div>
                <h2 class="text-sm font-semibold text-brand-ink">{{ __('Sleep, region, scheduler') }}</h2>
                <p class="mt-1 text-xs text-brand-moss">{{ __('These apply on the next deploy. Size and instance count stay on the canvas.') }}</p>
            </div>
            <div class="grid gap-3 sm:grid-cols-2">
                <div>
                    <x-input-label for="res-sleep" :value="__('Sleep after idle')" />
                    <select id="res-sleep" wire:model.live="sleepAfter" class="mt-1 block w-full rounded-lg border border-brand-ink/15 bg-white px-3 py-2 text-sm dark:bg-zinc-900">
                        @foreach (\App\Modules\Edge\Support\EdgeContainerSettings::SLEEP_AFTER as $option)
                            <option value="{{ $option }}">{{ $option }}</option>
                        @endforeach
                    </select>
                    <x-input-error :messages="$errors->get('sleepAfter')" class="mt-1" />
                </div>
                <div>
                    <x-input-label for="res-region" :value="__('Run only in')" />
                    <select id="res-region" wire:model.live="jurisdiction" class="mt-1 block w-full rounded-lg border border-brand-ink/15 bg-white px-3 py-2 text-sm dark:bg-zinc-900">
                        <option value="">{{ __('Anywhere (fastest)') }}</option>
                        <option value="eu">{{ __('EU only') }}</option>
                        <option value="fedramp">{{ __('US FedRAMP only') }}</option>
                    </select>
                    <p class="mt-1 text-xs text-brand-moss">
                        @if ($jurisdiction === 'eu')
                            {{ __('The app wakes only in Europe. Use this when data has to stay in the EU. Visitors outside Europe wait longer for a cold start.') }}
                        @elseif ($jurisdiction === 'fedramp')
                            {{ __('The app wakes only in the US FedRAMP locations. Use this when the workload has to stay inside that boundary.') }}
                        @else
                            {{ __('The app wakes in the nearest region. That is the fastest start after sleep.') }}
                        @endif
                    </p>
                    <x-input-error :messages="$errors->get('jurisdiction')" class="mt-1" />
                </div>
            </div>
            <fieldset>
                <legend class="text-xs font-semibold text-brand-ink">{{ __('Regions') }}</legend>
                @php $nearData = $jurisdiction === '' && $regions === [] ? \App\Modules\Edge\Support\EdgeContainerSettings::dataRegion($site) : null; @endphp
                <p class="mt-1 text-xs text-brand-moss">
                    @if ($nearData)
                        {{ __('Unchecked, this app runs in :region, next to its dply database and Valkey: each query is a short round trip instead of one across the continent. Check regions to choose yourself.', ['region' => $nearData.' · '.__(\App\Modules\Edge\Support\EdgeContainerSettings::REGIONS[$nearData])]) }}
                    @else
                        {{ __('Leave all unchecked to use every region inside the choice above.') }}
                    @endif
                </p>
                <div class="mt-2 grid gap-2 sm:grid-cols-2">
                    @foreach (\App\Modules\Edge\Support\EdgeContainerSettings::REGIONS as $code => $label)
                        @continue($jurisdiction !== '' && ! in_array($code, \App\Modules\Edge\Support\EdgeContainerSettings::JURISDICTION_REGIONS[$jurisdiction] ?? [], true))
                        <label class="flex items-center gap-2 text-xs text-brand-ink">
                            <input type="checkbox" value="{{ $code }}" wire:model.live="regions" class="rounded border-brand-ink/20 text-brand-sage" />
                            <span>{{ $code }} · {{ __($label) }}</span>
                        </label>
                    @endforeach
                </div>
            </fieldset>
            <fieldset class="space-y-3">
                <legend class="text-xs font-semibold text-brand-ink">{{ __('Rollout') }}</legend>
                <div>
                    <x-input-label for="res-rollout" :value="__('How a deploy replaces instances')" />
                    <select id="res-rollout" wire:model.live="rolloutMode" class="mt-1 block w-full rounded-lg border border-brand-ink/15 bg-white px-3 py-2 text-sm dark:bg-zinc-900">
                        <option value="gradual">{{ __('Gradual') }}</option>
                        <option value="immediate">{{ __('Immediate') }}</option>
                        <option value="none">{{ __('None') }}</option>
                    </select>
                    <p class="mt-1 text-xs text-brand-moss">
                        @if ($rolloutMode === 'immediate')
                            {{ __('Every instance moves to the new image in one step. A replaced instance is asked to stop and has 15 minutes to exit.') }}
                        @elseif ($rolloutMode === 'none')
                            {{ __('The next deploy updates Worker code only. Running instances keep the current image until you pick Gradual or Immediate.') }}
                        @else
                            {{ __('Instances move to the new image in steps. One extra instance is reserved so the new image can start before an old one stops. A replaced instance is asked to stop and has 15 minutes to exit.') }}
                        @endif
                    </p>
                </div>
                <div class="grid gap-3 sm:grid-cols-2">
                    <div>
                        <x-input-label for="res-rollout-steps" :value="__('Steps')" />
                        <input id="res-rollout-steps" type="text" wire:model.live="rolloutSteps" placeholder="10, 100" class="mt-1 block w-full rounded-lg border border-brand-ink/15 bg-white px-3 py-2 text-sm dark:bg-zinc-900" />
                        <p class="mt-1 text-xs text-brand-moss">{{ __('Leave blank for the default: 100 when you run one instance, otherwise 10 then 100. Each number is the percent of instances on the new image. The last step must be 100.') }}</p>
                        <x-input-error :messages="$errors->get('rolloutSteps')" class="mt-1" />
                    </div>
                    <div>
                        <x-input-label for="res-rollout-grace" :value="__('Wait before replacing (seconds)')" />
                        <input id="res-rollout-grace" type="number" min="0" max="3600" wire:model.live="rolloutGraceSeconds" class="mt-1 block w-full rounded-lg border border-brand-ink/15 bg-white px-3 py-2 text-sm dark:bg-zinc-900" />
                        <p class="mt-1 text-xs text-brand-moss">{{ __('0 replaces an instance as soon as the rollout reaches it. A higher number leaves it alone until it has been connected that long.') }}</p>
                        <x-input-error :messages="$errors->get('rolloutGraceSeconds')" class="mt-1" />
                    </div>
                </div>
            </fieldset>
            <label class="flex items-start gap-3">
                <input type="checkbox" wire:model.live="stickySessions" class="mt-0.5 rounded border-brand-ink/20 text-brand-sage" />
                <span class="text-sm">
                    <span class="font-medium text-brand-ink">{{ __('Keep a visitor on the same instance') }}</span>
                    <span class="block text-xs text-brand-moss">{{ __('Uses a cookie so sessions and websockets stay on one container. Turn this off to spread every request at random.') }}</span>
                </span>
            </label>
            <label class="flex items-start gap-3">
                <input type="checkbox" wire:model.live="dedicatedJobs" class="mt-0.5 rounded border-brand-ink/20 text-brand-sage" />
                <span class="text-sm">
                    <span class="font-medium text-brand-ink">{{ __('Run jobs on their own instance') }}</span>
                    <span class="block text-xs text-brand-moss">{{ __('Off, queues stay on the same instances as the site. On, they use one extra instance. Queue workers run background work on their own.') }}</span>
                </span>
            </label>
            <label class="flex items-start gap-3">
                <input type="checkbox" wire:model.live="scheduler" class="mt-0.5 rounded border-brand-ink/20 text-brand-sage" />
                <span class="text-sm">
                    <span class="font-medium text-brand-ink">{{ __('Run the Laravel scheduler every minute') }}</span>
                    <span class="block text-xs text-brand-moss">{{ __('With queue workers it runs in worker-0 (schedule:work) and the app can sleep. Without them, a Cron Trigger calls schedule:run in the app every minute.') }}</span>
                </span>
            </label>
            <label class="flex items-start gap-3">
                <input type="checkbox" wire:model.live="migrateOnBoot" class="mt-0.5 rounded border-brand-ink/20 text-brand-sage" />
                <span class="text-sm">
                    <span class="font-medium text-brand-ink">{{ __('Run migrations when a container starts') }}</span>
                    <span class="block text-xs text-brand-moss">{{ __('Laravel: migrate --force --isolated. Rails: db:prepare.') }}</span>
                </span>
            </label>
            <div class="flex justify-end gap-2">
                <x-secondary-button type="button" x-on:click="$dispatch('close-modal', 'resources-sleep')" wire:click="openPanel('')">{{ __('Cancel') }}</x-secondary-button>
                <x-primary-button type="submit">{{ __('Save') }}</x-primary-button>
            </div>
        </form>
    </x-modal>

    <x-modal name="resources-cache" :show="$panel === 'cache'" maxWidth="4xl" focusable>
        <div class="bg-white dark:bg-zinc-950">
            <div class="flex items-center justify-between border-b border-brand-ink/10 px-5 py-3">
                <h2 class="text-sm font-semibold text-brand-ink">{{ __('Cache settings') }}</h2>
                <button type="button" class="text-xs font-semibold text-brand-ink underline" x-on:click="$dispatch('close-modal', 'resources-cache')">{{ __('Close') }}</button>
            </div>
            @if ($panel === 'cache')
                <div class="max-h-[75vh] overflow-y-auto">
                    @livewire('sites.edge.workspace.cache', ['server' => $server, 'site' => $site], key('resources-cache-'.$site->id))
                </div>
            @endif
        </div>
    </x-modal>

    @include('livewire.sites.edge.workspace.partials.database-panel')

    <x-modal name="resources-databases" :show="$panel === 'databases'" maxWidth="6xl" focusable>
        <div class="bg-white dark:bg-zinc-950">
            <div class="flex items-center justify-between border-b border-brand-ink/10 px-5 py-3">
                <h2 class="text-sm font-semibold text-brand-ink">{{ __('Databases') }}</h2>
                <button type="button" class="text-xs font-semibold text-brand-ink underline" x-on:click="$dispatch('close-modal', 'resources-databases')">{{ __('Close') }}</button>
            </div>
            @if ($panel === 'databases')
                @livewire(\App\Modules\Edge\Livewire\Databases::class, ['compact' => true], key('resources-databases'))
            @endif
        </div>
    </x-modal>

    <x-unsaved-changes-bar
        :message="__('Save and redeploy to apply these resource changes.')"
        saveAction="redeploySettings"
        :saveLabel="__('Save and redeploy')"
        :savePendingLabel="__('Deploying…')"
        discardAction="discardPending"
        formPendingWire="pending"
        closeModal="resources-app-database"
    />
</div>
