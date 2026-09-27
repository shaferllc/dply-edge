@php
    $objectConnection = collect($connections)->firstWhere('host', $objectHost);
    $objectHostName = is_array($objectConnection) ? $objectConnection['host'] : $objectHost;
    $objectBucket = is_array($objectConnection) ? (string) $objectConnection['target'] : '';
    $objectDisk = is_array($objectConnection) ? strtolower((string) $objectConnection['name']) : 'uploads';
@endphp
<x-sheet name="resources-object" :show="$objectHost !== ''" maxWidth="3xl" focusable>
    <x-sheet.header :eyebrow="$objectHostName" :title="__('Object storage')" close-wire="$set('objectHost', '')">
        {{ __('This app stores files at http://:host/. The address belongs only to this app. Bucket :bucket.', ['host' => $objectHostName, 'bucket' => $objectBucket]) }}
    </x-sheet.header>

    <x-sheet.body>
        <div class="grid gap-4" x-data="{ tab: 'how' }">
            <x-sheet.tabs>
                <button type="button" role="tab" x-on:click="tab = 'how'" :aria-selected="tab === 'how' ? 'true' : 'false'">{{ __('How it works') }}</button>
                <button type="button" role="tab" x-on:click="tab = 'implementation'" :aria-selected="tab === 'implementation' ? 'true' : 'false'">{{ __('Implementation') }}</button>
                <button type="button" role="tab" x-on:click="tab = 'files'" :aria-selected="tab === 'files' ? 'true' : 'false'">{{ __('Files') }}</button>
                <button type="button" role="tab" x-on:click="tab = 'usage'" :aria-selected="tab === 'usage' ? 'true' : 'false'">{{ __('Usage') }}</button>
                <button type="button" role="tab" x-on:click="tab = 'costs'" :aria-selected="tab === 'costs' ? 'true' : 'false'">{{ __('Costs') }}</button>
            </x-sheet.tabs>
            <div x-show="tab === 'how'">
                <ol class="list-decimal space-y-1.5 pl-4 text-xs leading-5 text-brand-ink">
                    <li>{{ __('GET http://:host/ lists objects.', ['host' => $objectHostName]) }}</li>
                    <li>{{ __('GET http://:host/path reads one file. A missing file is a 404.', ['host' => $objectHostName]) }}</li>
                    <li>{{ __('PUT http://:host/path stores the request body. DELETE removes it.', ['host' => $objectHostName]) }}</li>
                    <li>{{ __('The worker for this app receives those calls. No other app can.') }}</li>
                    <li>{{ __('The app address starts working after the next deploy. Files you add here are in the bucket now.') }}</li>
                </ol>
            </div>
            <div x-show="tab === 'implementation'" x-cloak class="grid gap-5">
                <x-sheet.section :title="__('Laravel')">
                    <p class="text-xs leading-5 text-brand-moss">{{ __('The next deploy adds dply/laravel when this app does not already have it, and sets DPLY_STORAGE_HOST and FILESYSTEM_DISK to :disk. Storage::put writes here. A saved AWS key keeps your own s3 disk. The disk name is the store name.', ['disk' => $objectDisk]) }}</p>
                    <pre class="overflow-x-auto rounded-xl bg-brand-sand/40 p-3.5 text-xs text-brand-ink dark:bg-zinc-950">{{ "Storage::disk('{$objectDisk}')->put('uploads/photo.jpg', \$bytes);\nStorage::disk('{$objectDisk}')->get('uploads/photo.jpg');\nStorage::disk('{$objectDisk}')->delete('uploads/photo.jpg');" }}</pre>
                </x-sheet.section>
                <x-sheet.section :title="__('Rails')">
                    <p class="text-xs leading-5 text-brand-moss">{{ __('Add dply-rails. The next deploy sets DPLY_STORAGE_HOST. Dply::Rails::Storage writes to this bucket.') }}</p>
                    <pre class="overflow-x-auto rounded-xl bg-brand-sand/40 p-3.5 text-xs text-brand-ink dark:bg-zinc-950">gem "dply-rails"</pre>
                    <pre class="overflow-x-auto rounded-xl bg-brand-sand/40 p-3.5 text-xs text-brand-ink dark:bg-zinc-950">{{ "Dply::Rails::Storage.put('uploads/photo.jpg', bytes)\nDply::Rails::Storage.get('uploads/photo.jpg')\nDply::Rails::Storage.delete('uploads/photo.jpg')" }}</pre>
                </x-sheet.section>
                <x-sheet.section :title="__('HTTP')">
                    <p class="text-xs leading-5 text-brand-moss">{{ __('The same address works without either package. It is on the app after the next deploy.') }}</p>
                    <pre class="overflow-x-auto rounded-xl bg-brand-sand/40 p-3.5 text-xs text-brand-ink dark:bg-zinc-950">{{ "curl -X PUT http://{$objectHostName}/uploads/photo.jpg --data-binary @photo.jpg\ncurl http://{$objectHostName}/uploads/photo.jpg\ncurl -X DELETE http://{$objectHostName}/uploads/photo.jpg" }}</pre>
                </x-sheet.section>
            </div>

        <section x-show="tab === 'files'" x-cloak class="grid gap-3">
            <div class="flex items-center justify-between gap-3">
                <h3 class="text-2xs font-semibold uppercase tracking-[0.16em] text-brand-mist">{{ __('Files') }}</h3>
                <x-sheet.button wire:click="refreshObjectList">{{ __('Refresh') }}</x-sheet.button>
            </div>
            <form wire:submit="refreshObjectList" class="flex gap-2">
                <input type="search" wire:model="objectPrefix" spellcheck="false" placeholder="{{ __('Names starting with…') }}" aria-label="{{ __('Names starting with') }}" class="dply-input mt-0 min-w-0 flex-1 font-mono" />
                <x-sheet.button type="submit">{{ __('Search') }}</x-sheet.button>
            </form>
            <x-input-error :messages="$errors->get('object')" />
            @if ($objectList === [])
                <x-sheet.empty :message="$objectPrefix !== '' ? __('No files start with that.') : __('No files yet.')" />
            @else
                <ul class="divide-y divide-brand-ink/10 rounded-xl border border-brand-ink/10 text-xs dark:divide-brand-mist/15 dark:border-brand-mist/15">
                    @foreach ($objectList as $object)
                        <li class="flex items-baseline justify-between gap-3 px-3.5 py-2.5">
                            <button type="button" wire:click="pickObject({{ \Illuminate\Support\Js::from($object['key']) }})" class="min-w-0 truncate text-left font-mono text-brand-ink hover:text-brand-forest">{{ $object['key'] }}</button>
                            <span class="shrink-0 font-mono text-2xs text-brand-moss">{{ number_format($object['size']) }} {{ __('B') }}</span>
                        </li>
                    @endforeach
                </ul>
                @if ($objectCursor !== null)
                    <div><x-sheet.button wire:click="loadMoreObjects" wire:loading.attr="disabled" wire:target="loadMoreObjects">{{ __('Load more') }}</x-sheet.button></div>
                @endif
            @endif
            <x-sheet.field :label="__('Object name')" for="resources-object-key">
                <input id="resources-object-key" type="text" wire:model="objectKey" spellcheck="false" class="dply-input mt-0 font-mono" />
            </x-sheet.field>
            <x-sheet.field :label="__('Contents')" for="resources-object-body">
                <textarea id="resources-object-body" wire:model="objectBody" rows="3" class="dply-input mt-0 font-mono"></textarea>
            </x-sheet.field>
            <div class="flex flex-wrap gap-2">
                <x-sheet.button variant="primary" wire:click="runObject('write')" wire:loading.attr="disabled" wire:target="runObject,refreshObjectList">{{ __('Upload') }}</x-sheet.button>
                <x-sheet.button wire:click="runObject('read')" wire:loading.attr="disabled" wire:target="runObject">{{ __('Read') }}</x-sheet.button>
                <x-sheet.button variant="danger" wire:click="runObject('delete')" wire:loading.attr="disabled" wire:target="runObject,refreshObjectList">{{ __('Delete file') }}</x-sheet.button>
            </div>
            @if ($objectLog !== [])
                <ol class="space-y-1 rounded-xl bg-brand-sand/40 p-3.5 font-mono text-xs text-brand-ink dark:bg-zinc-950">
                    @foreach ($objectLog as $line)
                        <li>{{ $line }}</li>
                    @endforeach
                </ol>
            @endif
            @if ($objectPreview !== '')
                <pre class="max-h-40 overflow-auto rounded-xl bg-brand-sand/40 p-3.5 text-xs text-brand-ink dark:bg-zinc-950">{{ $objectPreview }}</pre>
            @endif
        </section>

        <div x-show="tab === 'usage'" x-cloak class="grid gap-3">
            @if ($objectUsage === null)
                <x-sheet.note>{{ __('Usage is not available right now. Try again in a few minutes.') }}</x-sheet.note>
            @else
                <x-sheet.metrics :cols="4">
                    <x-sheet.metric :label="__('Objects')">{{ number_format($objectUsage['objects']) }}</x-sheet.metric>
                    <x-sheet.metric :label="__('Storage')">{{ number_format($objectUsage['storage'] / 1024 ** 3, 2) }} GB</x-sheet.metric>
                    <x-sheet.metric :label="__('Writes this month')" :note="__('Class A: upload, list, delete')">{{ number_format($objectUsage['class_a']) }}</x-sheet.metric>
                    <x-sheet.metric :label="__('Reads this month')" :note="__('Class B: get, head')">{{ number_format($objectUsage['class_b']) }}</x-sheet.metric>
                </x-sheet.metrics>
                <p class="text-2xs leading-4 text-brand-mist">{{ __('From Cloudflare for this bucket, updated every 15 minutes.') }}</p>
            @endif
        </div>

        <div x-show="tab === 'costs'" x-cloak class="grid gap-3">
            @php $objectRate = fn (string $key): string => \App\Modules\Billing\Support\UsagePrice::dollars(\App\Modules\Billing\Support\UsagePrice::rate($key)); @endphp
            @if ($objectUsage === null)
                <x-sheet.note>{{ __('The estimate is not available right now. Try again in a few minutes.') }}</x-sheet.note>
            @else
                <x-sheet.cost :label="__('This month')">${{ number_format($objectUsage['cents'] / 100, 2) }}</x-sheet.cost>
            @endif
            <p class="text-2xs leading-4 text-brand-mist">{{ __('Storage is :storage per GB-month. Writes are :a per million. Reads are :b per million. Paid from your plan’s included usage credit first.', [
                'storage' => $objectRate('r2_bucket_storage_millicents_per_gb_month'),
                'a' => $objectRate('r2_bucket_class_a_millicents_per_million'),
                'b' => $objectRate('r2_bucket_class_b_millicents_per_million'),
            ]) }}</p>
        </div>
        </div>

        <x-sheet.danger :title="__('Delete this bucket')">
            <p class="text-xs text-brand-moss">{{ __('Removes the bucket. The app loses http://:host/ on the next deploy. A bucket with files in it can be emptied first.', ['host' => $objectHostName]) }}</p>
            <div><x-sheet.button variant="danger" wire:click="askDeleteConnection({{ \Illuminate\Support\Js::from($objectHostName) }})" wire:island="resources-delete-connection" x-on:click="$dispatch('open-modal', 'resources-delete-connection')">{{ __('Delete bucket') }}</x-sheet.button></div>
        </x-sheet.danger>
    </x-sheet.body>
</x-sheet>
