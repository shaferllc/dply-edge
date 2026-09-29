<div>
    <section class="border-b border-brand-ink/10 px-5 py-4 sm:px-6">
        @include('livewire.sites.edge.workspace.partials.feature-guide', [
            'docSlug' => 'queue-workers',
            'what' => __('Background work for this app: the Projects queues it is attached to and who runs them, its Laravel workers, and queue bindings for Worker code.'),
            'steps' => [
                __('Create queues in Projects → Queues and attach them on Overview → Resources. The first production app attached to a queue runs its jobs; others only send.'),
                __('Container Laravel apps: add Queue workers on Overview to run php artisan queue:work.'),
                __('From middleware/SSR code, send messages with await env.JOBS.send({ type: "…", … }) (binding name must match).'),
            ],
            'setupLinks' => [
                [
                    'label' => __('Overview'),
                    'href' => $bindingsUrl,
                ],
            ],
            'tips' => [
                __('A queue has exactly one consumer, so jobs are never split between apps.'),
                __('Bindings apply on the next deploy. Creating a resource needs platform Edge credentials.'),
            ],
        ])

        @include('livewire.sites.edge.workspace.partials.managed-only-banner', ['managedDelivery' => $managedDelivery])

    </section>

    @php
        $overviewUrl = route('sites.show', ['server' => $server, 'site' => $site, 'section' => 'general']);
        $running = collect($attachedQueues)->firstWhere('role', 'runs');
        $sendsTo = collect($attachedQueues)->where('role', 'sends');
        $orphans = collect($attachedQueues)->where('role', 'none');
        $workerQueues = collect([$workers['queues'], ...array_column($workers['groups'], 'queues')])->filter()->implode(', ');
        $workerRange = $workers['autoscale']
            ? __(':min–:max instances, scaling with the backlog', ['min' => $workers['instances'], 'max' => $workers['max_instances']])
            : trans_choice(':count instance|:count instances', $workers['instances']);
        $roleLabel = fn (array $q): string => match ($q['role']) {
            'runs' => __('Runs here'),
            'sends' => __('Sends to :app', ['app' => $q['owner']]),
            default => __('Nothing runs it'),
        };
    @endphp

    <section class="space-y-8 border-b border-brand-ink/10 px-5 py-8 sm:px-10 sm:py-10" @if ($isContainer && $workerInstances > 0) wire:init="loadLive" @endif>
        <div>
            <p class="text-2xs font-semibold uppercase tracking-[0.16em] text-brand-mist">{{ __('Jobs') }}</p>
            <p class="mt-3 max-w-3xl text-2xl font-medium leading-snug tracking-tight text-brand-ink sm:text-3xl">
                @if ($attachedQueues === [] && $workerInstances === 0)
                    {{ __('This app has no background jobs yet. Attach a queue from Projects, or turn on workers, on Overview → Resources.') }}
                @else
                    @if ($running)
                        {{ __('This app runs the jobs on the') }} <span class="font-mono text-brand-sage">{{ $running['queue'] }}</span> {{ __('queue.') }}
                    @endif
                    @foreach ($sendsTo as $q)
                        {{ __('It sends to') }} <span class="font-mono text-brand-sage">{{ $q['queue'] }}</span>{{ __(', which :app runs.', ['app' => $q['owner']]) }}
                    @endforeach
                    @if ($workerInstances > 0)
                        {{ __('Laravel workers process') }} <span class="font-mono">{{ $workerQueues }}</span>
                        @if ($workers['paused'])
                            <span class="text-amber-600 dark:text-amber-300">{{ __('— paused right now.') }}</span>
                        @else
                            {{ __('with :range.', ['range' => $workerRange]) }}
                        @endif
                    @endif
                    @if ($orphans->isNotEmpty())
                        <span class="text-amber-600 dark:text-amber-300">{{ trans_choice(':count queue has nothing running its jobs.|:count queues have nothing running their jobs.', $orphans->count()) }}</span>
                    @endif
                    @if (($live['failed'] ?? 0) > 0)
                        <span class="text-amber-600 dark:text-amber-300">{{ trans_choice(':count job has failed.|:count jobs have failed.', $live['failed']) }}</span>
                    @endif
                @endif
            </p>
        </div>

        {{-- Projects → Queues attachments (Cloudflare). One consumer per queue. --}}
        <div>
            <div class="flex items-center justify-between gap-3 border-b border-brand-ink/10 pb-2">
                <p class="text-sm font-semibold text-brand-ink">{{ __('Queues') }}</p>
                <a href="{{ $overviewUrl }}" wire:navigate class="text-sm font-medium text-brand-sage hover:underline">{{ __('Attach or detach on Overview') }}</a>
            </div>
            @forelse ($attachedQueues as $q)
                <div class="flex min-h-14 items-center gap-3 border-b border-brand-ink/10 py-3" wire:key="jobs-queue-{{ $q['name'] }}">
                    <span class="min-w-0 flex-1">
                        <span class="block text-sm text-brand-ink sm:text-base">
                            <span class="font-mono">{{ $q['queue'] }}</span>
                            @if ($q['asleep'])<span class="text-brand-mist"> · {{ __('asleep') }}</span>@endif
                        </span>
                        <span class="mt-0.5 block text-xs text-brand-moss">
                            @if ($q['role'] === 'runs')
                                {{ __('Jobs sent to this queue by any app are delivered here. The app has DPLY_QUEUE=:name.', ['name' => $q['name']]) }}
                            @elseif ($q['role'] === 'sends')
                                {{ __('A queue has one consumer: the first app attached to it. This app only adds jobs.') }}
                            @else
                                {{ __('Messages wait here. Attach a production app to run them.') }}
                            @endif
                        </span>
                    </span>
                    @if ($q['role'] === 'sends' && $q['owner_url'])
                        <a href="{{ $q['owner_url'] }}" wire:navigate class="shrink-0 text-xs font-medium text-brand-sage hover:underline">{{ $roleLabel($q) }}</a>
                    @else
                        <span @class(['shrink-0 text-xs', 'text-brand-sage' => $q['role'] === 'runs', 'text-amber-600 dark:text-amber-300' => $q['role'] === 'none'])>{{ $roleLabel($q) }}</span>
                    @endif
                </div>
            @empty
                <p class="border-b border-brand-ink/10 py-4 text-sm text-brand-moss">{{ __('No queue attached. Queues are created in Projects → Queues and attached on Overview → Resources.') }}</p>
            @endforelse
        </div>

        {{-- Laravel queue:work workers (container apps). Summary here; controls on Overview. --}}
        @if ($isContainer)
            <div>
                <div class="flex items-center justify-between gap-3 border-b border-brand-ink/10 pb-2">
                    <p class="text-sm font-semibold text-brand-ink">{{ __('Workers') }}</p>
                    <a href="{{ $overviewUrl }}" wire:navigate class="text-sm font-medium text-brand-sage hover:underline">{{ __('Manage on Overview') }}</a>
                </div>
                @if ($workersUnavailable)
                    <p class="border-b border-brand-ink/10 py-4 text-sm text-brand-moss">{{ $workersUnavailable }}</p>
                @elseif ($workerInstances === 0)
                    <p class="border-b border-brand-ink/10 py-4 text-sm text-brand-moss">{{ __('Workers are off. Turn them on to run php artisan queue:work next to the app.') }}</p>
                @else
                    <div class="flex min-h-14 items-center gap-3 border-b border-brand-ink/10 py-3">
                        <span class="min-w-0 flex-1">
                            <span class="block text-sm text-brand-ink sm:text-base">{{ __(':processes queue:work processes per instance, :range', ['processes' => $workers['processes'], 'range' => $workerRange]) }}</span>
                            <span class="mt-0.5 block text-xs text-brand-moss">
                                {{ __('Reads :queues from :connection', ['queues' => $workerQueues, 'connection' => \App\Modules\Edge\Support\EdgeQueueWorkers::connection($site) === 'redis' ? __('Redis') : __('the database')]) }}
                                @if (count($workers['groups']) > 0) · {{ trans_choice(':count extra group|:count extra groups', count($workers['groups'])) }} @endif
                            </span>
                        </span>
                        <span class="shrink-0 text-xs text-brand-moss">
                            @if ($workers['paused'])
                                <span class="text-amber-600 dark:text-amber-300">{{ __('Paused') }}</span>
                            @elseif ($live)
                                {{ __(':up of :total running', ['up' => $live['up'], 'total' => $live['total']]) }}
                            @elseif ($liveError)
                                <span title="{{ $liveError }}">{{ __('Couldn’t reach the app') }}</span>
                            @else
                                <span wire:loading wire:target="loadLive">{{ __('Checking…') }}</span>
                            @endif
                        </span>
                    </div>
                    <div class="flex min-h-12 items-center gap-3 border-b border-brand-ink/10 py-3">
                        <span class="flex-1 text-sm text-brand-ink sm:text-base">{{ $runsScheduler ? __('schedule:run runs every minute inside a worker') : __('The scheduler is off') }}</span>
                        <span class="shrink-0 text-xs text-brand-moss">{{ $runsScheduler ? __('On') : __('Off') }}</span>
                    </div>
                    @if ($live && $live['failed'] !== null)
                        <div class="flex min-h-12 items-center gap-3 border-b border-brand-ink/10 py-3">
                            <span class="flex-1 text-sm text-brand-ink sm:text-base">{{ trans_choice(':count failed job is waiting to be retried or deleted|:count failed jobs are waiting to be retried or deleted', $live['failed']) }}</span>
                            <a href="{{ $overviewUrl }}" wire:navigate @class(['shrink-0 text-xs font-medium hover:underline', 'text-amber-600 dark:text-amber-300' => $live['failed'] > 0, 'text-brand-moss' => $live['failed'] === 0])>{{ $live['failed'] > 0 ? __('Review') : __('None') }}</a>
                        </div>
                    @endif
                @endif
            </div>
        @endif

        {{-- Edge Worker bindings: env.NAME.send() from middleware / SSR. --}}
        <div>
            <div class="flex items-center justify-between gap-3 border-b border-brand-ink/10 pb-2">
                <p class="text-sm font-semibold text-brand-ink">{{ __('Send from Worker code') }}</p>
                @can('update', $site)
                    <button type="button" wire:click="openManageBindingsModal" @disabled(! $managedDelivery) class="text-sm font-medium text-brand-sage hover:underline disabled:opacity-50">{{ __('Manage bindings') }}</button>
                @endcan
            </div>
            @forelse ($queueBindings as $binding)
                <div class="flex min-h-12 items-center gap-3 border-b border-brand-ink/10 py-3">
                    <span class="flex-1 text-sm text-brand-ink sm:text-base">
                        <span class="font-mono">env.{{ $binding['name'] ?? '—' }}.send()</span> {{ __('adds to') }} <span class="font-mono">{{ $binding['value'] ?? '' }}</span>
                    </span>
                    @if (($binding['source'] ?? '') === 'repo')
                        <span class="shrink-0 font-mono text-2xs uppercase text-brand-moss">{{ __('Repo') }}</span>
                    @endif
                </div>
            @empty
                <p class="border-b border-brand-ink/10 py-4 text-sm text-brand-moss">{{ __('None. Add one to queue work from middleware or SSR code; applies on the next deploy.') }}</p>
            @endforelse
        </div>
    </section>

    <x-modal
        name="edge-jobs-bindings-modal"
        :show="false"
        maxWidth="2xl"
        overlayClass="bg-brand-ink/40"
        panelClass="dply-modal-panel overflow-hidden shadow-xl flex max-h-[min(90vh,820px)] flex-col"
        focusable
    >
        <div class="shrink-0 border-b border-brand-ink/10 px-5 py-4 sm:px-6">
            <p class="text-xs font-semibold uppercase tracking-[0.16em] text-brand-sage">{{ __('Bindings') }}</p>
            <h2 class="mt-1 text-lg font-semibold text-brand-ink">{{ __('Queue bindings') }}</h2>
            <p class="mt-1 text-sm text-brand-moss">{{ __('Create or attach a queue for env.NAME in your Worker code. Applies on the next deploy.') }}</p>
        </div>

        <div class="min-h-0 flex-1 space-y-4 overflow-y-auto px-5 py-4 sm:px-6">
            @unless ($hasWorker)
                <div class="rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-xs text-amber-900 dark:border-raw-amber-900/40 dark:bg-raw-amber-950/40 dark:text-raw-amber-100">
                    {{ __('No worker on this site yet — bindings attach after you add middleware or enable SSR and redeploy.') }}
                </div>
            @endunless

            @can('update', $site)
                <form wire:submit.prevent="addQueueBinding" class="space-y-3 rounded-xl border border-brand-ink/10 bg-brand-sand/15 p-3">
                    <p class="text-2xs font-semibold uppercase tracking-wide text-brand-mist">{{ __('Add queue binding') }}</p>
                    <div class="grid gap-3 sm:grid-cols-2">
                        <div>
                            <x-input-label for="jobs-binding-name" :value="__('Binding name')" />
                            <x-text-input
                                id="jobs-binding-name"
                                wire:model="new_name"
                                type="text"
                                class="mt-1 block w-full font-mono text-sm"
                                placeholder="JOBS"
                                autocomplete="off"
                            />
                            <p class="mt-1 text-xs text-brand-moss">{{ __('Becomes env.NAME in your worker.') }}</p>
                            @error('new_name') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <x-input-label for="jobs-binding-value" :value="$create_resource ? __('New queue name') : __('Existing queue name')" />
                            <x-text-input
                                id="jobs-binding-value"
                                wire:model="new_value"
                                type="text"
                                class="mt-1 block w-full font-mono text-sm"
                                placeholder="{{ $create_resource ? 'my-site-jobs' : 'existing-queue-name' }}"
                                autocomplete="off"
                            />
                            @error('new_value') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
                        </div>
                    </div>
                    <label class="flex items-center gap-2 text-xs text-brand-moss">
                        <input type="checkbox" wire:model.live="create_resource" class="rounded border-brand-ink/20 text-brand-sage focus:ring-brand-sage/40" />
                        {{ __('Create a new queue instead of attaching an existing one') }}
                    </label>
                    <div class="flex justify-end">
                        <x-primary-button type="submit" wire:loading.attr="disabled" wire:target="addQueueBinding">
                            <span wire:loading.remove wire:target="addQueueBinding">{{ $create_resource ? __('Create & attach') : __('Attach') }}</span>
                            <span wire:loading wire:target="addQueueBinding">{{ __('Saving…') }}</span>
                        </x-primary-button>
                    </div>
                </form>
            @endcan

            <div>
                <p class="text-2xs font-semibold uppercase tracking-wide text-brand-mist">{{ __('Dashboard queues') }}</p>
                @if ($dashboardQueueBindings === [])
                    <p class="mt-2 text-sm text-brand-moss">{{ __('None yet — add one above.') }}</p>
                @else
                    <ul class="mt-2 divide-y divide-brand-ink/8 rounded-lg border border-brand-ink/10">
                        @foreach ($dashboard_bindings as $index => $entry)
                            @continue(($entry['kind'] ?? '') !== 'queue')
                            <li class="flex flex-wrap items-center justify-between gap-2 px-3 py-2" wire:key="jobs-binding-{{ $index }}-{{ $entry['name'] }}">
                                <div class="min-w-0">
                                    <p class="font-mono text-sm text-brand-ink">env.{{ $entry['name'] }}</p>
                                    <p class="mt-0.5 font-mono text-xs text-brand-mist">{{ $entry['value'] }}</p>
                                </div>
                                <div class="flex items-center gap-3">
                                    @can('update', $site)
                                        <button
                                            type="button"
                                            wire:click="openConfirmActionModal('removeBinding', @js([$index]), @js(__('Detach binding')), @js(__('Detach :name? The queue and its data stay in place.', ['name' => $entry['name']])), @js(__('Detach')), true)"
                                            class="text-xs font-semibold text-rose-700 hover:text-rose-900 dark:text-rose-400"
                                        >
                                            {{ __('Detach') }}
                                        </button>
                                    @endcan
                                </div>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </div>

            @php
                $repoQueues = collect($queueBindings)->where('source', 'repo')->values();
            @endphp
            @if ($repoQueues->isNotEmpty())
                <div>
                    <p class="text-2xs font-semibold uppercase tracking-wide text-brand-mist">{{ __('From repo') }}</p>
                    <p class="mt-1 text-xs text-brand-moss">{{ __('Read-only — declare in wrangler.toml and redeploy.') }}</p>
                    <ul class="mt-2 divide-y divide-brand-ink/8 rounded-lg border border-brand-ink/10">
                        @foreach ($repoQueues as $entry)
                            <li class="flex flex-wrap items-center justify-between gap-2 px-3 py-2">
                                <div>
                                    <span class="font-mono text-sm text-brand-ink">env.{{ $entry['name'] }}</span>
                                    <p class="mt-0.5 font-mono text-xs text-brand-mist">{{ $entry['value'] ?? '' }}</p>
                                </div>
                            </li>
                        @endforeach
                    </ul>
                </div>
            @endif
        </div>

        <div class="flex shrink-0 flex-wrap items-center justify-between gap-3 border-t border-brand-ink/10 px-5 py-3 sm:px-6">
            <a href="{{ $bindingsUrl }}" wire:navigate class="text-xs font-semibold text-brand-sage hover:underline">
                {{ __('Open full Bindings page') }}
            </a>
            <button
                type="button"
                wire:click="closeManageBindingsModal"
                class="rounded-lg border border-brand-ink/15 bg-white px-3 py-1.5 text-sm font-medium text-brand-ink shadow-sm hover:bg-brand-sand/40"
            >
                {{ __('Done') }}
            </button>
        </div>
    </x-modal>

    @include('livewire.partials.confirm-action-modal')
</div>
