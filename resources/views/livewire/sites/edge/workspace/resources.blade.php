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
                        <button type="button" wire:click="openPanel('sleep')" x-on:click="$dispatch('open-modal', 'resources-sleep')" class="mt-auto pt-3 text-left text-xs font-semibold text-brand-ink underline">{{ __('Sleep, region, scheduler') }}</button>
                    @else
                        <p class="mt-2 text-xs text-brand-moss">{{ __('This app is served as :mode. No container size to pick.', ['mode' => $runtimeMode]) }}</p>
                    @endif
                </div>
                <div class="flex min-w-0 flex-col gap-1" style="grid-column: 3; grid-row: 2">
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
                                @elseif ($connection['kind'] === 'redis')
                                    <button type="button" wire:click="openRedis('{{ $connection['host'] }}')" x-on:click="$dispatch('open-modal', 'resources-redis')" class="text-xs font-semibold text-brand-ink underline">{{ __('Settings') }}</button>
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
                        @elseif ($connection['kind'] === 'redis' && \App\Modules\Edge\Services\EdgeRedisUsageCollector::isProvisionedId($connection['target']) && ! $site->organization?->onAnyPaidPlan())
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
                        @if ($connection['kind'] === 'queue' && isset($queueOwners[$connection['target']]))
                            <p class="mt-1 text-xs text-brand-moss">{{ __('Sends only. :app runs these jobs.', ['app' => $queueOwners[$connection['target']]]) }}</p>
                        @endif
                        @if (in_array($connection['name'], $overriddenByRepo, true))
                            <p class="mt-1 text-xs font-semibold text-brand-ink">{{ __('Overridden by wrangler.toml. The repo binding is used.') }}</p>
                        @endif
                        @if (isset($connectionEstimates[$connection['host']]))
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
                    <p class="text-xs font-semibold text-brand-ink">{{ __('Database') }}</p>
                    @if ($databaseEngine === 'postgres')
                        <p class="mt-2 text-xs text-brand-moss">
                            @if ($postgresPlan === 'awake')
                                {{ __('Postgres. 1 compute · :cpu · :memory · stays on · about $:hour/hour, $:day/day, $:month/month. Storage about $:gigabyte/GB each month.', ['cpu' => $postgresSizes[$postgresSize]['cpu'], 'memory' => $postgresSizes[$postgresSize]['memory'], 'hour' => $postgresSizes[$postgresSize]['hour'], 'day' => $postgresSizes[$postgresSize]['day'], 'month' => $postgresSizes[$postgresSize]['month'], 'gigabyte' => $postgresGigabyte]) }}
                            @elseif ($postgresSize === '0.25')
                                {{ __('Postgres. 1 compute · :cpu · :memory · sleeps after :sleep · about $:hour/hour, $:day/day, $:month/month at :hours hours awake. Storage about $:gigabyte/GB each month.', ['cpu' => $postgresSizes[$postgresSize]['cpu'], 'memory' => $postgresSizes[$postgresSize]['memory'], 'sleep' => __($postgresSleeps[$postgresSuspend]), 'hour' => $postgresSizes[$postgresSize]['hour'], 'day' => $postgresSizes[$postgresSize]['day'], 'month' => $postgresSizes[$postgresSize]['month'], 'hours' => $postgresAwakeHours, 'gigabyte' => $postgresGigabyte]) }}
                            @else
                                {{ __('Postgres. 1 compute · 1/4 vCPU to :cpu · 1 GB to :memory · sleeps after :sleep · about $:hour/hour, $:day/day, $:month/month at full size for :hours hours awake. Storage about $:gigabyte/GB each month.', ['cpu' => $postgresSizes[$postgresSize]['cpu'], 'memory' => $postgresSizes[$postgresSize]['memory'], 'sleep' => __($postgresSleeps[$postgresSuspend]), 'hour' => $postgresSizes[$postgresSize]['hour'], 'day' => $postgresSizes[$postgresSize]['day'], 'month' => $postgresSizes[$postgresSize]['month'], 'hours' => $postgresAwakeHours, 'gigabyte' => $postgresGigabyte]) }}
                            @endif
                            {{ __('In :location.', ['location' => $postgresRegions[$postgresRegion]]) }}
                            @if ($postgresStored['recorded'])
                                {{ __('Stored :gigabytes GB, about $:month this month.', ['gigabytes' => $postgresStored['gigabytes'], 'month' => $postgresStored['month']]) }}
                            @else
                                {{ __('No storage recorded yet.') }}
                            @endif
                        </p>
                    @elseif ($databaseEngine === 'mysql')
                        <p class="mt-2 text-xs text-brand-moss">{{ __('MySQL. 3 nodes · :cpu · :memory · about $:price/mo. The nodes stay on while the app sleeps.', ['cpu' => $mysqlSizes[$mysqlSize]['cpu'], 'memory' => $mysqlSizes[$mysqlSize]['memory'], 'price' => $mysqlMonthly]) }}</p>
                        @if ($databaseStatus === 'provisioning')
                            <p class="mt-1 text-xs text-brand-ink">{{ __('Starting. Redeploy after the address is ready.') }}</p>
                        @elseif ($databaseStatus === 'failed')
                            <p class="mt-1 text-xs text-brand-ink">{{ __('MySQL did not start. Save again to retry.') }}</p>
                        @endif
                    @elseif ($databaseEngine === 'sql')
                        <p class="mt-2 text-xs text-brand-moss">{{ __('SQLite. A file inside the app. It is saved while the app runs and restored when the app wakes.') }}</p>
                    @else
                        <p class="mt-2 text-xs text-brand-moss">{{ __('No database.') }}</p>
                    @endif
                    @error('database')
                        <p class="mt-2 text-xs text-brand-ink">{{ $message }}</p>
                    @enderror
                    @if (! $site->organization?->onAnyPaidPlan())
                        <p class="mt-2 text-xs text-brand-ink">{{ __('Add a card before starting Postgres. It is billed to that card. SQLite does not need one.') }}</p>
                        @if ($site->organization)
                            <a href="{{ route('billing.show', $site->organization) }}" class="mt-1 inline-block text-xs font-semibold text-brand-ink underline">{{ __('Billing') }}</a>
                        @endif
                    @endif
                    <div class="mt-3 grid gap-1.5" role="radiogroup" aria-label="{{ __('Database') }}">
                        @foreach (['postgres' => __('Postgres'), 'mysql' => __('MySQL'), 'sql' => __('SQLite')] as $engine => $label)
                            @if ($engine === 'mysql')
                                <button type="button" disabled aria-disabled="true" class="flex items-center justify-between gap-2 rounded-lg border border-brand-ink/10 bg-white/70 px-2.5 py-1.5 text-left text-xs font-semibold text-brand-moss dark:bg-zinc-900/70">
                                    <span>{{ $label }}</span>
                                    <span class="shrink-0 rounded-full bg-brand-sand/60 px-2 py-0.5 text-xs font-semibold uppercase tracking-wide text-brand-moss">{{ __('Coming soon') }}</span>
                                </button>
                            @elseif ($engine === 'postgres' && ! $site->organization?->onAnyPaidPlan())
                                <button type="button" disabled aria-disabled="true" class="flex items-center justify-between gap-2 rounded-lg border border-brand-ink/10 bg-white/70 px-2.5 py-1.5 text-left text-xs font-semibold text-brand-moss dark:bg-zinc-900/70">
                                    <span>{{ $label }}</span>
                                    <span class="shrink-0 text-xs font-semibold">{{ __('Add a card') }}</span>
                                </button>
                            @else
                                <button type="button" wire:click="selectDatabase('{{ $engine }}')" @class([
                                    'rounded-lg border px-2.5 py-1.5 text-left text-xs font-semibold',
                                    'border-brand-sage bg-white text-brand-ink dark:bg-zinc-900' => $databaseEngine === $engine,
                                    'border-brand-ink/10 bg-white/70 text-brand-ink dark:bg-zinc-900/70' => $databaseEngine !== $engine,
                                ])>{{ $label }}</button>
                            @endif
                        @endforeach
                    </div>
                    <div class="mt-auto flex gap-3 pt-3">
                        <button type="button" x-on:click="$dispatch('database-tab', 'how'); $dispatch('open-modal', 'resources-app-database')" class="text-left text-xs font-semibold text-brand-ink underline">{{ __('How to use') }}</button>
                        @if ($databaseEngine !== 'none')
                            <button type="button" x-on:click="$dispatch('database-tab', 'settings'); $dispatch('open-modal', 'resources-app-database')" class="text-left text-xs font-semibold text-brand-ink underline">{{ __('Settings') }}</button>
                        @endif
                        <button type="button" wire:click="selectDatabase('none')" class="text-left text-xs font-semibold text-brand-ink underline">{{ __('Remove') }}</button>
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
    <x-modal name="resources-redis" :show="$redisHost !== ''" maxWidth="3xl" focusable>
        <div class="max-h-[80vh] overflow-y-auto bg-white p-5 dark:bg-zinc-900">
            <div class="flex items-start justify-between gap-3">
                <h2 class="text-xs font-semibold uppercase tracking-[0.16em] text-brand-sage">{{ __('Redis') }}</h2>
                <button type="button" wire:click="closeRedis" x-on:click="$dispatch('close-modal', 'resources-redis')" class="text-xs font-semibold text-brand-ink underline">{{ __('Close') }}</button>
            </div>
            @if ($redisHost === '')
                <div class="mt-4 space-y-4" role="status" aria-live="polite">
                    <p class="text-xs text-brand-moss">{{ __('Loading Redis…') }}</p>
                    <div class="flex gap-4 border-b border-brand-ink/10 pb-2">
                        @foreach (['w-16', 'w-12', 'w-14', 'w-12', 'w-10', 'w-16', 'w-16'] as $width)
                            <span class="{{ $width }} h-3 animate-pulse rounded bg-brand-ink/10 dark:bg-white/10"></span>
                        @endforeach
                    </div>
                    <div class="space-y-3">
                        <span class="block h-3 w-20 animate-pulse rounded bg-brand-ink/10 dark:bg-white/10"></span>
                        <span class="block h-4 w-36 animate-pulse rounded bg-brand-ink/15 dark:bg-white/15"></span>
                        <span class="block h-3 w-20 animate-pulse rounded bg-brand-ink/10 dark:bg-white/10"></span>
                        <span class="block h-4 w-28 animate-pulse rounded bg-brand-ink/15 dark:bg-white/15"></span>
                    </div>
                </div>
            @else
            <div class="mt-4" x-data="{ tab: 'connection' }">
                <div class="flex gap-4 overflow-x-auto border-b border-brand-ink/10" role="tablist">
                    <button type="button" role="tab" x-on:click="tab = 'connection'" :aria-selected="tab === 'connection'" :class="tab === 'connection' ? 'border-brand-sage text-brand-ink' : 'border-transparent text-brand-moss'" class="-mb-px shrink-0 border-b-2 pb-2 text-xs font-semibold">{{ __('Connection') }}</button>
                    <button type="button" role="tab" x-on:click="tab = 'implementation'" :aria-selected="tab === 'implementation'" :class="tab === 'implementation' ? 'border-brand-sage text-brand-ink' : 'border-transparent text-brand-moss'" class="-mb-px shrink-0 border-b-2 pb-2 text-xs font-semibold">{{ __('Implementation') }}</button>
                    <button type="button" role="tab" x-on:click="tab = 'info'" :aria-selected="tab === 'info'" :class="tab === 'info' ? 'border-brand-sage text-brand-ink' : 'border-transparent text-brand-moss'" class="-mb-px shrink-0 border-b-2 pb-2 text-xs font-semibold">{{ __('Info') }}</button>
                    @if ($redisManaged)
                        <button type="button" role="tab" x-on:click="tab = 'usage'" :aria-selected="tab === 'usage'" :class="tab === 'usage' ? 'border-brand-sage text-brand-ink' : 'border-transparent text-brand-moss'" class="-mb-px border-b-2 pb-2 text-xs font-semibold">{{ __('Usage') }}</button>
                        <button type="button" role="tab" x-on:click="tab = 'costs'" :aria-selected="tab === 'costs'" :class="tab === 'costs' ? 'border-brand-sage text-brand-ink' : 'border-transparent text-brand-moss'" class="-mb-px border-b-2 pb-2 text-xs font-semibold">{{ __('Costs') }}</button>
                        <button type="button" role="tab" x-on:click="tab = 'test'" :aria-selected="tab === 'test'" :class="tab === 'test' ? 'border-brand-sage text-brand-ink' : 'border-transparent text-brand-moss'" class="-mb-px border-b-2 pb-2 text-xs font-semibold">{{ __('Test') }}</button>
                        <button type="button" role="tab" x-on:click="tab = 'settings'" :aria-selected="tab === 'settings'" :class="tab === 'settings' ? 'border-brand-sage text-brand-ink' : 'border-transparent text-brand-moss'" class="-mb-px border-b-2 pb-2 text-xs font-semibold">{{ __('Settings') }}</button>
                        <button type="button" role="tab" x-on:click="tab = 'backups'" :aria-selected="tab === 'backups'" :class="tab === 'backups' ? 'border-brand-sage text-brand-ink' : 'border-transparent text-brand-moss'" class="-mb-px border-b-2 pb-2 text-xs font-semibold">{{ __('Backups') }}</button>
                    @endif
                </div>
                <x-input-error :messages="$errors->get('redisSettings')" class="mt-3" />
                <div x-show="tab === 'connection'" class="mt-4">
                    <p class="text-xs text-brand-moss">{{ __('The app connects with this username and password. They apply on the next deploy.') }}</p>
                    <dl class="mt-4 space-y-3 text-xs">
                        <div>
                            <dt class="text-brand-moss">{{ __('Username') }}</dt>
                            <dd class="mt-1 font-mono text-brand-ink">{{ $redisUser }}</dd>
                        </div>
                        <div>
                            <dt class="text-brand-moss">{{ __('Password') }}</dt>
                            <dd class="mt-1 flex items-center gap-2">
                                <span class="font-mono text-brand-ink">{{ $redisShowPassword ? $redisPassword : '••••••••' }}</span>
                                <button type="button" wire:click="$toggle('redisShowPassword')" class="font-semibold text-brand-ink underline">{{ $redisShowPassword ? __('Hide') : __('Show') }}</button>
                            </dd>
                        </div>
                    </dl>
                    @if ($redisManaged)
                        <button type="button" wire:click="resetRedisPassword" class="mt-4 rounded-md border border-brand-ink/15 px-3 py-1.5 text-xs font-semibold text-brand-ink">{{ $redisResetArmed ? __('Reset password now') : __('Reset password') }}</button>
                        @if ($redisResetArmed)
                            <p class="mt-2 text-xs text-brand-moss">{{ __('This replaces the password. The running app keeps the old one until the next deploy.') }}</p>
                        @endif
                    @else
                        <p class="mt-4 text-xs text-brand-moss">{{ __('Stats and settings are available for Redis started here. A pasted address keeps the username and password above.') }}</p>
                    @endif
                </div>
                <div x-show="tab === 'implementation'" x-cloak class="mt-4">
                    <p class="text-xs font-semibold text-brand-ink">{{ __('Laravel') }}</p>
                    <p class="mt-1 max-w-xl text-xs text-brand-moss">{{ __('The next deploy sets REDIS_URL and the host, port, username, and password. When this address is awake, CACHE_STORE is redis.') }}</p>
                    <pre class="mt-2 overflow-x-auto rounded-lg bg-brand-sand/40 p-3 text-xs text-brand-ink dark:bg-zinc-950">{{ "Cache::store('redis')->put('session', 'hello');\nCache::store('redis')->get('session');\nRedis::get('session');" }}</pre>
                    <p class="mt-4 text-xs font-semibold text-brand-ink">{{ __('Rails') }}</p>
                    <p class="mt-1 max-w-xl text-xs text-brand-moss">{{ __('Add dply-rails. The next deploy sets REDIS_URL. Rails.cache uses Redis when that is set.') }}</p>
                    <pre class="mt-2 overflow-x-auto rounded-lg bg-brand-sand/40 p-3 text-xs text-brand-ink dark:bg-zinc-950">gem "dply-rails"</pre>
                    <pre class="mt-2 overflow-x-auto rounded-lg bg-brand-sand/40 p-3 text-xs text-brand-ink dark:bg-zinc-950">{{ "Rails.cache.write('session', 'hello')\nRails.cache.read('session')\nRails.cache.delete('session')" }}</pre>
                </div>
                <div x-show="tab === 'info'" x-cloak class="mt-4">
                    <p class="text-xs text-brand-moss">{{ __('These are injected on the next deploy.') }}</p>
                    <ul class="mt-3 space-y-1 font-mono text-xs text-brand-ink">
                        @foreach (\App\Modules\Edge\Support\EdgeContainerConnections::redisInjectionPreview($site) as $row)
                            <li>{{ $row['key'] }}={{ $row['value'] }}</li>
                        @endforeach
                    </ul>
                </div>
            @if ($redisManaged)
                <div x-show="tab === 'usage'" x-cloak class="mt-4">
                    <div class="grid grid-cols-2 gap-3 sm:grid-cols-4">
                        @foreach ($redisStats as $label => $value)
                            <div>
                                <p class="text-xs text-brand-moss">{{ $label }}</p>
                                <p class="mt-1 text-xs font-semibold text-brand-ink">{{ $value }}</p>
                            </div>
                        @endforeach
                    </div>
                    @if ($redisState !== '' || $redisRegionLabel !== '')
                        <p class="mt-3 text-xs text-brand-moss">{{ trim($redisState.' · '.$redisRegionLabel, ' ·') }}</p>
                    @endif
                </div>
                <div x-show="tab === 'costs'" x-cloak class="mt-4">
                    <p class="text-xs text-brand-moss">{{ __('This month') }}</p>
                    <p class="mt-1 text-xl font-semibold tabular-nums text-brand-ink">${{ number_format($redisMonthCents / 100, 2) }}</p>
                    @if ($redisPlan === 'free')
                        <p class="mt-2 max-w-xl text-xs text-brand-moss">{{ __('This Redis is on the free size, so this month is $0. Pick a paid size under Settings to raise the limits.') }}</p>
                    @elseif (str_starts_with($redisPlan, 'fixed_'))
                        <p class="mt-2 max-w-xl text-xs text-brand-moss">{{ __('Flat price for this size. Commands are included. Storage and bandwidth are included up to the size. Each extra read region adds half of that price. The first 10 databases are included. Each database after that is $1 a month.') }}</p>
                    @else
                        <p class="mt-2 max-w-xl text-xs text-brand-moss">{{ __('Estimate for this month so far. The first 1 GB of storage and 200 GB of bandwidth are included, then storage is $0.50 per GB and bandwidth is $0.05 per GB. Commands are billed with usage. The first 10 databases are included. Each database after that is $1 a month.') }}</p>
                    @endif
                    <dl class="mt-4 grid max-w-xs grid-cols-[1fr_auto] gap-x-6 gap-y-2 text-xs">
                        <dt class="text-brand-moss">{{ __('Commands this month') }}</dt>
                        <dd class="font-semibold tabular-nums text-brand-ink">{{ $redisStats[__('Commands this month')] ?? '0' }}</dd>
                        <dt class="text-brand-moss">{{ __('Storage') }}</dt>
                        <dd class="font-semibold tabular-nums text-brand-ink">{{ $redisStats[__('Storage')] ?? '0 B' }}</dd>
                        <dt class="text-brand-moss">{{ __('Bandwidth this month') }}</dt>
                        <dd class="font-semibold tabular-nums text-brand-ink">{{ $redisStats[__('Bandwidth this month')] ?? '0 B' }}</dd>
                    </dl>
                </div>
                <div x-show="tab === 'test'" x-cloak class="mt-4 space-y-3">
                    <p class="text-xs text-brand-moss">{{ __('Run a command on this Redis. Ping checks the connection. Get, set, and delete use the key below.') }}</p>
                    <label class="block text-xs text-brand-moss">
                        {{ __('Command') }}
                        <select wire:model.live="redisTestAction" class="mt-1 block w-full rounded-lg border border-brand-ink/15 bg-white px-3 py-2 text-sm text-brand-ink dark:bg-zinc-900">
                            <option value="ping">{{ __('Ping') }}</option>
                            <option value="get">{{ __('Get') }}</option>
                            <option value="set">{{ __('Set') }}</option>
                            <option value="del">{{ __('Delete') }}</option>
                        </select>
                    </label>
                    @if ($redisTestAction !== 'ping')
                        <label class="block text-xs text-brand-moss">
                            {{ __('Key') }}
                            <input type="text" wire:model="redisTestKey" class="mt-1 block w-full rounded-lg border border-brand-ink/15 bg-white px-3 py-2 text-sm text-brand-ink dark:bg-zinc-900" />
                        </label>
                    @endif
                    @if ($redisTestAction === 'set')
                        <label class="block text-xs text-brand-moss">
                            {{ __('Value') }}
                            <input type="text" wire:model="redisTestValue" class="mt-1 block w-full rounded-lg border border-brand-ink/15 bg-white px-3 py-2 text-sm text-brand-ink dark:bg-zinc-900" />
                        </label>
                    @endif
                    <button type="button" wire:click="runRedisTest" wire:loading.attr="disabled" wire:target="runRedisTest" class="rounded-md bg-brand-ink px-3 py-1.5 text-xs font-semibold text-white disabled:opacity-60">{{ __('Run') }}</button>
                    @if ($redisTestResult !== '')
                        <pre class="overflow-x-auto rounded-lg bg-brand-sand/40 p-3 text-xs text-brand-ink dark:bg-zinc-950">{{ $redisTestResult }}</pre>
                    @endif
                </div>
                <div x-show="tab === 'settings'" x-cloak class="mt-4 space-y-5">
                    <section class="space-y-2 border-b border-brand-ink/10 pb-4">
                        <h3 class="text-xs font-semibold text-brand-ink">{{ __('Name') }}</h3>
                        <p class="text-xs text-brand-moss">{{ __('How this Redis is labeled. Letters, numbers, dashes, and underscores.') }}</p>
                        <input type="text" wire:model="redisName" class="block w-full rounded-lg border border-brand-ink/15 bg-white px-3 py-2 text-sm text-brand-ink dark:bg-zinc-900" />
                    </section>
                    <section class="space-y-2 border-b border-brand-ink/10 pb-4">
                        <h3 class="text-xs font-semibold text-brand-ink">{{ __('Size') }}</h3>
                        @if ($redisPlan === 'free')
                            <p class="text-xs text-brand-moss">{{ __('This is the free size, 256 MB. Pick a paid size to raise the limits.') }}</p>
                        @elseif ($redisPlan === 'payg')
                            <p class="text-xs text-brand-moss">{{ __('Pay as you go bills commands, storage, and bandwidth. A fixed size is one monthly price, and commands are included.') }}</p>
                        @else
                            @php $fixed = app(\App\Modules\Billing\Services\EdgeRedisCost::class)->fixedPlans()[$redisPlan] ?? null; @endphp
                            <p class="text-xs text-brand-moss">{{ __('Holds :data and :bandwidth of bandwidth each month. Commands are included. When it is full, writes slow down.', ['data' => $fixed['data'] ?? '', 'bandwidth' => $fixed['bandwidth'] ?? '']) }}</p>
                        @endif
                        <select wire:model.live="redisPlan" class="block w-full rounded-lg border border-brand-ink/15 bg-white px-3 py-2 text-sm text-brand-ink dark:bg-zinc-900">
                            @if ($redisPlan === 'free')
                                <option value="free">{{ __('Free') }}</option>
                            @endif
                            <option value="payg">{{ __('Pay as you go') }}</option>
                            @foreach (app(\App\Modules\Billing\Services\EdgeRedisCost::class)->fixedPlans() as $id => $plan)
                                <option value="{{ $id }}">{{ __($plan['label']) }} · ${{ number_format($plan['cents'] / 100, 0) }}/mo</option>
                            @endforeach
                        </select>
                    </section>
                    @if ($redisPlan === 'payg')
                    <section class="space-y-2 border-b border-brand-ink/10 pb-4">
                        <h3 class="text-xs font-semibold text-brand-ink">{{ __('Monthly budget') }}</h3>
                        <p class="text-xs text-brand-moss">{{ __('0 means no cap. The smallest cap is $20. At the cap, Redis slows down so the month does not go past it.') }}</p>
                        <label class="block text-xs text-brand-moss">
                            {{ __('Dollars') }}
                            <input type="number" min="0" max="10000" wire:model="redisBudget" class="mt-1 block w-full rounded-lg border border-brand-ink/15 bg-white px-3 py-2 text-sm text-brand-ink dark:bg-zinc-900" />
                        </label>
                    </section>
                    @endif
                    <section class="space-y-3 border-b border-brand-ink/10 pb-4">
                        <h3 class="text-xs font-semibold text-brand-ink">{{ __('Data') }}</h3>
                        <label class="flex items-start justify-between gap-3 text-xs text-brand-ink">
                            <span>
                                <span class="font-semibold">{{ __('Drop keys when full') }}</span>
                                <span class="mt-1 block text-brand-moss">{{ __('When Redis is full, drop the least recently used keys so new writes still succeed. Off means a write fails once it is full.') }}</span>
                            </span>
                            <input type="checkbox" wire:model="redisEviction" class="mt-0.5 rounded border-brand-ink/20" />
                        </label>
                        <label class="flex items-start justify-between gap-3 text-xs text-brand-ink">
                            <span>
                                <span class="font-semibold">{{ __('Daily backup') }}</span>
                                <span class="mt-1 block text-brand-moss">{{ __('Save a copy of the data once a day. Take or restore a single backup on the Backups tab.') }}</span>
                            </span>
                            <input type="checkbox" wire:model="redisDailyBackup" class="mt-0.5 rounded border-brand-ink/20" />
                        </label>
                        <div class="flex items-start justify-between gap-3 text-xs text-brand-ink">
                            <span>
                                <span class="font-semibold">{{ __('TLS') }}</span>
                                <span class="mt-1 block text-brand-moss">{{ __('Encrypts traffic between the app and Redis. Once it is on, it stays on.') }}</span>
                            </span>
                            @if ($redisTls)
                                <span class="font-semibold">{{ __('On') }}</span>
                            @else
                                <input type="checkbox" wire:model="redisTls" class="mt-0.5 rounded border-brand-ink/20" />
                            @endif
                        </div>
                    </section>
                    <fieldset class="space-y-2 text-xs text-brand-ink">
                        <legend class="text-xs font-semibold">{{ __('Read regions') }}</legend>
                        <p class="text-brand-moss">{{ __('A read region answers reads closer to visitors. Writes still go to :region.', ['region' => $redisRegionLabel !== '' ? $redisRegionLabel : __('the primary region')]) }} @if (str_starts_with($redisPlan, 'fixed_')){{ __('Each extra region adds half the monthly price.') }}@else{{ __('Each extra region uses more storage and bandwidth.') }}@endif</p>
                        <div class="mt-2 grid gap-1 sm:grid-cols-2">
                            @foreach (\App\Modules\Providers\Upstash\UpstashRedisClient::REGIONS as $id => $label)
                                @if ($id !== $redisPrimaryRegion)
                                    <label class="flex items-center gap-2">
                                        <input type="checkbox" value="{{ $id }}" wire:model="redisReadRegions" class="rounded border-brand-ink/20" />
                                        <span>{{ $label }}</span>
                                    </label>
                                @endif
                            @endforeach
                        </div>
                    </fieldset>
                    <div class="flex flex-wrap gap-2">
                        <button type="button" wire:click="saveRedisSettings" class="rounded-md bg-brand-ink px-3 py-1.5 text-xs font-semibold text-white">{{ __('Save settings') }}</button>
                    </div>
                </div>
                <div x-show="tab === 'backups'" x-cloak class="mt-4">
                        <p class="text-xs font-semibold text-brand-ink">{{ __('Backups') }}</p>
                        @if ($redisBackups === [])
                            <p class="mt-1 text-xs text-brand-moss">{{ __('No backups yet.') }}</p>
                        @else
                            <ul class="mt-2 divide-y divide-brand-ink/10 text-xs">
                                @foreach ($redisBackups as $backup)
                                    <li class="flex flex-wrap items-center justify-between gap-2 py-2" wire:key="redis-backup-{{ $backup['id'] }}">
                                        <span class="text-brand-ink">{{ $backup['name'] }} · {{ $backup['state'] }} · {{ $backup['size'] }}</span>
                                        <span class="flex gap-2">
                                            <button type="button" wire:click="restoreRedisBackup('{{ $backup['id'] }}')" class="font-semibold text-brand-ink underline">{{ $redisBackupArmed === 'restore:'.$backup['id'] ? __('Restore now') : __('Restore') }}</button>
                                            <button type="button" wire:click="deleteRedisBackup('{{ $backup['id'] }}')" class="font-semibold text-brand-ink underline">{{ $redisBackupArmed === 'delete:'.$backup['id'] ? __('Delete now') : __('Delete') }}</button>
                                        </span>
                                    </li>
                                @endforeach
                            </ul>
                        @endif
                        @if ($redisBackupArmed !== '')
                            <p class="mt-1 text-xs text-brand-moss">{{ __('Restore replaces the data in this Redis. Delete removes that backup only.') }}</p>
                        @endif
                        <label class="mt-2 block text-xs text-brand-moss">
                            {{ __('Backup name') }}
                            <input type="text" wire:model="redisBackupName" class="mt-1 block w-full rounded-lg border border-brand-ink/15 bg-white px-3 py-2 text-sm text-brand-ink dark:bg-zinc-900" />
                        </label>
                        <button type="button" wire:click="createRedisBackup" class="mt-2 rounded-md border border-brand-ink/15 px-3 py-1.5 text-xs font-semibold text-brand-ink">{{ __('Take backup') }}</button>
                </div>
            @endif
            </div>
            @endif
        </div>
    </x-modal>

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
                        @unless ($site->organization?->onAnyPaidPlan())
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

    <x-modal name="resources-connection" :show="$panel === 'connection'" maxWidth="lg" focusable>
        <div class="space-y-4 bg-white p-5 dark:bg-zinc-900">
            @if ($connectionKind === '')
                <div>
                    <h2 class="text-sm font-semibold text-brand-ink">{{ __('Add a resource') }}</h2>
                </div>
                <div class="grid gap-2 sm:grid-cols-3">
                    <button type="button" wire:click="addDatabase" x-on:click="$dispatch('close-modal', 'resources-connection')" class="flex items-center gap-2 rounded-xl border border-brand-ink/10 px-3 py-2 text-left text-xs font-semibold text-brand-ink hover:border-brand-sage">
                        <x-resource-kind-icon kind="database" />
                        {{ __('Database') }}
                    </button>
                    @foreach ($connectionKinds as $key => $kind)
                        @continue($key === 'http_delivery' || ! in_array($key, $allowedKinds, true))
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
                    @if ($connectionMode === 'create' && $connectionKind === 'key_value' && ! $site->organization?->onAnyPaidPlan())
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
                    @elseif ($connectionKind === 'http_delivery' && ! $site->organization?->onAnyPaidPlan())
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
                    @elseif ($connectionKind === 'redis' && $connectionMode === 'create' && ! $site->organization?->onAnyPaidPlan())
                        <p class="text-xs text-brand-moss">{{ __('Add a card before starting Redis. Usage is billed to that card.') }}</p>
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
                            <select wire:model="redisPlan" class="mt-1 block w-full rounded-lg border border-brand-ink/15 bg-white px-3 py-2 text-sm text-brand-ink dark:bg-zinc-900">
                                <option value="payg">{{ __('Pay as you go') }}</option>
                                @foreach (app(\App\Modules\Billing\Services\EdgeRedisCost::class)->fixedPlans() as $id => $plan)
                                    <option value="{{ $id }}">{{ __($plan['label']) }} · ${{ number_format($plan['cents'] / 100, 0) }}/mo</option>
                                @endforeach
                            </select>
                        </label>
                        <label class="block text-xs text-brand-moss">
                            {{ __('Region') }}
                            <select wire:model="redisRegion" class="mt-1 block w-full rounded-lg border border-brand-ink/15 bg-white px-3 py-2 text-sm text-brand-ink dark:bg-zinc-900">
                                @foreach (\App\Modules\Edge\Support\EdgeContainerConnections::redisRegions() as $id => $label)
                                    <option value="{{ $id }}">{{ __($label) }}</option>
                                @endforeach
                            </select>
                        </label>
                        <p class="text-xs text-brand-moss">{{ __('This starts a Redis for this app in the region you pick. Commands are billed with usage. The first 1 GB of storage and 200 GB of bandwidth each month are included, then storage is $0.50 per GB and bandwidth is $0.05 per GB. The first 10 databases are included. Each database after that is $1 a month. The address is set on the next deploy and is not shown.') }}</p>
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
                    @if (! (($connectionKind === 'redis' && $connectionMode === 'create' || $connectionKind === 'http_delivery' || $connectionKind === 'key_value' && $connectionMode === 'create') && ! $site->organization?->onAnyPaidPlan()))
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
                <p class="mt-1 text-xs text-brand-moss">{{ __('Leave all unchecked to use every region inside the choice above.') }}</p>
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
                    <span class="block text-xs text-brand-moss">{{ __('Calls schedule:run through dply/laravel.') }}</span>
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

    <x-modal name="resources-app-database" maxWidth="3xl" focusable>
        <div class="max-h-[80vh] overflow-y-auto bg-white p-5 dark:bg-zinc-900" x-data="{ tab: 'how' }" x-on:database-tab.window="tab = Array.isArray($event.detail) ? $event.detail[0] : $event.detail">
            <div class="flex items-start justify-between gap-3">
                <h2 class="text-xs font-semibold uppercase tracking-[0.16em] text-brand-sage">{{ __('Database') }}</h2>
                <button type="button" x-on:click="$dispatch('close-modal', 'resources-app-database')" class="text-xs font-semibold text-brand-ink underline">{{ __('Close') }}</button>
            </div>
            <div class="mt-4 flex gap-4 overflow-x-auto border-b border-brand-ink/10" role="tablist">
                <button type="button" role="tab" x-on:click="tab = 'how'" :aria-selected="tab === 'how'" :class="tab === 'how' ? 'border-brand-sage text-brand-ink' : 'border-transparent text-brand-moss'" class="-mb-px shrink-0 border-b-2 pb-2 text-xs font-semibold">{{ __('How it works') }}</button>
                <button type="button" role="tab" x-on:click="tab = 'implementation'" :aria-selected="tab === 'implementation'" :class="tab === 'implementation' ? 'border-brand-sage text-brand-ink' : 'border-transparent text-brand-moss'" class="-mb-px shrink-0 border-b-2 pb-2 text-xs font-semibold">{{ __('Implementation') }}</button>
                @if ($databaseEngine !== 'none')
                    <button type="button" role="tab" x-on:click="tab = 'settings'" :aria-selected="tab === 'settings'" :class="tab === 'settings' ? 'border-brand-sage text-brand-ink' : 'border-transparent text-brand-moss'" class="-mb-px shrink-0 border-b-2 pb-2 text-xs font-semibold">{{ __('Settings') }}</button>
                @endif
            </div>
            @if (in_array($databaseEngine, ['postgres', 'mysql'], true) && ! $site->organization?->onAnyPaidPlan())
                <p class="mt-4 text-xs text-brand-ink">{{ __('Add a card before starting a database. It is billed to that card.') }}</p>
                @if ($site->organization)
                    <a href="{{ route('billing.show', $site->organization) }}" class="mt-1 inline-block text-xs font-semibold text-brand-ink underline">{{ __('Billing') }}</a>
                @endif
            @endif
            <div x-show="tab === 'how'" class="mt-4">
                @if ($databaseEngine === 'postgres')
                    <ol class="list-decimal space-y-1 pl-4 text-xs text-brand-ink">
                        <li>{{ __('Save and redeploy. The next deploy sets the database address on the app.') }}</li>
                        @if ($postgresPlan === 'awake')
                            <li>{{ __('This plan stays on at :memory, about $:hour/hour, $:day/day, $:month/month.', ['memory' => $postgresSizes[$postgresSize]['memory'], 'hour' => $postgresSizes[$postgresSize]['hour'], 'day' => $postgresSizes[$postgresSize]['day'], 'month' => $postgresSizes[$postgresSize]['month']]) }}</li>
                        @elseif ($postgresSize === '0.25')
                            <li>{{ __('This plan sleeps :sleep after the last connection. 1 GB is about $:hour/hour, $:day/day, $:month/month at :hours hours awake.', ['sleep' => __($postgresSleeps[$postgresSuspend]), 'hour' => $postgresSizes[$postgresSize]['hour'], 'day' => $postgresSizes[$postgresSize]['day'], 'month' => $postgresSizes[$postgresSize]['month'], 'hours' => $postgresAwakeHours]) }}</li>
                        @else
                            <li>{{ __('This plan sleeps :sleep after the last connection. It starts at 1 GB and grows to :memory, about $:hour/hour, $:day/day, $:month/month at full size for :hours hours awake.', ['sleep' => __($postgresSleeps[$postgresSuspend]), 'memory' => $postgresSizes[$postgresSize]['memory'], 'hour' => $postgresSizes[$postgresSize]['hour'], 'day' => $postgresSizes[$postgresSize]['day'], 'month' => $postgresSizes[$postgresSize]['month'], 'hours' => $postgresAwakeHours]) }}</li>
                        @endif
                        <li>{{ __('Storage is about $:gigabyte/GB each month, including while compute sleeps. Connections require TLS.', ['gigabyte' => $postgresGigabyte]) }}</li>
                    </ol>
                @elseif ($databaseEngine === 'mysql')
                    <ol class="list-decimal space-y-1 pl-4 text-xs text-brand-ink">
                        <li>{{ __('Save starts MySQL. The address can take a minute. Redeploy after it is ready.') }}</li>
                        <li>{{ __('About $:price/mo for 3 nodes: 1 primary and 2 replicas. They stay on while the app sleeps.', ['price' => $mysqlMonthly]) }}</li>
                        <li>{{ __('Foreign keys are on. Connections require TLS.') }}</li>
                    </ol>
                @elseif ($databaseEngine === 'sql')
                    <p class="text-xs text-brand-ink">{{ __('SQLite is a file inside the app. It is saved while the app runs and restored when the app wakes. One instance serves the app so that file stays consistent.') }}</p>
                @else
                    <p class="text-xs text-brand-ink">{{ __('No database is attached. Pick Postgres, MySQL, or SQLite, then save and redeploy.') }}</p>
                @endif
            </div>
            <div x-show="tab === 'implementation'" x-cloak class="mt-4">
                @if ($databaseEngine === 'postgres' || $databaseEngine === 'mysql')
                    <p class="text-xs font-semibold text-brand-ink">{{ __('Laravel') }}</p>
                    <p class="mt-1 max-w-xl text-xs text-brand-moss">{{ __('The next deploy sets DB_CONNECTION, the host, the password, and DATABASE_URL.') }}</p>
                    <pre class="mt-2 overflow-x-auto rounded-lg bg-brand-sand/40 p-3 text-xs text-brand-ink dark:bg-zinc-950">DB::table('users')->count();</pre>
                    <p class="mt-4 text-xs font-semibold text-brand-ink">{{ __('Rails') }}</p>
                    <p class="mt-1 max-w-xl text-xs text-brand-moss">{{ __('The next deploy sets DATABASE_URL. ActiveRecord uses it.') }}</p>
                    <pre class="mt-2 overflow-x-auto rounded-lg bg-brand-sand/40 p-3 text-xs text-brand-ink dark:bg-zinc-950">User.count</pre>
                @elseif ($databaseEngine === 'sql')
                    <p class="text-xs text-brand-moss">{{ __('The next deploy sets DB_CONNECTION to sqlite and DB_DATABASE to /tmp/database.sqlite.') }}</p>
                @else
                    <p class="text-xs text-brand-moss">{{ __('Pick a database first.') }}</p>
                @endif
            </div>
            @if ($databaseEngine !== 'none')
                <div x-show="tab === 'settings'" x-cloak class="mt-4 space-y-3">
                    <label class="flex items-start gap-2 text-xs text-brand-ink">
                        <input type="checkbox" wire:model.live="migrateOnBoot" class="mt-0.5 rounded border-brand-ink/20" />
                        <span>
                            <span class="block font-semibold">{{ __('Run migrations when a container starts') }}</span>
                            <span class="mt-1 block text-brand-moss">{{ __('Laravel: migrate --force --isolated. Rails: db:prepare.') }}</span>
                        </span>
                    </label>
                    @if ($site->isLaravelFrameworkDetected() || $site->isRailsFrameworkDetected())
                        <div class="border-t border-brand-ink/10 pt-3">
                            <p class="text-xs font-semibold text-brand-ink">{{ __('Tools') }}</p>
                            <div class="mt-2 flex flex-wrap gap-2">
                                <button type="button" wire:click="runDatabaseCommand('migrate')" wire:loading.attr="disabled" wire:target="runDatabaseCommand,confirmDatabaseCommand" class="rounded-md border border-brand-ink/15 px-2.5 py-1.5 text-xs font-semibold text-brand-ink">{{ __('Migrate') }}</button>
                                <button type="button" wire:click="runDatabaseCommand('status')" wire:loading.attr="disabled" wire:target="runDatabaseCommand,confirmDatabaseCommand" class="rounded-md border border-brand-ink/15 px-2.5 py-1.5 text-xs font-semibold text-brand-ink">{{ __('Status') }}</button>
                                <button type="button" wire:click="runDatabaseCommand('seed')" wire:loading.attr="disabled" wire:target="runDatabaseCommand,confirmDatabaseCommand" class="rounded-md border border-brand-ink/15 px-2.5 py-1.5 text-xs font-semibold text-brand-ink">{{ __('Seed') }}</button>
                                @if ($site->isRailsFrameworkDetected())
                                    <button type="button" wire:click="runDatabaseCommand('prepare')" wire:loading.attr="disabled" wire:target="runDatabaseCommand,confirmDatabaseCommand" class="rounded-md border border-brand-ink/15 px-2.5 py-1.5 text-xs font-semibold text-brand-ink">{{ __('Prepare') }}</button>
                                @endif
                                <button type="button" wire:click="runDatabaseCommand('rollback')" wire:loading.attr="disabled" wire:target="runDatabaseCommand,confirmDatabaseCommand" class="rounded-md border border-brand-ink/15 px-2.5 py-1.5 text-xs font-semibold text-brand-ink">{{ __('Roll back') }}</button>
                            </div>
                            <p wire:loading wire:target="runDatabaseCommand,confirmDatabaseCommand" class="mt-2 text-xs text-brand-moss">{{ __('Running…') }}</p>
                            @if ($pendingDatabaseCommand === 'rollback')
                                <div class="mt-3 rounded-lg border border-brand-ink/10 p-3">
                                    <p class="text-xs text-brand-ink">{{ __('Roll back the last migration on this database?') }}</p>
                                    <div class="mt-2 flex gap-2">
                                        <button type="button" wire:click="confirmDatabaseCommand" class="rounded-md bg-brand-ink px-2.5 py-1.5 text-xs font-semibold text-white">{{ __('Roll back') }}</button>
                                        <button type="button" wire:click="$set('pendingDatabaseCommand', '')" class="rounded-md border border-brand-ink/15 px-2.5 py-1.5 text-xs font-semibold text-brand-ink">{{ __('Cancel') }}</button>
                                    </div>
                                </div>
                            @endif
                            @if ($databaseCommandOutput !== '')
                                <pre class="mt-3 max-h-48 overflow-auto whitespace-pre-wrap rounded-lg bg-brand-sand/40 p-3 text-xs text-brand-ink dark:bg-zinc-950">{{ $databaseCommandOutput }}</pre>
                            @endif
                        </div>
                    @endif
                    @if ($databaseEngine === 'postgres')
                        @php $postgresLocked = ! $site->organization?->onAnyPaidPlan(); @endphp
                        <div class="grid gap-2 sm:grid-cols-2">
                            <label for="postgres-location" class="block text-xs font-semibold text-brand-ink">
                                {{ __('Location') }}
                                <select id="postgres-location" wire:change="selectPostgresRegion($event.target.value)" @disabled($postgresRegionLocked || $postgresLocked) class="mt-1 block w-full rounded-md border border-brand-ink/15 bg-white py-1 ps-2 pe-6 text-xs font-semibold text-brand-ink disabled:opacity-60 dark:bg-zinc-900">
                                    @foreach ($postgresRegions as $id => $label)
                                        <option value="{{ $id }}" @selected($postgresRegion === $id)>{{ $label }}</option>
                                    @endforeach
                                </select>
                            </label>
                            <label for="postgres-sleep" class="block text-xs font-semibold text-brand-ink">
                                {{ __('Sleep after') }}
                                <select id="postgres-sleep" wire:change="selectPostgresSuspend(Number($event.target.value))" @disabled($postgresLocked) class="mt-1 block w-full rounded-md border border-brand-ink/15 bg-white py-1 ps-2 pe-6 text-xs font-semibold text-brand-ink disabled:opacity-60 dark:bg-zinc-900">
                                    @foreach ($postgresSleeps as $seconds => $label)
                                        <option value="{{ $seconds }}" @selected($postgresSuspend === $seconds)>{{ __($label) }}</option>
                                    @endforeach
                                </select>
                            </label>
                            <label for="postgres-history" class="block text-xs font-semibold text-brand-ink">
                                {{ __('Restore') }}
                                <select id="postgres-history" wire:change="selectPostgresHistory(Number($event.target.value))" @disabled($postgresLocked) class="mt-1 block w-full rounded-md border border-brand-ink/15 bg-white py-1 ps-2 pe-6 text-xs font-semibold text-brand-ink disabled:opacity-60 dark:bg-zinc-900">
                                    @foreach ($postgresHistories as $seconds => $label)
                                        <option value="{{ $seconds }}" @selected($postgresHistory === $seconds)>{{ __($label) }}</option>
                                    @endforeach
                                </select>
                            </label>
                            <label for="postgres-awake" class="block text-xs font-semibold text-brand-ink">
                                {{ __('Hours awake') }}
                                <input id="postgres-awake" type="number" min="0" max="24" wire:model.live="awakeHours" @disabled($postgresLocked) class="mt-1 block w-full rounded-md border border-brand-ink/15 bg-white py-1 ps-2 pe-2 text-xs font-semibold text-brand-ink disabled:opacity-60 dark:bg-zinc-900" />
                            </label>
                        </div>
                        <p class="text-xs text-brand-moss">
                            @if ($postgresRegionLocked)
                                {{ __('Stays in :location.', ['location' => $postgresRegions[$postgresRegion]]) }}
                            @else
                                {{ __('Location is fixed after you save.') }}
                            @endif
                            @if ($postgresSuspend === -1)
                                {{ __('Stays on bills every hour. Hours awake is only the estimate.') }}
                            @else
                                {{ __('Day and month assume :hours hours awake. That number does not change the database.', ['hours' => $postgresAwakeHours]) }}
                            @endif
                        </p>
                        <div class="grid gap-1 sm:grid-cols-2" role="radiogroup" aria-label="{{ __('Size') }}">
                            @foreach ($postgresSizes as $key => $size)
                                <button type="button" wire:click="selectPostgresSize('{{ $key }}')" @disabled($postgresLocked) @class([
                                    'rounded-md border px-2 py-1 text-left text-xs font-semibold',
                                    'border-brand-sage bg-white text-brand-ink dark:bg-zinc-900' => $postgresSize === $key,
                                    'border-brand-ink/10 bg-white/70 text-brand-ink dark:bg-zinc-900/70' => $postgresSize !== $key,
                                ])>{{ $size['cpu'] }} · {{ $size['memory'] }} · ${{ $size['day'] }}/{{ __('day') }} · ${{ $size['month'] }}/{{ __('mo') }}</button>
                            @endforeach
                        </div>
                        <p class="text-xs text-brand-moss">
                            {{ __('1 compute. Storage $:gigabyte/GB each month. Restore history $:history/GB each month.', ['gigabyte' => $postgresGigabyte, 'history' => $postgresHistoryRate]) }}
                            @if ($postgresStored['recorded'])
                                {{ __('Using :gigabytes GB, about $:month so far.', ['gigabytes' => $postgresStored['gigabytes'], 'month' => $postgresStored['month']]) }}
                            @endif
                        </p>
                    @elseif ($databaseEngine === 'mysql')
                        <p class="text-xs font-semibold text-brand-ink">{{ __('Size') }}</p>
                        <div class="grid gap-1.5" role="radiogroup" aria-label="{{ __('Size') }}">
                            @foreach ($mysqlSizes as $key => $size)
                                <button type="button" wire:click="selectMysqlSize('{{ $key }}')" @class([
                                    'rounded-lg border px-2.5 py-1.5 text-left text-xs font-semibold',
                                    'border-brand-sage bg-white text-brand-ink dark:bg-zinc-900' => $mysqlSize === $key,
                                    'border-brand-ink/10 bg-white/70 text-brand-ink dark:bg-zinc-900/70' => $mysqlSize !== $key,
                                ])>{{ $size['cpu'] }} · {{ $size['memory'] }} · ${{ number_format($size['cents'] / 100, 2) }}/mo</button>
                            @endforeach
                        </div>
                        <p class="text-xs font-semibold text-brand-ink">{{ __('Nodes') }}</p>
                        <p class="text-xs text-brand-moss">{{ __('MySQL is 3 nodes. That is the smallest cluster, and it is why the monthly price is a cluster price.') }}</p>
                        <ul class="list-disc space-y-1 pl-4 text-xs text-brand-ink">
                            <li>{{ __('1 primary answers the app. Reads and writes go there.') }}</li>
                            <li>{{ __('2 replicas keep a copy in other zones. If the primary stops, a replica takes over.') }}</li>
                            <li>{{ __('All 3 stay on while the app sleeps. You are billed for the month, not for the hours the app is awake.') }}</li>
                            <li>{{ __('The price on each size is those 3 nodes together. Storage past the included disk is extra.') }}</li>
                        </ul>
                        <p class="text-xs text-brand-moss">{{ __('Foreign keys are on. Connections require TLS. A size change applies when you save.') }}</p>
                        @if ($databaseStatus === 'provisioning')
                            <p class="text-xs text-brand-ink">{{ __('Starting. Redeploy after the address is ready.') }}</p>
                        @elseif ($databaseStatus === 'failed')
                            <p class="text-xs text-brand-ink">{{ __('MySQL did not start. Save again to retry.') }}</p>
                        @endif
                    @else
                        <p class="text-xs text-brand-moss">{{ __('SQLite is a file at /tmp/database.sqlite. It is saved while the app runs and restored when the app wakes.') }}</p>
                    @endif
                </div>
            @endif
        </div>
    </x-modal>

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
