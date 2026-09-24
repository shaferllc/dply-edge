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
            <div class="grid min-w-[56rem] items-stretch gap-x-2 gap-y-2" style="grid-template-columns: minmax(12rem, 0.9fr) auto minmax(18rem, 1.3fr) auto minmax(12rem, 0.95fr) auto minmax(12rem, 0.95fr)">
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
                                    <option value="{{ $option }}">{{ $option }}</option>
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
                                    <option value="{{ $size['key'] }}">{{ $size['label'] }} · {{ $size['vcpu'] }} · {{ $size['memory'] }} · {{ __('up to :price', ['price' => $size['price']]) }}</option>
                                @endforeach
                                <option value="custom">{{ __('Custom · 1–4 vCPU · up to 12 GiB') }}</option>
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
                    <div class="flex justify-center py-0.5" aria-hidden="true"><span class="resource-flow resource-flow-y"></span></div>
                    <div @class([
                        'rounded-xl border p-3',
                        'border-dashed border-brand-ink/20 bg-white/70 dark:bg-zinc-900/70' => $connection['asleep'],
                        'border-brand-sage bg-brand-sage/5' => ! $connection['asleep'],
                    ])>
                        <div class="flex flex-col gap-2">
                            <p class="flex items-center gap-1.5 whitespace-nowrap text-xs font-semibold text-brand-ink">
                                @if ($connection['kind'] === 'redis')
                                    <x-redis-mark class="h-3.5 w-3.5 shrink-0" />
                                @endif
                                {{ __($connectionKinds[$connection['kind']]['label']) }}
                                @if ($connection['asleep'])
                                    <span class="font-medium text-brand-moss">{{ __('Asleep') }}</span>
                                @endif
                            </p>
                            <span class="flex flex-wrap gap-x-2 gap-y-1">
                                @if ($connection['kind'] === 'service')
                                    <button type="button" wire:click="$set('explainConnectionHost', '{{ $connection['host'] }}')" x-on:click="$dispatch('open-modal', 'resources-service')" class="text-xs font-semibold text-brand-ink underline">{{ __('Open') }}</button>
                                @elseif ($connection['kind'] === 'key_value')
                                    <button type="button" wire:click="$set('kvHost', '{{ $connection['host'] }}')" x-on:click="$dispatch('open-modal', 'resources-kv')" class="text-xs font-semibold text-brand-ink underline">{{ __('Open') }}</button>
                                @elseif ($connection['kind'] === 'durable_object')
                                    <button type="button" wire:click="$set('stateHost', '{{ $connection['host'] }}')" x-on:click="$dispatch('open-modal', 'resources-state')" class="text-xs font-semibold text-brand-ink underline">{{ __('Open') }}</button>
                                @elseif ($connection['kind'] === 'object_storage')
                                    <button type="button" wire:click="openObject('{{ $connection['host'] }}')" x-on:click="$dispatch('open-modal', 'resources-object')" class="text-xs font-semibold text-brand-ink underline">{{ __('Open') }}</button>
                                @elseif ($connection['kind'] === 'redis')
                                    <button type="button" wire:click="openRedis('{{ $connection['host'] }}')" x-on:click="$dispatch('open-modal', 'resources-redis')" class="text-xs font-semibold text-brand-ink underline">{{ __('Settings') }}</button>
                                @endif
                                <button type="button" wire:click="sleepConnection('{{ $connection['host'] }}', {{ $connection['asleep'] ? 'false' : 'true' }})" class="text-xs font-semibold text-brand-ink underline">{{ $connection['asleep'] ? __('Wake') : __('Sleep') }}</button>
                                <button type="button" wire:click="askDeleteConnection('{{ $connection['host'] }}')" x-on:click="$dispatch('open-modal', 'resources-delete-connection')" class="text-xs font-semibold text-brand-ink underline">{{ __('Delete') }}</button>
                                <button type="button" wire:click="removeConnection('{{ $connection['host'] }}')" class="text-xs font-semibold text-brand-ink underline">{{ __('Detach') }}</button>
                            </span>
                        </div>
                        @if ($connection['kind'] === 'redis')
                            <p class="mt-1 font-mono text-xs text-brand-moss">REDIS_URL</p>
                        @elseif ($connection['kind'] === 'object_storage')
                            <p class="mt-1 text-xs text-brand-moss">{{ __('Bucket :name', ['name' => $connection['target']]) }}</p>
                        @else
                            <p class="mt-1 font-mono text-xs text-brand-moss">{{ $connection['host'] }}</p>
                        @endif
                    </div>
                @endforeach
                <div class="flex justify-center py-0.5" aria-hidden="true"><span class="resource-flow resource-flow-y"></span></div>
                <button type="button" wire:click="openConnectionBuilder" x-on:click="$dispatch('open-modal', 'resources-connection')" class="rounded-xl border border-dashed border-brand-ink/25 px-3 py-2 text-left text-xs font-semibold text-brand-ink">{{ __('Add resource') }}</button>
                </div>

                <div class="flex items-center justify-center self-stretch px-1" aria-hidden="true"><span class="resource-flow resource-flow-x"></span></div>

                <div @class([
                    'flex min-w-0 flex-col rounded-xl border p-3',
                    'border-brand-sage bg-brand-sage/5' => $cacheMode !== 'off',
                    'border-dashed border-brand-ink/20 bg-white dark:bg-zinc-900' => $cacheMode === 'off',
                ])>
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

                <div class="flex items-center justify-center self-stretch px-1" aria-hidden="true"><span class="resource-flow resource-flow-x"></span></div>

                <div @class([
                    'flex min-w-0 flex-col rounded-xl border p-3',
                    'border-brand-sage bg-brand-sage/5' => $databaseEngine === 'sql',
                    'border-dashed border-brand-ink/20 bg-white dark:bg-zinc-900' => $databaseEngine !== 'sql',
                ])>
                    <p class="text-xs font-semibold text-brand-ink">{{ __('Database') }}</p>
                    @if ($databaseEngine === 'sql')
                        <dl class="mt-2 space-y-1.5 text-xs">
                            <div class="flex justify-between gap-2">
                                <dt class="text-brand-moss">{{ __('Type') }}</dt>
                                <dd class="font-medium text-brand-ink">{{ __('SQL') }}</dd>
                            </div>
                            <div class="flex justify-between gap-2">
                                <dt class="text-brand-moss">{{ __('Name') }}</dt>
                                <dd class="truncate font-mono text-brand-ink">{{ $databaseName }}</dd>
                            </div>
                        </dl>
                        <p class="mt-2 text-xs text-brand-moss">{{ __('SQLite.') }}</p>
                    @else
                        <p class="mt-2 text-xs text-brand-moss">{{ __('No database.') }}</p>
                    @endif
                    <div class="mt-3 grid gap-1.5" role="radiogroup" aria-label="{{ __('Database') }}">
                        <button type="button" wire:click="selectDatabase('sql')" @class([
                            'rounded-lg border px-2.5 py-1.5 text-left text-xs font-semibold',
                            'border-brand-sage bg-white text-brand-ink dark:bg-zinc-900' => $databaseEngine === 'sql',
                            'border-brand-ink/10 bg-white/70 text-brand-ink dark:bg-zinc-900/70' => $databaseEngine !== 'sql',
                        ])>{{ __('SQL') }}</button>
                        <button type="button" wire:click="selectDatabase('none')" @class([
                            'rounded-lg border px-2.5 py-1.5 text-left text-xs font-semibold',
                            'border-brand-sage bg-white text-brand-ink dark:bg-zinc-900' => $databaseEngine === 'none',
                            'border-brand-ink/10 bg-white/70 text-brand-ink dark:bg-zinc-900/70' => $databaseEngine !== 'none',
                        ])>{{ __('None') }}</button>
                    </div>
                    <button type="button" wire:click="openPanel('databases')" x-on:click="$dispatch('open-modal', 'resources-databases')" class="mt-auto pt-3 text-left text-xs font-semibold text-brand-ink underline">{{ __('Databases') }}</button>
                </div>
            </div>
        </div>
    </section>

    @if ($showBrowser)
        <section class="border-t border-brand-ink/10 px-5 py-4 sm:px-6">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <p class="text-xs font-semibold uppercase tracking-[0.16em] text-brand-sage">{{ __('Browser') }}</p>
                    <p class="mt-1 text-xs text-brand-moss">{{ $browserOn ? $browserHost : __('Off') }}</p>
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
            <p class="mt-3 text-xs font-semibold text-brand-ink">{{ __('How it works') }}</p>
            <ol class="mt-2 list-decimal space-y-1 pl-4 text-xs text-brand-ink">
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
            <p class="mt-3 text-xs font-semibold text-brand-ink">{{ __('Example') }}</p>
            @if ($site->isLaravelFrameworkDetected())
                <pre class="mt-2 overflow-x-auto rounded-lg bg-brand-sand/40 p-3 text-xs text-brand-ink dark:bg-zinc-950">{{ "Http::get('http://{$explainedHost}/health');" }}</pre>
            @elseif ($site->isRailsFrameworkDetected())
                <pre class="mt-2 overflow-x-auto rounded-lg bg-brand-sand/40 p-3 text-xs text-brand-ink dark:bg-zinc-950">{{ "Faraday.get('http://{$explainedHost}/health')" }}</pre>
            @else
                <pre class="mt-2 overflow-x-auto rounded-lg bg-brand-sand/40 p-3 text-xs text-brand-ink dark:bg-zinc-950">{{ "curl http://{$explainedHost}/health" }}</pre>
            @endif
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
            <p class="mt-3 text-xs text-brand-moss">{{ __('The app connects with this username and password. They apply on the next deploy.') }}</p>
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
                <div class="mt-4 grid grid-cols-2 gap-3 border-t border-brand-ink/10 pt-4 sm:grid-cols-4">
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
                <div class="mt-4 space-y-3 border-t border-brand-ink/10 pt-4">
                    <label class="block text-xs text-brand-moss">
                        {{ __('Name') }}
                        <input type="text" wire:model="redisName" class="mt-1 block w-full rounded-lg border border-brand-ink/15 bg-white px-3 py-2 text-sm text-brand-ink dark:bg-zinc-900" />
                    </label>
                    <label class="block text-xs text-brand-moss">
                        {{ __('Monthly budget ($)') }}
                        <input type="number" min="0" max="10000" wire:model="redisBudget" class="mt-1 block w-full rounded-lg border border-brand-ink/15 bg-white px-3 py-2 text-sm text-brand-ink dark:bg-zinc-900" />
                    </label>
                    <p class="text-xs text-brand-moss">{{ __('0 means no cap.') }}</p>
                    <label class="flex items-center justify-between gap-3 text-xs text-brand-ink">
                        <span>{{ __('Drop keys when full') }}</span>
                        <input type="checkbox" wire:model="redisEviction" class="rounded border-brand-ink/20" />
                    </label>
                    <div class="flex items-center justify-between gap-3 text-xs text-brand-ink">
                        <span>{{ __('TLS') }}</span>
                        @if ($redisTls)
                            <span class="font-semibold">{{ __('On') }}</span>
                        @else
                            <input type="checkbox" wire:model="redisTls" class="rounded border-brand-ink/20" />
                        @endif
                    </div>
                    <label class="flex items-center justify-between gap-3 text-xs text-brand-ink">
                        <span>{{ __('Upgrade when it hits a limit') }}</span>
                        <input type="checkbox" wire:model="redisAutoUpgrade" class="rounded border-brand-ink/20" />
                    </label>
                    <label class="flex items-center justify-between gap-3 text-xs text-brand-ink">
                        <span>{{ __('Daily backup') }}</span>
                        <input type="checkbox" wire:model="redisDailyBackup" class="rounded border-brand-ink/20" />
                    </label>
                    <x-input-error :messages="$errors->get('redisSettings')" class="mt-2" />
                    <div class="flex flex-wrap gap-2">
                        <button type="button" wire:click="saveRedisSettings" class="rounded-md bg-brand-ink px-3 py-1.5 text-xs font-semibold text-white">{{ __('Save settings') }}</button>
                        <button type="button" wire:click="resetRedisPassword" class="rounded-md border border-brand-ink/15 px-3 py-1.5 text-xs font-semibold text-brand-ink">{{ $redisResetArmed ? __('Reset password now') : __('Reset password') }}</button>
                    </div>
                    @if ($redisResetArmed)
                        <p class="text-xs text-brand-moss">{{ __('This replaces the password. The running app keeps the old one until the next deploy.') }}</p>
                    @endif
                </div>
            @else
                <p class="mt-4 text-xs text-brand-moss">{{ __('Stats and settings are available for Redis started here. A pasted address keeps the username and password above.') }}</p>
            @endif
        </div>
    </x-modal>

    <x-modal name="resources-kv" :show="$kvHost !== ''" maxWidth="3xl" focusable>
        <div class="max-h-[80vh] overflow-y-auto bg-white p-5 dark:bg-zinc-900">
            <div class="flex items-start justify-between gap-3">
                <h2 class="text-xs font-semibold uppercase tracking-[0.16em] text-brand-sage">{{ __('Key-value store') }}</h2>
                <button type="button" wire:click="$set('kvHost', '')" x-on:click="$dispatch('close-modal', 'resources-kv')" class="text-xs font-semibold text-brand-ink underline">{{ __('Close') }}</button>
            </div>
            <p class="mt-3 max-w-xl text-xs text-brand-moss">{{ __('This app keeps short values at http://:host/. The address belongs only to this app.', ['host' => $kvHostName]) }}</p>
            <p class="mt-3 text-xs font-semibold text-brand-ink">{{ __('How it works') }}</p>
            <ol class="mt-2 list-decimal space-y-1 pl-4 text-xs text-brand-ink">
                <li>{{ __('GET http://:host/ lists the keys.', ['host' => $kvHostName]) }}</li>
                <li>{{ __('GET http://:host/key reads one value. A missing key is a 404.', ['host' => $kvHostName]) }}</li>
                <li>{{ __('PUT http://:host/key stores the request body. DELETE removes it.', ['host' => $kvHostName]) }}</li>
                <li>{{ __('The worker for this app receives those calls. No other app can.') }}</li>
                <li>{{ __('This starts working after the next deploy.') }}</li>
            </ol>
            <p class="mt-3 text-xs font-semibold text-brand-ink">{{ __('Examples') }}</p>
            @if ($site->isLaravelFrameworkDetected())
                <p class="mt-1 text-xs text-brand-moss">{{ __('Use it like a cache. Register a store named :store that calls the host below. A counter that must be exact belongs on State.', ['store' => $kvStore]) }}</p>
                <pre class="mt-2 overflow-x-auto rounded-lg bg-brand-sand/40 p-3 text-xs text-brand-ink dark:bg-zinc-950">{{ "Cache::store('{$kvStore}')->put('session', 'hello');\nCache::store('{$kvStore}')->get('session');\nCache::store('{$kvStore}')->forget('session');" }}</pre>
                <pre class="mt-2 overflow-x-auto rounded-lg bg-brand-sand/40 p-3 text-xs text-brand-ink dark:bg-zinc-950">{{ "Http::withBody(\$value, 'text/plain')->put('http://{$kvHostName}/'.\$key);\nHttp::get('http://{$kvHostName}/'.\$key)->body();\nHttp::delete('http://{$kvHostName}/'.\$key);" }}</pre>
            @elseif ($site->isRailsFrameworkDetected())
                <p class="mt-1 text-xs text-brand-moss">{{ __('Use it like a cache through Rails.cache, backed by these calls.') }}</p>
                <pre class="mt-2 overflow-x-auto rounded-lg bg-brand-sand/40 p-3 text-xs text-brand-ink dark:bg-zinc-950">{{ "Rails.cache.write('session', 'hello')\nRails.cache.read('session')\nRails.cache.delete('session')" }}</pre>
                <pre class="mt-2 overflow-x-auto rounded-lg bg-brand-sand/40 p-3 text-xs text-brand-ink dark:bg-zinc-950">{{ "Faraday.put('http://{$kvHostName}/session', 'hello')\nFaraday.get('http://{$kvHostName}/session').body\nFaraday.delete('http://{$kvHostName}/session')" }}</pre>
            @else
                <p class="mt-1 text-xs text-brand-moss">{{ __('Any HTTP client can get, put, and delete a key.') }}</p>
                <pre class="mt-2 overflow-x-auto rounded-lg bg-brand-sand/40 p-3 text-xs text-brand-ink dark:bg-zinc-950">{{ "curl -X PUT http://{$kvHostName}/session -d 'hello'\ncurl http://{$kvHostName}/session\ncurl -X DELETE http://{$kvHostName}/session" }}</pre>
                <pre class="mt-2 overflow-x-auto rounded-lg bg-brand-sand/40 p-3 text-xs text-brand-ink dark:bg-zinc-950">{{ "await fetch('http://{$kvHostName}/session', { method: 'PUT', body: 'hello' });\nawait fetch('http://{$kvHostName}/session').then((res) => res.text());" }}</pre>
            @endif
            <div class="mt-4 border-t border-brand-ink/10 pt-4">
                <p class="text-xs font-semibold text-brand-ink">{{ __('Demo') }}</p>
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
        </div>
    </x-modal>

    @php
        $objectConnection = collect($connections)->firstWhere('host', $objectHost);
        $objectHostName = is_array($objectConnection) ? $objectConnection['host'] : $objectHost;
        $objectBucket = is_array($objectConnection) ? (string) $objectConnection['target'] : '';
    @endphp
    <x-modal name="resources-object" :show="$objectHost !== ''" maxWidth="3xl" focusable>
        <div class="max-h-[80vh] overflow-y-auto bg-white p-5 dark:bg-zinc-900">
            <div class="flex items-start justify-between gap-3">
                <h2 class="text-xs font-semibold uppercase tracking-[0.16em] text-brand-sage">{{ __('Object storage') }}</h2>
                <button type="button" wire:click="$set('objectHost', '')" x-on:click="$dispatch('close-modal', 'resources-object')" class="text-xs font-semibold text-brand-ink underline">{{ __('Close') }}</button>
            </div>
            <p class="mt-3 max-w-xl text-xs text-brand-moss">{{ __('This app stores files at http://:host/. The address belongs only to this app. Bucket :bucket.', ['host' => $objectHostName, 'bucket' => $objectBucket]) }}</p>
            <p class="mt-3 text-xs font-semibold text-brand-ink">{{ __('How it works') }}</p>
            <ol class="mt-2 list-decimal space-y-1 pl-4 text-xs text-brand-ink">
                <li>{{ __('GET http://:host/ lists objects.', ['host' => $objectHostName]) }}</li>
                <li>{{ __('GET http://:host/path reads one file. A missing file is a 404.', ['host' => $objectHostName]) }}</li>
                <li>{{ __('PUT http://:host/path stores the request body. DELETE removes it.', ['host' => $objectHostName]) }}</li>
                <li>{{ __('The worker for this app receives those calls. No other app can.') }}</li>
                <li>{{ __('The app address starts working after the next deploy. Files you add here are in the bucket now.') }}</li>
            </ol>
            <p class="mt-3 text-xs font-semibold text-brand-ink">{{ __('Examples') }}</p>
            @if ($site->isLaravelFrameworkDetected())
                <pre class="mt-2 overflow-x-auto rounded-lg bg-brand-sand/40 p-3 text-xs text-brand-ink dark:bg-zinc-950">{{ "Storage::put('uploads/photo.jpg', \$bytes);\nStorage::get('uploads/photo.jpg');\nStorage::disk('s3')->delete('uploads/photo.jpg');" }}</pre>
            @elseif ($site->isRailsFrameworkDetected())
                <pre class="mt-2 overflow-x-auto rounded-lg bg-brand-sand/40 p-3 text-xs text-brand-ink dark:bg-zinc-950">{{ "Dply::Rails::Storage.put('uploads/photo.jpg', bytes)\nDply::Rails::Storage.get('uploads/photo.jpg')\nDply::Rails::Storage.delete('uploads/photo.jpg')" }}</pre>
            @else
                <pre class="mt-2 overflow-x-auto rounded-lg bg-brand-sand/40 p-3 text-xs text-brand-ink dark:bg-zinc-950">{{ "curl -X PUT http://{$objectHostName}/uploads/photo.jpg --data-binary @photo.jpg\ncurl http://{$objectHostName}/uploads/photo.jpg\ncurl -X DELETE http://{$objectHostName}/uploads/photo.jpg" }}</pre>
            @endif
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
        $stateConnection = collect($connections)->firstWhere('host', $stateHost);
        $stateHostName = is_array($stateConnection) ? $stateConnection['host'] : $stateHost;
    @endphp
    <x-modal name="resources-state" :show="$stateHost !== ''" maxWidth="3xl" focusable>
        <div class="max-h-[80vh] overflow-y-auto bg-white p-5 dark:bg-zinc-900">
            <div class="flex items-start justify-between gap-3">
                <h2 class="text-xs font-semibold uppercase tracking-[0.16em] text-brand-sage">{{ __('State') }}</h2>
                <button type="button" wire:click="$set('stateHost', '')" x-on:click="$dispatch('close-modal', 'resources-state')" class="text-xs font-semibold text-brand-ink underline">{{ __('Close') }}</button>
            </div>
            <p class="mt-3 max-w-xl text-xs text-brand-moss">{{ __('One object owns every key at http://:host/. A counter changes one at a time, so two requests cannot both read the same number.', ['host' => $stateHostName]) }}</p>
            <ol class="mt-2 list-decimal space-y-1 pl-4 text-xs text-brand-ink">
                <li>{{ __('GET http://:host/key reads a value. PUT stores the body. DELETE removes it.', ['host' => $stateHostName]) }}</li>
                <li>{{ __('POST http://:host/incr/key adds one and returns the new number.', ['host' => $stateHostName]) }}</li>
                <li>{{ __('This starts working after the next deploy.') }}</li>
            </ol>
            <p class="mt-3 text-xs font-semibold text-brand-ink">{{ __('Examples') }}</p>
            @if ($site->isLaravelFrameworkDetected())
                <pre class="mt-2 overflow-x-auto rounded-lg bg-brand-sand/40 p-3 text-xs text-brand-ink dark:bg-zinc-950">{{ "Http::withBody('hello', 'text/plain')->put('http://{$stateHostName}/session');\nHttp::get('http://{$stateHostName}/session')->body();\nHttp::post('http://{$stateHostName}/incr/visits')->body();" }}</pre>
            @elseif ($site->isRailsFrameworkDetected())
                <pre class="mt-2 overflow-x-auto rounded-lg bg-brand-sand/40 p-3 text-xs text-brand-ink dark:bg-zinc-950">{{ "Faraday.put('http://{$stateHostName}/session', 'hello')\nFaraday.get('http://{$stateHostName}/session').body\nFaraday.post('http://{$stateHostName}/incr/visits').body" }}</pre>
            @else
                <pre class="mt-2 overflow-x-auto rounded-lg bg-brand-sand/40 p-3 text-xs text-brand-ink dark:bg-zinc-950">{{ "curl -X PUT http://{$stateHostName}/session -d 'hello'\ncurl http://{$stateHostName}/session\ncurl -X POST http://{$stateHostName}/incr/visits" }}</pre>
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
                @if ($browserOn)
                    <p class="mt-3 text-xs font-semibold text-brand-ink">{{ __('Examples') }}</p>
                    <p class="mt-1 text-xs text-brand-moss">{{ __('Post {"url":"https://example.com"}. /content returns the page, /screenshot returns a PNG, /pdf returns a PDF.') }}</p>
                    @if ($site->isLaravelFrameworkDetected())
                        <pre class="mt-2 overflow-x-auto rounded-lg bg-brand-sand/40 p-3 text-xs text-brand-ink dark:bg-zinc-950">{{ "Http::post('http://{$browserHost}/screenshot', [\n    'url' => 'https://example.com',\n]);" }}</pre>
                    @elseif ($site->isRailsFrameworkDetected())
                        <pre class="mt-2 overflow-x-auto rounded-lg bg-brand-sand/40 p-3 text-xs text-brand-ink dark:bg-zinc-950">{{ "Faraday.post('http://{$browserHost}/screenshot', { url: 'https://example.com' }.to_json, 'Content-Type' => 'application/json')" }}</pre>
                    @else
                        <pre class="mt-2 overflow-x-auto rounded-lg bg-brand-sand/40 p-3 text-xs text-brand-ink dark:bg-zinc-950">{{ "curl -X POST http://{$browserHost}/screenshot \\\n  -H 'content-type: application/json' \\\n  -d '{\"url\":\"https://example.com\"}'" }}</pre>
                    @endif
                    <ul class="mt-2 space-y-1 font-mono text-xs text-brand-moss">
                        <li>http://{{ $browserHost }}/content</li>
                        <li>http://{{ $browserHost }}/screenshot</li>
                        <li>http://{{ $browserHost }}/pdf</li>
                    </ul>
                    @unless ($browserDeployed)
                        <p class="mt-2 text-xs font-semibold text-brand-ink">{{ __('Deploy this app before those calls work. Only this app can use this address.') }}</p>
                    @endunless
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
                    <p class="mt-1 text-xs text-brand-moss">{{ __('Pick what the app needs. You can create it here or attach one that already exists.') }}</p>
                </div>
                <div class="grid gap-2 sm:grid-cols-3">
                    @foreach ($connectionKinds as $key => $kind)
                        <button type="button" wire:click="chooseConnectionKind('{{ $key }}')" class="flex items-center gap-2 rounded-xl border border-brand-ink/10 px-3 py-2 text-left text-xs font-semibold text-brand-ink hover:border-brand-sage">
                            @if ($key === 'redis')
                                <x-redis-mark class="h-4 w-4 shrink-0" />
                            @endif
                            {{ __($kind['label']) }}
                        </button>
                    @endforeach
                </div>
            @else
                <div>
                    <button type="button" wire:click="$set('connectionKind', '')" class="text-xs font-semibold text-brand-ink underline">{{ __('All types') }}</button>
                    <h2 class="mt-2 flex items-center gap-2 text-sm font-semibold text-brand-ink">
                        @if ($connectionKind === 'redis')
                            <x-redis-mark class="h-4 w-4 shrink-0" />
                        @endif
                        {{ __($connectionKinds[$connectionKind]['label']) }}
                    </h2>
                    <p class="mt-1 text-xs text-brand-moss">{{ __($connectionKinds[$connectionKind]['hint']) }}</p>
                </div>
                @if (in_array($connectionKind, \App\Modules\Edge\Support\EdgeContainerConnections::CREATABLE, true) || $connectionKind === 'redis')
                    <div class="grid grid-cols-2 gap-2">
                        <button type="button" wire:click="setConnectionMode('create')" @class(['rounded-lg border px-3 py-2 text-xs font-semibold', 'border-brand-sage bg-brand-sage/10 text-brand-ink' => $connectionMode === 'create', 'border-brand-ink/10 text-brand-ink' => $connectionMode !== 'create'])>{{ __('Create new') }}</button>
                        <button type="button" wire:click="setConnectionMode('attach')" @class(['rounded-lg border px-3 py-2 text-xs font-semibold', 'border-brand-sage bg-brand-sage/10 text-brand-ink' => $connectionMode === 'attach', 'border-brand-ink/10 text-brand-ink' => $connectionMode !== 'attach'])>{{ __('Attach existing') }}</button>
                    </div>
                @endif
                <form wire:submit="saveConnection" class="space-y-3">
                    @if ($connectionMode === 'create' && in_array($connectionKind, \App\Modules\Edge\Support\EdgeContainerConnections::CREATABLE, true))
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
                    @elseif ($connectionKind === 'redis' && $connectionMode === 'create')
                        <label class="block text-xs text-brand-moss">
                            {{ __('Name') }}
                            <input type="text" wire:model="connectionLabel" placeholder="{{ __('Cache') }}" class="mt-1 block w-full rounded-lg border border-brand-ink/15 bg-white px-3 py-2 text-sm text-brand-ink dark:bg-zinc-900" />
                        </label>
                        <label class="block text-xs text-brand-moss">
                            {{ __('Region') }}
                            <select wire:model="redisRegion" class="mt-1 block w-full rounded-lg border border-brand-ink/15 bg-white px-3 py-2 text-sm text-brand-ink dark:bg-zinc-900">
                                @foreach (\App\Modules\Edge\Support\EdgeContainerConnections::redisRegions() as $id => $label)
                                    <option value="{{ $id }}">{{ __($label) }}</option>
                                @endforeach
                            </select>
                        </label>
                        <p class="text-xs text-brand-moss">{{ __('This starts a Redis for this app in the region you pick. Commands are billed with usage. The first 1 GB of storage and 200 GB of bandwidth each month are included. The address is set on the next deploy and is not shown.') }}</p>
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
                    <button type="submit" class="rounded-md bg-brand-ink px-3 py-1.5 text-xs font-semibold text-white">{{ $connectionMode === 'attach' ? __('Attach') : __('Create') }}</button>
                </form>
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
                    <span class="block text-xs text-brand-moss">{{ __('Queues and the scheduler use one container, separate from visitor traffic.') }}</span>
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
        :message="__('Save these resource changes, or redeploy to apply them now.')"
        saveAction="saveSettings"
        :saveLabel="__('Save settings')"
        discardAction="discardPending"
        extraAction="redeploySettings"
        :extraLabel="__('Redeploy')"
        formPendingWire="pending"
    />
</div>
