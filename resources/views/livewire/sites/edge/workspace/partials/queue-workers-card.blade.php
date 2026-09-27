{{-- Queue workers and, when it runs in worker-0, the scheduler. Rendered inside the workers sheet (sheets/workers.blade.php). --}}
@if ($isContainer && ($workers['enabled'] ?? false))
    @php
        $w = \App\Modules\Edge\Support\EdgeQueueWorkers::normalize($workers);
    @endphp
    <div class="grid gap-5" wire:key="queue-workers-card">
        <div class="flex flex-wrap gap-1.5">
            @if (\App\Modules\Edge\Support\EdgeQueueWorkers::for($site)['enabled'])
                <x-sheet.button wire:click="pauseWorkers({{ $w['paused'] ? 'false' : 'true' }})" wire:loading.attr="disabled" wire:target="pauseWorkers">{{ $w['paused'] ? __('Resume') : __('Pause') }}</x-sheet.button>
            @endif
            <x-sheet.button variant="danger" wire:click="removeWorkers">{{ __('Remove') }}</x-sheet.button>
        </div>
        @if ($w['paused'])
            <x-sheet.note tone="warn">{{ __('Paused. Jobs wait on the queue until you resume; deploys and autoscaling leave the workers stopped.') }}</x-sheet.note>
        @endif
        @if ($workersUnavailable)
            <x-sheet.note>{{ $workersUnavailable }}</x-sheet.note>
        @else
            @php $allow = \App\Modules\Edge\Support\EdgeQueueWorkers::allowance($site); @endphp
            <x-sheet.section>
                <div>
                    <x-sheet.toggle id="workers-autoscale" wire:model.live="workers.autoscale" :disabled="! $allow['autoscale']" :label="__('Autoscale')" :help="! $allow['autoscale'] ? __('On Pro and Team') : ($w['autoscale'] ? __('On, follows the backlog') : __('Off'))" />
                </div>
                <div class="grid gap-3 sm:grid-cols-2">
                    <x-sheet.field :label="$w['autoscale'] ? __('Always on') : __('Instances')" :help="$w['instances'] === 0 ? __('0, start when jobs arrive, stop 5 min after') : null" class="sm:col-span-2">
                        <x-sheet.segmented id="workers-instances">
                            @for ($i = $w['autoscale'] ? 0 : 1; $i <= \App\Modules\Edge\Support\EdgeQueueWorkers::MAX_INSTANCES; $i++)
                                <x-sheet.segment wire:click="$set('workers.instances', {{ $i }})" :active="(int) $w['instances'] === $i">{{ $i }}</x-sheet.segment>
                            @endfor
                        </x-sheet.segmented>
                    </x-sheet.field>
                    @if ($w['autoscale'])
                        <x-sheet.field :label="__('Up to')" :help="trans_choice(':count instance|:count instances', (int) $w['max_instances'])" class="sm:col-span-2">
                            <x-sheet.segmented id="workers-max">
                                @for ($i = max(1, (int) $w['instances']); $i <= \App\Modules\Edge\Support\EdgeQueueWorkers::MAX_INSTANCES; $i++)
                                    <x-sheet.segment wire:click="$set('workers.max_instances', {{ $i }})" :active="(int) $w['max_instances'] === $i">{{ $i }}</x-sheet.segment>
                                @endfor
                            </x-sheet.segmented>
                        </x-sheet.field>
                        <x-sheet.field :label="__('Add one at')" for="workers-scale-per">
                            <div class="flex items-center gap-2 text-xs">
                                <input id="workers-scale-per" type="number" min="1" max="1000" wire:model.live.debounce.500ms="workers.scale_per" class="dply-input mt-0 w-20" />
                                <span class="text-brand-moss">{{ __('jobs waiting per process') }}</span>
                            </div>
                        </x-sheet.field>
                        <x-sheet.field :label="__('Or when')" for="workers-max-wait">
                            <div class="flex items-center gap-2 text-xs">
                                <span class="text-brand-moss">{{ __('a job waits') }}</span>
                                <input id="workers-max-wait" type="number" min="0" max="3600" wire:model.live.debounce.500ms="workers.max_wait" class="dply-input mt-0 w-20" />
                                <span class="text-brand-moss">{{ __('s (0 = off)') }}</span>
                            </div>
                        </x-sheet.field>
                    @endif
                    <x-sheet.field :label="__('Processes')" :help="trans_choice(':count per instance|:count per instance', (int) $w['processes'])">
                        <x-sheet.segmented id="workers-processes">
                            @for ($i = 1; $i <= \App\Modules\Edge\Support\EdgeQueueWorkers::MAX_PROCESSES; $i++)
                                <x-sheet.segment wire:click="$set('workers.processes', {{ $i }})" :active="(int) $w['processes'] === $i">{{ $i }}</x-sheet.segment>
                            @endfor
                        </x-sheet.segmented>
                    </x-sheet.field>
                    <x-sheet.field :label="__('Connection')" :help="$w['connection'] === 'auto' && $workersConnection ? __('Automatic').' ('.$workersConnection.')' : null">
                        <x-sheet.segmented id="workers-connection">
                            @foreach (['auto' => __('Automatic'), 'redis' => 'redis', 'database' => 'database'] as $value => $label)
                                <x-sheet.segment wire:click="$set('workers.connection', '{{ $value }}')" :active="$w['connection'] === $value">{{ $label }}</x-sheet.segment>
                            @endforeach
                        </x-sheet.segmented>
                    </x-sheet.field>
                    <x-sheet.field :label="__('Queues')" for="workers-queues" class="sm:col-span-2">
                        <input id="workers-queues" type="text" wire:model.live.debounce.500ms="workers.queues" placeholder="high,default" class="dply-input mt-0 font-mono" />
                    </x-sheet.field>
                </div>
                @if ($workersConnection === null)
                    <x-sheet.note tone="danger">{{ __('This app has no :connection for workers to pull from.', ['connection' => $w['connection']]) }}</x-sheet.note>
                @elseif ($workersConnection === 'database' && ($site->edgeMeta()['database']['provider'] ?? '') === 'dply' && (int) ($site->edgeMeta()['database']['suspend'] ?? -1) !== -1)
                    <x-sheet.note>
                        {{ __('Workers check the database every :sleep s, so it will not sleep while they run.', ['sleep' => $w['sleep']]) }}
                        <button type="button" wire:click="useValkeyForWorkers" wire:island="resources-connection" x-on:click="$dispatch('open-modal', 'resources-connection')" class="font-semibold underline">{{ __('Queue on dply Valkey instead') }}</button>
                    </x-sheet.note>
                @endif
            </x-sheet.section>

            <x-sheet.section :title="__('Groups')">
                <p class="text-2xs leading-4 text-brand-mist">{{ __('More workers for other queues, sized and scaled on their own, so a flood on one queue cannot hold up another.') }}</p>
                @foreach (array_values((array) ($workers['groups'] ?? [])) as $gi => $rawGroup)
                    @php $g = \App\Modules\Edge\Support\EdgeQueueWorkers::normalizeGroup($rawGroup, $gi); @endphp
                    <div class="grid gap-3 rounded-xl border border-brand-ink/10 p-3.5 dark:border-brand-mist/20" wire:key="worker-group-{{ $gi }}">
                        <div class="flex items-end gap-2">
                            <x-sheet.field :label="__('Queues')" class="min-w-0 flex-1">
                                <input type="text" wire:model.live.debounce.500ms="workers.groups.{{ $gi }}.queues" placeholder="high" class="dply-input mt-0 font-mono" />
                            </x-sheet.field>
                            <x-sheet.button variant="danger" wire:click="removeWorkerGroup({{ $gi }})" class="shrink-0">{{ __('Remove') }}</x-sheet.button>
                        </div>
                        <div class="grid grid-cols-2 gap-3">
                            <x-sheet.field :label="$g['autoscale'] ? __('Always on') : __('Instances')" class="col-span-2">
                                <x-sheet.segmented>
                                    @for ($i = $g['autoscale'] ? 0 : 1; $i <= \App\Modules\Edge\Support\EdgeQueueWorkers::MAX_INSTANCES; $i++)
                                        <x-sheet.segment wire:click="$set('workers.groups.{{ $gi }}.instances', {{ $i }})" :active="(int) $g['instances'] === $i">{{ $i }}</x-sheet.segment>
                                    @endfor
                                </x-sheet.segmented>
                            </x-sheet.field>
                            <x-sheet.field :label="__('Processes')" class="col-span-2">
                                <x-sheet.segmented>
                                    @for ($i = 1; $i <= \App\Modules\Edge\Support\EdgeQueueWorkers::MAX_PROCESSES; $i++)
                                        <x-sheet.segment wire:click="$set('workers.groups.{{ $gi }}.processes', {{ $i }})" :active="(int) $g['processes'] === $i">{{ $i }}</x-sheet.segment>
                                    @endfor
                                </x-sheet.segmented>
                            </x-sheet.field>
                            @if ($g['autoscale'])
                                <x-sheet.field :label="__('up to')" class="col-span-2">
                                    <x-sheet.segmented>
                                        @for ($i = max(1, (int) $g['instances']); $i <= \App\Modules\Edge\Support\EdgeQueueWorkers::MAX_INSTANCES; $i++)
                                            <x-sheet.segment wire:click="$set('workers.groups.{{ $gi }}.max_instances', {{ $i }})" :active="(int) $g['max_instances'] === $i">{{ $i }}</x-sheet.segment>
                                        @endfor
                                    </x-sheet.segmented>
                                </x-sheet.field>
                            @endif
                        </div>
                        <div>
                            <x-sheet.toggle wire:model.live="workers.groups.{{ $gi }}.autoscale" :label="__('Autoscale')" />
                        </div>
                        <p class="font-mono text-2xs text-brand-moss">worker-{{ $g['key'] }}-N · queue:work --queue={{ $g['queues'] }}</p>
                    </div>
                @endforeach
                @if (count((array) ($workers['groups'] ?? [])) < min(\App\Modules\Edge\Support\EdgeQueueWorkers::MAX_GROUPS, $allow['groups']))
                    <div><x-sheet.button wire:click="addWorkerGroup">{{ __('Add a group') }}</x-sheet.button></div>
                @elseif ($allow['groups'] === 0)
                    <p class="text-xs text-brand-moss">{{ __('Groups are on Pro and Team.') }}</p>
                @endif
            </x-sheet.section>

            <details class="group rounded-xl border border-brand-ink/10 px-3.5 py-2.5 text-xs dark:border-brand-mist/20">
                <summary class="cursor-pointer text-sm font-semibold text-brand-ink">{{ __('Worker options') }}</summary>
                <div class="mt-3 grid grid-cols-2 gap-3">
                    @foreach (['timeout' => [__('Timeout (s)'), 1, 3600], 'tries' => [__('Tries'), 1, 25], 'sleep' => [__('Sleep when empty (s)'), 1, 60], 'memory' => [__('Memory (MB)'), 64, 2048], 'max_time' => [__('Restart after (s)'), 60, 86400]] as $key => [$label, $min, $max])
                        <x-sheet.field :label="$label" for="workers-{{ $key }}">
                            <input id="workers-{{ $key }}" type="number" min="{{ $min }}" max="{{ $max }}" wire:model.live.debounce.500ms="workers.{{ $key }}" class="dply-input mt-0" />
                        </x-sheet.field>
                    @endforeach
                </div>
                <p class="mt-3 break-words font-mono text-2xs text-brand-moss">php artisan queue:work {{ $workersConnection ?? '…' }} --queue={{ $w['queues'] }} --tries={{ $w['tries'] }} --timeout={{ $w['timeout'] }} --sleep={{ $w['sleep'] }} --memory={{ $w['memory'] }} --max-time={{ $w['max_time'] }}</p>
            </details>

            @php
                $draftSpan = \App\Modules\Edge\Support\EdgeQueueWorkers::draftInstances($workers);
                $overPlan = ($allow['instances'] !== null && $draftSpan['max'] > $allow['instances'])
                    || count((array) ($workers['groups'] ?? [])) > $allow['groups']
                    || (! $allow['autoscale'] && ($w['autoscale'] || collect($w['groups'])->contains('autoscale', true)));
            @endphp
            @if (! $site->organization?->hasPlan())
                <x-sheet.note tone="warn">
                    {{ __('Workers don’t run without a plan. Start a trial or choose a plan on the billing page.') }}
                </x-sheet.note>
            @elseif ($overPlan)
                <x-sheet.note tone="warn">
                    {{ __(':plan runs :n worker instance(s) per app:autoscale:groups. The rest of these settings are not deployed. Choose a plan on the billing page for more.', [
                        'plan' => $allow['plan'] ?: __('This plan'),
                        'n' => $allow['instances'] ?? '∞',
                        'autoscale' => $allow['autoscale'] ? '' : __(', without autoscaling'),
                        'groups' => $allow['groups'] > 0 ? __(', :g extra group(s)', ['g' => $allow['groups']]) : __(', no extra groups'),
                    ]) }}
                </x-sheet.note>
            @endif

            <div class="rounded-xl border border-brand-ink/10 px-3.5 py-2.5 text-xs dark:border-brand-mist/20">
                @php $span = \App\Modules\Edge\Support\EdgeQueueWorkers::draftInstances($workers); @endphp
                @if ($span['max'] > $span['min'])
                    <p class="font-mono text-lg font-bold tabular-nums text-brand-ink">{{ __('About $:min–$:max/mo', ['min' => number_format($workersMonthlyCents / 100, 2), 'max' => number_format($workersMaxMonthlyCents / 100, 2)]) }}</p>
                    <p class="mt-0.5 text-2xs text-brand-mist">{{ __(':min–:max × :size · the extra ones billed only while running', ['min' => $span['min'], 'max' => $span['max'], 'size' => $settings['instance_type'] ?? 'basic']) }}</p>
                @else
                    <p class="font-mono text-lg font-bold tabular-nums text-brand-ink">{{ __('About $:total/mo', ['total' => number_format($workersMonthlyCents / 100, 2)]) }}</p>
                    <p class="mt-0.5 text-2xs text-brand-mist">{{ __(':instances × :size, always on', ['instances' => $span['min'], 'size' => $settings['instance_type'] ?? 'basic']) }}</p>
                @endif
                @foreach ($workerScaling as $scaling)
                    @include('livewire.sites.edge.workspace.partials.worker-autoscale', $scaling + ['labelled' => count($workerScaling) > 1])
                @endforeach
            </div>

            <x-sheet.section>
                <div class="flex flex-wrap items-center gap-x-3 gap-y-1.5 text-xs">
                    <x-sheet.button wire:click="loadWorkersBacklog" wire:loading.attr="disabled" wire:target="loadWorkersBacklog">
                        <span wire:loading.remove wire:target="loadWorkersBacklog">{{ __('Check workers') }}</span>
                        <span wire:loading wire:target="loadWorkersBacklog">{{ __('Checking…') }}</span>
                    </x-sheet.button>
                    <x-sheet.button wire:click="sendTestJob" wire:loading.attr="disabled" wire:target="sendTestJob">{{ __('Send test job') }}</x-sheet.button>
                    @if (is_array($workersBacklog))
                        @foreach ($workersBacklog['queues'] as $queue => $waiting)
                            <span class="tabular-nums text-brand-moss"><span class="font-mono text-brand-ink">{{ $queue }}</span> {{ trans_choice(':count waiting|:count waiting', $waiting) }}</span>
                        @endforeach
                        @if (($workersBacklog['failed'] ?? null) !== null)
                            <span @class(['tabular-nums', 'font-semibold text-rose-700 dark:text-rose-300' => $workersBacklog['failed'] > 0, 'text-brand-moss' => $workersBacklog['failed'] === 0])>{{ trans_choice(':count failed|:count failed', $workersBacklog['failed']) }}</span>
                        @endif
                    @endif
                </div>
                @if ($workersBacklogError)
                    <x-sheet.note tone="danger">{{ $workersBacklogError }}</x-sheet.note>
                @endif
                <x-sheet.row wire:click="openFailedJobs" x-on:click="$dispatch('open-modal', 'resources-failed-jobs')" :title="__('Failed jobs')" />
                <x-sheet.row wire:click="openWorkerLogs" x-on:click="$dispatch('open-modal', 'resources-worker-logs')" :title="__('Logs')" />
            </x-sheet.section>

            @if (is_array($workersStatus) || $workersStatusError)
                @php
                    $stoppedWorkers = $w['paused'] ? 0 : collect($workersStatus ?? [])->where('wanted', true)->whereIn('status', ['stopped', 'stopped_with_code'])->count();
                @endphp
                <x-sheet.section>
                    <ul @class(["divide-y divide-brand-ink/10 text-xs dark:divide-brand-mist/15", "rounded-xl border border-brand-ink/10 dark:border-brand-mist/15" => ($workersStatus ?? []) !== []]) wire:key="workers-status">
                        @foreach ($workersStatus ?? [] as $worker)
                            @php
                                $up = in_array($worker['status'], ['running', 'healthy'], true);
                                $idle = ! $up && (! ($worker['wanted'] ?? true) || ($worker['paused'] ?? false));
                            @endphp
                            <li class="flex items-center justify-between gap-2 px-3.5 py-2">
                                <span class="flex min-w-0 items-center gap-1.5 font-mono text-brand-ink">
                                    <span @class(['h-1.5 w-1.5 shrink-0 rounded-full', 'bg-emerald-500' => $up, 'bg-amber-500' => $worker['status'] === 'stopping', 'bg-brand-ink/20' => $idle && $worker['status'] !== 'stopping', 'bg-red-500' => ! $up && ! $idle && $worker['status'] !== 'stopping'])></span>
                                    {{ $worker['name'] }}
                                    @if ($p = $workersPlacement[$worker['name']] ?? null)
                                        @php $far = max($p['db_ms'] ?? 0, $p['redis_ms'] ?? 0) > \App\Modules\Edge\Services\Containers\EdgeContainerDeployer::FAR_FROM_DATABASE_MS; @endphp
                                        <span @class(['font-sans', 'text-brand-moss' => ! $far, 'font-semibold text-amber-800 dark:text-amber-300' => $far])>· {{ $p['location'] ?: '?' }}{{ $p['db_ms'] !== null ? ' · db '.$p['db_ms'].' ms' : '' }}{{ $p['redis_ms'] !== null ? ' · redis '.$p['redis_ms'].' ms' : '' }}</span>
                                    @endif
                                </span>
                                <span class="shrink-0 text-brand-moss">
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
                        <div><x-sheet.button wire:click="startWorkers" wire:loading.attr="disabled" wire:target="startWorkers">{{ __('Start stopped workers') }}</x-sheet.button></div>
                    @endif
                    @if ($workersStatusError)
                        <x-sheet.note tone="danger">{{ $workersStatusError }}</x-sheet.note>
                    @endif
                </x-sheet.section>
            @endif
        @endif
    </div>
