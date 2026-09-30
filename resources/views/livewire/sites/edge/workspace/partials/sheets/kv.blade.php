@php
    $kvConnection = collect($connections)->firstWhere('host', $kvHost);
    $kvHostName = is_array($kvConnection) ? $kvConnection['host'] : $kvHost;
    $kvStore = is_array($kvConnection) ? strtolower((string) $kvConnection['name']) : 'store';
    $kvAsleep = is_array($kvConnection) && $kvConnection['asleep'];
    $kvRate = fn (string $key): string => \App\Modules\Billing\Support\UsagePrice::dollars(\App\Modules\Billing\Support\UsagePrice::rate($key));
    $kvPre = 'overflow-x-auto rounded-xl bg-brand-sand/40 p-3.5 text-xs text-brand-ink dark:bg-zinc-950';
@endphp
<x-sheet name="resources-kv" :show="$kvHost !== ''" maxWidth="3xl" focusable>
    <x-sheet.header :eyebrow="$kvHostName !== '' ? $kvHostName : null" :title="$kvHostName !== '' ? \App\Modules\Edge\Support\EdgeContainerConnections::resourceLabel($kvHostName) : __('Key-value store')" close-wire="$set('kvHost', '')">
        @if ($kvHostName !== '')
            <span class="inline-flex items-center gap-2">
                {{ __('Key-value store · Cache::store(\':store\')', ['store' => $kvStore]) }}
                <span @class([
                    'inline-flex items-center gap-1.5 rounded-full px-2 py-0.5 text-2xs font-semibold',
                    'bg-emerald-500/10 text-emerald-700 dark:text-emerald-300' => ! $kvAsleep,
                    'bg-violet-500/10 text-violet-700 dark:text-violet-300' => $kvAsleep,
                ])><span class="h-1.5 w-1.5 rounded-full bg-current"></span>{{ $kvAsleep ? __('Asleep') : __('Active') }}</span>
            </span>
        @endif
    </x-sheet.header>

    <x-sheet.body>
        @if ($kvHostName === '')
            <p class="text-xs text-brand-moss">{{ __('Loading the store…') }}</p>
        @else
        <div class="grid content-start gap-4" x-data="{ tab: 'overview' }">
            <x-sheet.tabs>
                @foreach (['overview' => __('Overview'), 'connect' => __('Connect'), 'keys' => __('Keys'), 'settings' => __('Settings')] as $kvTab => $kvTabLabel)
                    <button type="button" role="tab" x-on:click="tab = '{{ $kvTab }}'" :aria-selected="tab === '{{ $kvTab }}' ? 'true' : 'false'">{{ $kvTabLabel }}</button>
                @endforeach
            </x-sheet.tabs>

            {{-- Overview --}}
            <div x-show="tab === 'overview'" class="grid gap-4">
                @if ($kvAsleep)
                    <x-sheet.note>{{ __('Asleep. Once the next deploy drops it, there are no reads or writes, but its stored data is still billed.') }}</x-sheet.note>
                @endif
                <x-sheet.metrics :cols="4">
                    <x-sheet.metric :label="__('Reads')" :note="__('This month')">{{ \Illuminate\Support\Number::abbreviate($kvReads, maxPrecision: 1) }}</x-sheet.metric>
                    <x-sheet.metric :label="__('Writes')" :note="__('Writes, deletes, lists')">{{ \Illuminate\Support\Number::abbreviate($kvWrites + $kvDeletes + $kvLists, maxPrecision: 1) }}</x-sheet.metric>
                    <x-sheet.metric :label="__('Stored')">{{ \Illuminate\Support\Number::fileSize($kvStorageBytes, precision: 1) }}</x-sheet.metric>
                    <x-sheet.metric :label="__('Cost')" :note="__('This month so far')">${{ number_format($kvMonthCents / 100, 2) }}</x-sheet.metric>
                </x-sheet.metrics>
                <div>
                    <x-sheet.stat :label="__('In the app')">Cache::store('{{ $kvStore }}')</x-sheet.stat>
                    <x-sheet.stat :label="__('Address')">http://{{ $kvHostName }}/</x-sheet.stat>
                </div>
                <x-sheet.note>{{ __('Reads are :reads per million; writes, deletes and lists :writes per million; storage :storage per GB-month, from your plan’s included usage credit first. Collected through today.', ['reads' => $kvRate('kv_reads_millicents_per_million'), 'writes' => $kvRate('kv_writes_millicents_per_million'), 'storage' => $kvRate('kv_storage_millicents_per_gb_month')]) }}</x-sheet.note>
                @unless ($cardOnFile)
                    <x-sheet.note tone="warn">{{ __('This counts against the usage credit until a card is on the account.') }}</x-sheet.note>
                @endunless
            </div>

            {{-- Connect --}}
            <div x-show="tab === 'connect'" x-cloak class="grid gap-5">
                <x-sheet.note>{{ __('Fast reads of values that change rarely: sessions, settings, feature flags, cached pages. It is eventually consistent (a write can take up to a minute to show everywhere), so counters and locks belong in Valkey or State.') }}</x-sheet.note>
                <x-sheet.section :title="__('Test from the app')">
                    <p class="text-xs text-brand-moss">{{ __('The running app writes, reads and forgets a key through Cache::store(\':store\'), the same path your code takes. Laravel apps, after a deploy with this store attached.', ['store' => $kvStore]) }}</p>
                    <div><x-sheet.button wire:click="testKvFromApp" wire:island="resources-kv" wire:loading.attr="disabled" wire:target="testKvFromApp">{{ __('Run test') }}</x-sheet.button></div>
                    @if (is_array($kvAppTest))
                        <x-sheet.note :tone="$kvAppTest['ok'] ? null : 'danger'">{{ $kvAppTest['ok'] ? __('Works from the app.').(($kvAppTest['region'] ?? '') !== '' ? ' '.__('Ran in :region.', ['region' => $kvAppTest['region']]) : '') : ($kvAppTest['error'] ?? __('The test failed.')) }}</x-sheet.note>
                        @if ($kvAppTest['steps'] !== [])
                            <div>
                                @foreach ($kvAppTest['steps'] as $step)
                                    <x-sheet.stat :label="$step['step']">{{ $step['ms'] }} ms · {{ $step['result'] }}</x-sheet.stat>
                                @endforeach
                            </div>
                        @endif
                    @endif
                </x-sheet.section>
                <x-sheet.section :title="__('Laravel')">
                    <p class="text-xs text-brand-moss">{{ __('The next deploy adds dply/laravel when the app does not have it, and registers a cache store named :store. The app’s default cache stays as it is unless you make this the default in Settings.', ['store' => $kvStore]) }}</p>
                    <pre class="{{ $kvPre }}">{{ "Cache::store('{$kvStore}')->put('settings', \$value, now()->addHour());\nCache::store('{$kvStore}')->get('settings');\nCache::store('{$kvStore}')->forget('settings');" }}</pre>
                </x-sheet.section>
                <x-sheet.section :title="__('Rails')">
                    <p class="text-xs text-brand-moss">{{ __('Add dply-rails and use Dply::Rails::Kv, or make this the default cache in Settings so Rails.cache uses it.') }}</p>
                    <pre class="{{ $kvPre }}">gem "dply-rails"</pre>
                    <pre class="{{ $kvPre }}">{{ "Dply::Rails::Kv.write('settings', value, expires_in: 3600)\nDply::Rails::Kv.read('settings')\nDply::Rails::Kv.delete('settings')" }}</pre>
                </x-sheet.section>
                <x-sheet.section :title="__('HTTP (Node or anything else)')">
                    <p class="text-xs text-brand-moss">{{ __('The app’s private address, from the next deploy. GET / lists keys (?prefix=, ?cursor=), POST / with {"keys": [...]} reads up to 100 at once. A PUT can send x-dply-ttl or x-dply-expires-at, and x-dply-metadata (JSON).') }}</p>
                    <pre class="{{ $kvPre }}">{{ "curl -X PUT http://{$kvHostName}/settings -H 'x-dply-ttl: 3600' -d 'hello'\ncurl http://{$kvHostName}/settings\ncurl -X DELETE http://{$kvHostName}/settings" }}</pre>
                </x-sheet.section>
            </div>

            <div x-show="tab === 'keys'" x-cloak class="grid gap-5">
                <x-sheet.section>
                    <div class="flex items-center justify-between gap-3">
                        <h3 class="text-2xs font-semibold uppercase tracking-[0.16em] text-brand-mist">{{ __('Keys') }}</h3>
                        <x-sheet.button wire:click="refreshKv">{{ __('Refresh') }}</x-sheet.button>
                    </div>
                    <form wire:submit="refreshKv" class="flex gap-2">
                        <input type="search" wire:model="kvPrefix" spellcheck="false" placeholder="{{ __('Keys starting with…') }}" aria-label="{{ __('Keys starting with') }}" class="dply-input mt-0 min-w-0 flex-1 font-mono" />
                        <x-sheet.button type="submit">{{ __('Search') }}</x-sheet.button>
                    </form>
                    @if ($kvKeys === [])
                        <x-sheet.empty :message="$kvPrefix !== '' ? __('No keys start with that.') : __('No keys yet.')" />
                    @else
                        <div class="grid gap-1.5">
                            @foreach ($kvKeys as $row)
                                @php
                                    $hint = implode(' · ', array_filter([
                                        $row['expiration'] ? __('Expires :at UTC', ['at' => \Illuminate\Support\Carbon::createFromTimestamp($row['expiration'], 'UTC')->toDayDateTimeString()]) : null,
                                        $row['metadata'] !== null ? \Illuminate\Support\Str::limit(json_encode($row['metadata'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), 80) : null,
                                    ]));
                                @endphp
                                <x-sheet.row wire:key="kv-key-{{ md5($row['name']) }}" wire:click="pickKvKey({{ \Illuminate\Support\Js::from($row['name']) }})" :title="$row['name']" :hint="$hint !== '' ? $hint : null" class="font-mono" />
                            @endforeach
                        </div>
                        @if ($kvCursor !== null)
                            <div><x-sheet.button wire:click="loadMoreKvKeys" wire:loading.attr="disabled" wire:target="loadMoreKvKeys">{{ __('Load more') }}</x-sheet.button></div>
                        @endif
                    @endif
                </x-sheet.section>
                @php $kvDeletion = $this->kvPrefixDeletion(); @endphp
                @if (is_array($kvDeletion) && ! $kvDeletion['done'])
                    <div wire:poll.2s>
                        <x-sheet.note>{{ __('Deleting keys starting with :prefix… :count removed.', ['prefix' => $kvDeletion['prefix'], 'count' => number_format($kvDeletion['removed'])]) }}</x-sheet.note>
                    </div>
                @elseif (is_array($kvDeletion) && $kvDeletion['failed'] !== null)
                    <x-sheet.note tone="warn">{{ __('Deleting keys starting with :prefix stopped after :count. :reason', ['prefix' => $kvDeletion['prefix'], 'count' => number_format($kvDeletion['removed']), 'reason' => $kvDeletion['failed']]) }}</x-sheet.note>
                @elseif (trim($kvPrefix) !== '' && $kvKeys !== [])
                    <x-sheet.danger :title="__('Delete keys by prefix')">
                        @if (is_array($kvDeletion))
                            <p class="text-xs text-brand-moss">{{ __('Last run removed :count keys starting with :prefix.', ['count' => number_format($kvDeletion['removed']), 'prefix' => $kvDeletion['prefix']]) }}</p>
                        @endif
                        @if ($kvDeleteCount === null)
                            <div><x-sheet.button variant="danger" wire:click="previewKvPrefixDelete" wire:loading.attr="disabled" wire:target="previewKvPrefixDelete">{{ __('Delete keys starting with :prefix', ['prefix' => trim($kvPrefix)]) }}</x-sheet.button></div>
                        @else
                            <p class="text-xs text-brand-moss">{{ __('This deletes :count keys starting with :prefix. It cannot be undone. Type the prefix to confirm.', ['count' => $kvDeleteCount, 'prefix' => trim($kvPrefix)]) }}</p>
                            <input type="text" wire:model="kvDeleteConfirm" spellcheck="false" aria-label="{{ __('Type the prefix to confirm') }}" class="dply-input mt-0 font-mono" />
                            <div class="flex gap-2">
                                <x-sheet.button variant="danger" wire:click="deleteKvPrefix" wire:loading.attr="disabled" wire:target="deleteKvPrefix">{{ __('Delete keys') }}</x-sheet.button>
                                <x-sheet.button wire:click="$set('kvDeleteCount', null)">{{ __('Cancel') }}</x-sheet.button>
                            </div>
                        @endif
                        <x-input-error :messages="$errors->get('kvDelete')" />
                    </x-sheet.danger>
                @endif
                <x-sheet.section :title="__('Try a key')">
                    <p class="text-xs text-brand-moss">{{ __('Read and write one key in this store from here. This does not call the app.') }}</p>
                    <x-sheet.field :label="__('Key')" for="kv-demo-key">
                        <input id="kv-demo-key" type="text" wire:model="kvDemoKey" spellcheck="false" class="dply-input mt-0 font-mono" />
                    </x-sheet.field>
                    <x-sheet.field :label="__('Value')" for="kv-demo-value">
                        <textarea id="kv-demo-value" wire:model="kvDemoValue" rows="3" class="dply-input mt-0 font-mono"></textarea>
                    </x-sheet.field>
                    <x-sheet.field :label="__('Expire after (seconds)')" for="kv-demo-ttl" :help="__('Used on Write. At least 60. Leave empty to keep the key.')">
                        <input id="kv-demo-ttl" type="number" min="60" step="1" wire:model="kvDemoTtl" placeholder="{{ __('Never') }}" class="dply-input mt-0 max-w-40 font-mono" />
                    </x-sheet.field>
                    <div class="flex flex-wrap gap-2">
                        <x-sheet.button wire:click="runKvDemo('write')" wire:loading.attr="disabled" wire:target="runKvDemo">{{ __('Write') }}</x-sheet.button>
                        <x-sheet.button wire:click="runKvDemo('read')" wire:loading.attr="disabled" wire:target="runKvDemo">{{ __('Read') }}</x-sheet.button>
                        <x-sheet.button variant="danger" wire:click="runKvDemo('delete')" wire:loading.attr="disabled" wire:target="runKvDemo">{{ __('Delete key') }}</x-sheet.button>
                    </div>
                    <x-input-error :messages="$errors->get('kvDemo')" />
                    @if ($kvDemoLog !== [])
                        <ol class="space-y-1 rounded-xl bg-brand-sand/40 p-3.5 font-mono text-xs text-brand-ink dark:bg-zinc-950">
                            @foreach ($kvDemoLog as $line)
                                <li>{{ $line }}</li>
                            @endforeach
                        </ol>
                    @endif
                    @if ($kvDemoPreview !== '')
                        <pre class="max-h-40 overflow-auto rounded-xl bg-brand-sand/40 p-3.5 text-xs text-brand-ink dark:bg-zinc-950">{{ $kvDemoPreview }}</pre>
                    @endif
                    @if (is_array($kvDemoMeta))
                        <div>
                            <x-sheet.stat :label="__('Expires')">{{ $kvDemoMeta['expiration'] ? \Illuminate\Support\Carbon::createFromTimestamp($kvDemoMeta['expiration'], 'UTC')->toDayDateTimeString().' UTC' : __('Never') }}</x-sheet.stat>
                            @if ($kvDemoMeta['metadata'] !== null)
                                <x-sheet.stat :label="__('Metadata')">{{ json_encode($kvDemoMeta['metadata'], JSON_UNESCAPED_SLASHES) }}</x-sheet.stat>
                            @endif
                        </div>
                    @endif
                </x-sheet.section>
            </div>
            {{-- Settings --}}
            <div x-show="tab === 'settings'" x-cloak class="grid gap-5">
                <x-sheet.section :title="__('Name')">
                    <x-input-error :messages="$errors->get('kvSettings')" />
                    @php $kvNext = \App\Modules\Edge\Support\EdgeContainerConnections::identity($kvName, $site); @endphp
                    <div class="flex flex-wrap items-center gap-2">
                        <input id="kv-name" type="text" wire:model="kvName" aria-label="{{ __('Name') }}" class="dply-input mt-0 min-w-0 flex-1" />
                        <x-sheet.button variant="primary" wire:click="saveKvSettings">{{ __('Save') }}</x-sheet.button>
                    </div>
                    <p class="text-2xs leading-4 text-brand-mist">{{ is_array($kvNext) ? __('The app uses http://:host/ after the next deploy.', ['host' => $kvNext['host']]) : __('Letters and numbers only, starting with a letter.') }}</p>
                </x-sheet.section>

                @php
                    $kvIsDefault = ($site->edgeMeta()['kv_default_cache'] ?? null) === $kvStore;
                    $kvHasRedis = collect($connections)->contains(fn ($c) => $c['kind'] === 'redis');
                @endphp
                <x-sheet.section :title="__('Default cache')">
                    <div class="flex flex-wrap items-center justify-between gap-3">
                        <p class="min-w-0 flex-1 text-xs text-brand-moss">{{ $kvIsDefault
                            ? __('This store is the app’s default cache: Cache::put and Rails.cache use it.').($kvHasRedis ? ' '.__('Redis is attached, so Redis stays the default until it is removed.') : '')
                            : __('Off. The app keeps its own default cache and reaches this store by name, Cache::store(\':store\').', ['store' => $kvStore]) }}</p>
                        <x-sheet.button wire:click="setKvDefaultCache({{ $kvIsDefault ? 'false' : 'true' }})" wire:island="resources-kv">{{ $kvIsDefault ? __('Stop using as default') : __('Make default cache') }}</x-sheet.button>
                    </div>
                    <p class="text-2xs leading-4 text-brand-mist">{{ __('As the default, anything that counts or locks fails: rate limiting and login throttling, Cache::lock, withoutOverlapping. Use Valkey for a default cache that does all of that.') }}</p>
                </x-sheet.section>

                @if (is_array($kvConnection))
                    <x-sheet.section :title="__('Sleep')">
                        <div class="flex flex-wrap items-center justify-between gap-3">
                            <p class="min-w-0 flex-1 text-xs text-brand-moss">{{ $kvAsleep
                                ? __('Asleep. Wake it, then deploy, to give the app its keys again.')
                                : __('Takes the store off the app on the next deploy. Keys are kept and still billed.') }}</p>
                            <x-sheet.button wire:click="sleepConnection({{ \Illuminate\Support\Js::from($kvHostName) }}, {{ $kvAsleep ? 'false' : 'true' }})" wire:island="resources-kv">{{ $kvAsleep ? __('Wake') : __('Sleep') }}</x-sheet.button>
                        </div>
                    </x-sheet.section>
                @endif

                <x-sheet.danger :title="__('Remove')">
                    <div class="grid gap-3">
                        <div class="flex flex-wrap items-center justify-between gap-3">
                            <p class="min-w-0 flex-1 text-xs text-brand-moss">{{ __('Detach: the app stops using the store on the next deploy. The store and its keys are kept for other apps.') }}</p>
                            <x-sheet.button wire:click="removeConnection({{ \Illuminate\Support\Js::from($kvHostName) }})" x-on:click="$dispatch('close-modal', 'resources-kv')">{{ __('Detach') }}</x-sheet.button>
                        </div>
                        <div class="flex flex-wrap items-center justify-between gap-3">
                            <p class="min-w-0 flex-1 text-xs text-brand-moss">{{ __('Delete: removes the store and every key in it.') }}</p>
                            <x-sheet.button variant="danger" wire:click="askDeleteConnection({{ \Illuminate\Support\Js::from($kvHostName) }})" wire:island="resources-delete-connection" x-on:click="$dispatch('open-modal', 'resources-delete-connection')">{{ __('Delete store') }}</x-sheet.button>
                        </div>
                    </div>
                </x-sheet.danger>
            </div>
        </div>
        @endif
    </x-sheet.body>
</x-sheet>
