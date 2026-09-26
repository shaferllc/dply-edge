{{-- Queue workers and, when it runs in worker-0, the scheduler. Rendered under the store the queue uses (resources.blade.php). --}}
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
                            <p class="mt-2 rounded-md border border-amber-500/50 bg-amber-500/10 px-2 py-1.5 text-xs font-medium text-brand-ink">{{ __('Paused. Jobs wait on the queue until you resume; deploys and autoscaling leave the workers stopped.') }}</p>
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
                                    @for ($i = $w['autoscale'] ? 0 : 1; $i <= \App\Modules\Edge\Support\EdgeQueueWorkers::MAX_INSTANCES; $i++)<option value="{{ $i }}">{{ $i === 0 ? __('0, start when jobs arrive, stop 5 min after') : $i }}</option>@endfor
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
                            @if (! $site->organization?->hasPlan())
                                <p class="mt-2 rounded-md border border-amber-500/50 bg-amber-500/10 px-2 py-1.5 text-xs font-medium text-brand-ink">
                                    {{ __('Workers don’t run without a plan. Start a trial or choose a plan on the billing page.') }}
                                </p>
                            @elseif ($overPlan)
                                <p class="mt-2 rounded-md border border-amber-500/50 bg-amber-500/10 px-2 py-1.5 text-xs font-medium text-brand-ink">
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