@endif
@if ($isContainer && $scheduler)
    @php $schedulerOnWorker = ($workers['enabled'] ?? false) && \App\Modules\Edge\Support\EdgeQueueWorkers::unavailableReason($site) === null; @endphp
    <div class="grid gap-2" wire:key="scheduler-card">
        @if ($workers['enabled'] ?? false)
            <h3 class="text-2xs font-semibold uppercase tracking-[0.16em] text-brand-mist">{{ __('Scheduler') }}</h3>
        @endif
        <p class="font-mono text-2xs text-brand-moss">{{ $schedulerOnWorker ? 'php artisan schedule:work' : 'php artisan schedule:run · * * * * *' }}</p>
        <p class="text-xs text-brand-moss">
            {{ $schedulerOnWorker
                ? __('Runs in worker-0 beside the queue workers, so the app itself can still sleep. Pausing the workers pauses it too.')
                : __('A Cron Trigger calls the app every minute, which keeps it from sleeping. Add queue workers and it moves into a worker instead.') }}
        </p>
        <div class="flex flex-wrap gap-1.5">
            <x-sheet.button wire:click="runSchedulerNow" wire:loading.attr="disabled" wire:target="runSchedulerNow">
                <span wire:loading.remove wire:target="runSchedulerNow">{{ __('Run now') }}</span>
                <span wire:loading wire:target="runSchedulerNow">{{ __('Running…') }}</span>
            </x-sheet.button>
            <x-sheet.button variant="danger" wire:click="removeScheduler">{{ __('Remove') }}</x-sheet.button>
        </div>
        @if ($schedulerOutput)
            <pre class="overflow-x-auto whitespace-pre-wrap rounded-xl bg-zinc-950 p-3 font-mono text-2xs text-zinc-200">{{ $schedulerOutput }}</pre>
        @endif
    </div>
@endif
