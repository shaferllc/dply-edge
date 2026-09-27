@php
    $kvConnection = collect($connections)->firstWhere('host', $kvHost);
    $kvHostName = is_array($kvConnection) ? $kvConnection['host'] : $kvHost;
    $kvStore = is_array($kvConnection) ? strtolower((string) $kvConnection['name']) : 'store';
@endphp
<x-sheet name="resources-kv" :show="$kvHost !== ''" maxWidth="3xl" focusable>
    <x-sheet.header :eyebrow="$kvHostName !== '' ? $kvHostName : null" :title="__('Key-value store')" close-wire="$set('kvHost', '')" />

    <x-sheet.body>
        @if ($kvHostName === '')
            <p class="text-xs text-brand-moss">{{ __('Loading the store…') }}</p>
        @else
        <p class="text-xs leading-5 text-brand-moss">{{ __('This app keeps short values at http://:host/. The address belongs only to this app.', ['host' => $kvHostName]) }}</p>
        <div class="grid content-start gap-4" x-data="{ tab: 'how' }">
            <x-sheet.tabs>
                <button type="button" role="tab" x-on:click="tab = 'how'" :aria-selected="tab === 'how' ? 'true' : 'false'">{{ __('How it works') }}</button>
                <button type="button" role="tab" x-on:click="tab = 'implementation'" :aria-selected="tab === 'implementation' ? 'true' : 'false'">{{ __('Implementation') }}</button>
                <button type="button" role="tab" x-on:click="tab = 'keys'" :aria-selected="tab === 'keys' ? 'true' : 'false'">{{ __('Keys') }}</button>
                <button type="button" role="tab" x-on:click="tab = 'usage'" :aria-selected="tab === 'usage' ? 'true' : 'false'">{{ __('Usage') }}</button>
                <button type="button" role="tab" x-on:click="tab = 'costs'" :aria-selected="tab === 'costs' ? 'true' : 'false'">{{ __('Costs') }}</button>
                <button type="button" role="tab" x-on:click="tab = 'settings'" :aria-selected="tab === 'settings' ? 'true' : 'false'">{{ __('Settings') }}</button>
            </x-sheet.tabs>
            <div x-show="tab === 'how'" class="grid gap-3">
                <ol class="list-decimal space-y-1.5 pl-4 text-sm text-brand-ink">
                    <li>{{ __('GET http://:host/ lists up to 100 keys.', ['host' => $kvHostName]) }}</li>
                    <li>{{ __('GET http://:host/key reads one value. A missing key is a 404.', ['host' => $kvHostName]) }}</li>
                    <li>{{ __('PUT http://:host/key stores the request body. DELETE removes it.', ['host' => $kvHostName]) }}</li>
                    <li>{{ __('The worker for this app receives those calls. No other app can.') }}</li>
                    <li>{{ __('This starts working after the next deploy.') }}</li>
                </ol>
                <x-sheet.note>{{ __('Reads are :reads per million. Writes, deletes, and lists are :writes per million. Storage is :storage per GB-month.', ['reads' => \App\Modules\Billing\Support\UsagePrice::dollars(\App\Modules\Billing\Support\UsagePrice::rate('kv_reads_millicents_per_million')), 'writes' => \App\Modules\Billing\Support\UsagePrice::dollars(\App\Modules\Billing\Support\UsagePrice::rate('kv_writes_millicents_per_million')), 'storage' => \App\Modules\Billing\Support\UsagePrice::dollars(\App\Modules\Billing\Support\UsagePrice::rate('kv_storage_millicents_per_gb_month'))]) }}</x-sheet.note>
            </div>
            <div x-show="tab === 'implementation'" x-cloak class="grid gap-5">
                <x-sheet.section :title="__('Laravel')">
                    <p class="text-xs text-brand-moss">{{ __('The next deploy adds dply/laravel when this app does not already have it, sets DPLY_KV_HOST, and registers a cache store named :store.', ['store' => $kvStore]) }}</p>
                    <pre class="overflow-x-auto rounded-xl bg-brand-sand/40 p-3.5 text-xs text-brand-ink dark:bg-zinc-950">{{ "Cache::store('{$kvStore}')->put('session', 'hello');\nCache::store('{$kvStore}')->get('session');\nCache::store('{$kvStore}')->forget('session');" }}</pre>
                </x-sheet.section>
                <x-sheet.section :title="__('Rails')">
                    <p class="text-xs text-brand-moss">{{ __('Add dply-rails. The next deploy sets DPLY_KV_HOST. Rails.cache uses this store.') }}</p>
                    <pre class="overflow-x-auto rounded-xl bg-brand-sand/40 p-3.5 text-xs text-brand-ink dark:bg-zinc-950">gem "dply-rails"</pre>
                    <pre class="overflow-x-auto rounded-xl bg-brand-sand/40 p-3.5 text-xs text-brand-ink dark:bg-zinc-950">{{ "Rails.cache.write('session', 'hello')\nRails.cache.read('session')\nRails.cache.delete('session')" }}</pre>
                </x-sheet.section>
                <x-sheet.section :title="__('HTTP')">
                    <p class="text-xs text-brand-moss">{{ __('The same address works without either package. It is on the app after the next deploy.') }}</p>
                    <pre class="overflow-x-auto rounded-xl bg-brand-sand/40 p-3.5 text-xs text-brand-ink dark:bg-zinc-950">{{ "curl -X PUT http://{$kvHostName}/session -d 'hello'\ncurl http://{$kvHostName}/session\ncurl -X DELETE http://{$kvHostName}/session" }}</pre>
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
                            @foreach ($kvKeys as $key)
                                <x-sheet.row wire:key="kv-key-{{ md5($key) }}" wire:click="pickKvKey({{ \Illuminate\Support\Js::from($key) }})" :title="$key" class="font-mono" />
                            @endforeach
                        </div>
                        @if ($kvCursor !== null)
                            <div><x-sheet.button wire:click="loadMoreKvKeys" wire:loading.attr="disabled" wire:target="loadMoreKvKeys">{{ __('Load more') }}</x-sheet.button></div>
                        @endif
                    @endif
                </x-sheet.section>
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
            <div x-show="tab === 'usage'" x-cloak class="grid gap-3">
                <x-sheet.metrics :cols="3">
                    <x-sheet.metric :label="__('Reads this month')">{{ number_format($kvReads) }}</x-sheet.metric>
                    <x-sheet.metric :label="__('Writes this month')">{{ number_format($kvWrites) }}</x-sheet.metric>
                    <x-sheet.metric :label="__('Deletes this month')">{{ number_format($kvDeletes) }}</x-sheet.metric>
                    <x-sheet.metric :label="__('Lists this month')">{{ number_format($kvLists) }}</x-sheet.metric>
                    <x-sheet.metric :label="__('Storage')">{{ $kvStorageBytes >= 1024 ** 3 ? number_format($kvStorageBytes / 1024 ** 3, 2).' GB' : ($kvStorageBytes >= 1024 ** 2 ? number_format($kvStorageBytes / 1024 ** 2, 1).' MB' : number_format($kvStorageBytes).' B') }}</x-sheet.metric>
                </x-sheet.metrics>
                <p class="text-2xs leading-4 text-brand-mist">{{ __('Collected through today. A list of keys counts as a list.') }}</p>
            </div>
            <div x-show="tab === 'costs'" x-cloak class="grid gap-3">
                @if (is_array($kvConnection) && $kvConnection['asleep'])
                    <x-sheet.note>{{ __('Asleep. Once the next deploy drops it, there are no reads or writes, but its stored data is still billed.') }}</x-sheet.note>
                @endif
                    <x-sheet.cost :label="__('This month')">${{ number_format($kvMonthCents / 100, 2) }}</x-sheet.cost>
                    <p class="text-2xs leading-4 text-brand-mist">{{ __('Reads are :reads per million. Writes, deletes, and lists are :writes per million. Storage is :storage per GB-month.', ['reads' => \App\Modules\Billing\Support\UsagePrice::dollars(\App\Modules\Billing\Support\UsagePrice::rate('kv_reads_millicents_per_million')), 'writes' => \App\Modules\Billing\Support\UsagePrice::dollars(\App\Modules\Billing\Support\UsagePrice::rate('kv_writes_millicents_per_million')), 'storage' => \App\Modules\Billing\Support\UsagePrice::dollars(\App\Modules\Billing\Support\UsagePrice::rate('kv_storage_millicents_per_gb_month'))]) }}</p>
                    @unless ($cardOnFile)
                        <x-sheet.note tone="warn">{{ __('This counts against the usage credit until a card is on the account.') }}</x-sheet.note>
                    @endunless
            </div>
            <div x-show="tab === 'settings'" x-cloak class="grid gap-3">
                <x-input-error :messages="$errors->get('kvSettings')" />
                @php $kvNext = \App\Modules\Edge\Support\EdgeContainerConnections::identity($kvName, $site); @endphp
                <x-sheet.field :label="__('Name')" for="kv-name">
                    <input id="kv-name" type="text" wire:model="kvName" class="dply-input mt-0" />
                    @if (is_array($kvNext))
                        <p class="text-2xs leading-4 text-brand-mist">{{ __('The app uses http://:host/ after the next deploy.', ['host' => $kvNext['host']]) }}</p>
                    @else
                        <p class="text-2xs leading-4 text-brand-mist">{{ __('Name the store. Letters and numbers only, starting with a letter.') }}</p>
                    @endif
                </x-sheet.field>
                <div><x-sheet.button variant="primary" wire:click="saveKvSettings">{{ __('Save settings') }}</x-sheet.button></div>
                <x-sheet.danger :title="__('Delete this store')" class="mt-2">
                    <p class="text-xs text-brand-moss">{{ __('Removes the store and every key in it. The app loses http://:host/ on the next deploy.', ['host' => $kvHostName]) }}</p>
                    <div><x-sheet.button variant="danger" wire:click="askDeleteConnection({{ \Illuminate\Support\Js::from($kvHostName) }})" wire:island="resources-delete-connection" x-on:click="$dispatch('open-modal', 'resources-delete-connection')">{{ __('Delete store') }}</x-sheet.button></div>
                </x-sheet.danger>
            </div>
        </div>
        @endif
    </x-sheet.body>
</x-sheet>
