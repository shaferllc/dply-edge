@php
    $objectConnection = collect($connections)->firstWhere('host', $objectHost);
    $objectHostName = is_array($objectConnection) ? $objectConnection['host'] : $objectHost;
    $objectBucket = is_array($objectConnection) ? (string) $objectConnection['target'] : '';
    $objectDisk = is_array($objectConnection) ? strtolower((string) $objectConnection['name']) : 'uploads';
    $objectAsleep = is_array($objectConnection) && $objectConnection['asleep'];
    $objectPublicPath = (string) (($site->edgeMeta()['storage_public'] ?? [])[$objectHostName] ?? '');
    $objectBuckets = collect($connections)->where('kind', 'object_storage')->where('asleep', false);
    $objectDefault = \App\Modules\Edge\Support\EdgeContainerConnections::storageDriverEnv($site)['DPLY_STORAGE_DISK'] ?? null;
    $objectEndpoint = \App\Modules\Edge\Services\Storage\EdgeBucketKeys::endpoint();
    $objectAppKey = \App\Models\EdgeBucketKey::query()->where('site_id', $site->id)->value('token_id');
    $objectKeys = $objectBucket === '' ? collect() : \App\Models\EdgeBucketKey::query()
        ->where('organization_id', $site->organization_id)->whereNull('site_id')->whereJsonContains('buckets', $objectBucket)->latest()->get();
    $objectPre = 'overflow-x-auto rounded-xl bg-brand-sand/40 p-3.5 text-xs text-brand-ink dark:bg-zinc-950';
@endphp
<x-sheet name="resources-object" :show="$objectHost !== ''" maxWidth="3xl" focusable>
    <x-sheet.header :eyebrow="$objectBucket" :title="$objectHostName !== '' ? \App\Modules\Edge\Support\EdgeContainerConnections::resourceLabel($objectHostName) : __('Object storage')" close-wire="$set('objectHost', '')">
        <span class="inline-flex items-center gap-2">
            {{ __('Object storage · Storage::disk(\':disk\')', ['disk' => $objectDisk]) }}{{ $objectDefault === $objectDisk ? ' · '.__('default') : '' }}
            <span @class([
                'inline-flex items-center gap-1.5 rounded-full px-2 py-0.5 text-2xs font-semibold',
                'bg-emerald-500/10 text-emerald-700 dark:text-emerald-300' => ! $objectAsleep,
                'bg-violet-500/10 text-violet-700 dark:text-violet-300' => $objectAsleep,
            ])><span class="h-1.5 w-1.5 rounded-full bg-current"></span>{{ $objectAsleep ? __('Asleep') : __('Active') }}</span>
        </span>
    </x-sheet.header>

    <x-sheet.body>
        <div class="grid content-start gap-4" x-data="{ tab: 'overview' }">
            <x-sheet.tabs>
                @foreach (['overview' => __('Overview'), 'connect' => __('Connect'), 'files' => __('Files'), 'keys' => __('Keys'), 'settings' => __('Settings')] as $objectTab => $objectTabLabel)
                    <button type="button" role="tab" x-on:click="tab = '{{ $objectTab }}'" :aria-selected="tab === '{{ $objectTab }}' ? 'true' : 'false'">{{ $objectTabLabel }}</button>
                @endforeach
            </x-sheet.tabs>

            {{-- Overview --}}
            <div x-show="tab === 'overview'" class="grid gap-4">
                @if ($objectUsage === null)
                    <x-sheet.note>{{ __('Usage is not available right now. Try again in a few minutes.') }}</x-sheet.note>
                @else
                    <x-sheet.metrics :cols="4">
                        <x-sheet.metric :label="__('Files')">{{ number_format($objectUsage['objects']) }}</x-sheet.metric>
                        <x-sheet.metric :label="__('Stored')">{{ number_format($objectUsage['storage'] / 1024 ** 3, 2) }} GB</x-sheet.metric>
                        <x-sheet.metric :label="__('Writes')" :note="__('This month')">{{ number_format($objectUsage['class_a']) }}</x-sheet.metric>
                        <x-sheet.metric :label="__('Cost')" :note="__('This month so far')">${{ number_format($objectUsage['cents'] / 100, 2) }}</x-sheet.metric>
                    </x-sheet.metrics>
                @endif
                <div>
                    <x-sheet.stat :label="__('Bucket')">{{ $objectBucket }}</x-sheet.stat>
                    <x-sheet.stat :label="__('In the app')">Storage::disk('{{ $objectDisk }}'){{ $objectDefault === $objectDisk ? ' · '.__('the default disk') : '' }}</x-sheet.stat>
                    <x-sheet.stat :label="__('Public')">{{ $objectPublicPath !== '' ? 'https://'.$hostname.$objectPublicPath.'/…' : __('No. Private to this app and links you share') }}</x-sheet.stat>
                    <x-sheet.stat :label="__('S3 key for the app')">{{ $objectAppKey ?? __('Made on the next deploy') }}</x-sheet.stat>
                </div>
                @php $objectRate = fn (string $key): string => \App\Modules\Billing\Support\UsagePrice::dollars(\App\Modules\Billing\Support\UsagePrice::rate($key)); @endphp
                <x-sheet.note>{{ __('Storage is :storage per GB-month, writes :a per million, reads :b per million, from your plan’s included usage credit first. Figures from Cloudflare, every 15 minutes.', [
                    'storage' => $objectRate('r2_bucket_storage_millicents_per_gb_month'),
                    'a' => $objectRate('r2_bucket_class_a_millicents_per_million'),
                    'b' => $objectRate('r2_bucket_class_b_millicents_per_million'),
                ]) }}</x-sheet.note>
            </div>

            {{-- Connect --}}
            <div x-show="tab === 'connect'" x-cloak class="grid gap-5">
                <x-sheet.section :title="__('Env the app receives')">
                    <p class="text-xs text-brand-moss">{{ __('The next deploy sets the standard S3 variables, so S3 libraries work unchanged. A saved AWS_ACCESS_KEY_ID of your own turns all of them off.') }}</p>
                    <pre class="{{ $objectPre }}">{{ "AWS_ACCESS_KEY_ID=".($objectAppKey ?? '(made on the next deploy)')."\nAWS_SECRET_ACCESS_KEY=••••\nAWS_BUCKET=".($objectDefault === $objectDisk ? $objectBucket : '(the default disk’s bucket)')."\nAWS_DEFAULT_REGION=auto\nAWS_ENDPOINT={$objectEndpoint}\nAWS_ENDPOINT_URL_S3={$objectEndpoint}\nAWS_USE_PATH_STYLE_ENDPOINT=true" }}</pre>
                </x-sheet.section>
                <x-sheet.section :title="__('Laravel')">
                    <p class="text-xs text-brand-moss">{{ __('Storage, the s3 disk and temporaryUrl work as they are. Add Flysystem’s S3 adapter; without it files still save through dply, but temporaryUrl and very large files do not work.') }}</p>
                    <pre class="{{ $objectPre }}">composer require league/flysystem-aws-s3-v3 "^3.0"</pre>
                    <pre class="{{ $objectPre }}">{{ "Storage::put('avatars/1.jpg', \$bytes);\nStorage::temporaryUrl('avatars/1.jpg', now()->addMinutes(5));\nStorage::disk('{$objectDisk}')->get('avatars/1.jpg');" }}</pre>
                </x-sheet.section>
                <x-sheet.section :title="__('Rails (Active Storage)')">
                    <p class="text-xs text-brand-moss">{{ __('Add the gem and this service to config/storage.yml, then set config.active_storage.service = :dply in production.rb.') }}</p>
                    <pre class="{{ $objectPre }}">gem "aws-sdk-s3", require: false</pre>
                    <pre class="{{ $objectPre }}">{{ "dply:\n  service: S3\n  endpoint: <%= ENV[\"AWS_ENDPOINT\"] %>\n  access_key_id: <%= ENV[\"AWS_ACCESS_KEY_ID\"] %>\n  secret_access_key: <%= ENV[\"AWS_SECRET_ACCESS_KEY\"] %>\n  region: auto\n  bucket: <%= ENV[\"AWS_BUCKET\"] %>\n  force_path_style: true" }}</pre>
                </x-sheet.section>
                <x-sheet.section :title="__('Node')">
                    <p class="text-xs text-brand-moss">{{ __('The AWS SDK reads the key, region and endpoint from the environment.') }}</p>
                    <pre class="{{ $objectPre }}">{{ "import { S3Client, PutObjectCommand } from '@aws-sdk/client-s3';\n\nconst s3 = new S3Client({ forcePathStyle: true });\nawait s3.send(new PutObjectCommand({ Bucket: process.env.AWS_BUCKET, Key: 'photo.jpg', Body: bytes }));" }}</pre>
                </x-sheet.section>
                <x-sheet.section :title="__('HTTP')">
                    <p class="text-xs text-brand-moss">{{ __('No library: the app’s private address, on the app after the next deploy.') }}</p>
                    <pre class="{{ $objectPre }}">{{ "curl -X PUT http://{$objectHostName}/photo.jpg --data-binary @photo.jpg\ncurl http://{$objectHostName}/photo.jpg" }}</pre>
                </x-sheet.section>
            </div>

            {{-- Files: pick one to read, share or delete. --}}
            <div x-show="tab === 'files'" x-cloak class="grid gap-3">
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
                            <li @class(['flex items-baseline justify-between gap-3 px-3.5 py-2.5', 'bg-brand-sand/40 dark:bg-zinc-800' => $objectKey === $object['key']])>
                                <button type="button" wire:click="pickObject({{ \Illuminate\Support\Js::from($object['key']) }})" class="min-w-0 truncate text-left font-mono text-brand-ink hover:text-brand-forest">{{ $object['key'] }}</button>
                                <span class="shrink-0 font-mono text-2xs text-brand-moss">{{ \Illuminate\Support\Number::fileSize($object['size']) }}</span>
                            </li>
                        @endforeach
                    </ul>
                    @if ($objectCursor !== null)
                        <div><x-sheet.button wire:click="loadMoreObjects" wire:loading.attr="disabled" wire:target="loadMoreObjects">{{ __('Load more') }}</x-sheet.button></div>
                    @endif
                @endif

                <x-sheet.field :label="__('File name')" for="resources-object-key">
                    <input id="resources-object-key" type="text" wire:model="objectKey" spellcheck="false" placeholder="avatars/1.jpg" class="dply-input mt-0 font-mono" />
                </x-sheet.field>
                <div class="flex flex-wrap gap-2">
                    <x-sheet.button wire:click="runObject('read')" wire:loading.attr="disabled" wire:target="runObject">{{ __('Read') }}</x-sheet.button>
                    <x-sheet.button variant="danger" wire:click="runObject('delete')" wire:confirm="{{ __('Delete this file?') }}" wire:loading.attr="disabled" wire:target="runObject,refreshObjectList">{{ __('Delete') }}</x-sheet.button>
                </div>

                {{-- A signed link to the named file, made on demand and never kept in component state. --}}
                <div x-data="{ hours: 24, url: '', error: '', busy: false, async make(method) { this.busy = true; this.url = ''; this.error = ''; const r = await $wire.$island('resources-object').objectSignedUrl($wire.objectKey, method, Number(this.hours)); this.url = r.url ?? ''; this.error = r.error ?? ''; this.busy = false } }" class="grid gap-2 rounded-xl border border-brand-ink/10 p-3 dark:border-brand-mist/15">
                    <p class="text-xs text-brand-moss">{{ __('Share this file: a link that works without a key until it expires.') }}</p>
                    <div class="flex flex-wrap items-center gap-2">
                        <select x-model="hours" aria-label="{{ __('Link works for') }}" class="dply-input mt-0 w-auto py-1 text-xs">
                            <option value="1">{{ __('1 hour') }}</option>
                            <option value="24">{{ __('1 day') }}</option>
                            <option value="168">{{ __('7 days') }}</option>
                        </select>
                        <x-sheet.button x-on:click="make('get')" x-bind:disabled="busy">{{ __('Download link') }}</x-sheet.button>
                        <x-sheet.button x-on:click="make('put')" x-bind:disabled="busy">{{ __('Upload link') }}</x-sheet.button>
                    </div>
                    <p x-show="error" x-cloak x-text="error" class="text-xs text-rose-700 dark:text-rose-300"></p>
                    <div x-show="url" x-cloak class="flex items-start gap-2">
                        <code x-text="url" class="min-w-0 flex-1 break-all rounded-lg bg-brand-sand/40 p-2 font-mono text-2xs text-brand-ink dark:bg-zinc-950"></code>
                        <x-sheet.button x-on:click="navigator.clipboard.writeText(url)">{{ __('Copy') }}</x-sheet.button>
                    </div>
                </div>

                <details class="rounded-xl border border-brand-ink/10 p-3 dark:border-brand-mist/15">
                    <summary class="cursor-pointer text-xs font-semibold text-brand-ink">{{ __('Write a small text file') }}</summary>
                    <div class="mt-3 grid gap-2">
                        <textarea wire:model="objectBody" rows="3" aria-label="{{ __('Contents') }}" class="dply-input mt-0 font-mono"></textarea>
                        <div><x-sheet.button variant="primary" wire:click="runObject('write')" wire:loading.attr="disabled" wire:target="runObject,refreshObjectList">{{ __('Save to the file name above') }}</x-sheet.button></div>
                    </div>
                </details>

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
            </div>

            {{-- Keys: S3 keys for tools outside the app. --}}
            <div x-show="tab === 'keys'" x-cloak class="grid gap-4"
                x-data="{ label: '', access: 'write', made: null, error: '', busy: false, async make() { this.busy = true; this.error = ''; const r = await $wire.$island('resources-object').createObjectKey(this.label, this.access); this.busy = false; if (r.error) { this.error = r.error; return } this.made = { ...r, label: this.label }; this.label = '' } }">
                <p class="text-xs text-brand-moss">{{ __('For aws cli, rclone, Cyberduck or another host. A key opens only this bucket. The app has its own key and needs none of these.') }}</p>
                <div>
                    <x-sheet.stat :label="__('Endpoint')">{{ $objectEndpoint }}</x-sheet.stat>
                    <x-sheet.stat :label="__('Bucket')">{{ $objectBucket }}</x-sheet.stat>
                    <x-sheet.stat :label="__('Region')">auto</x-sheet.stat>
                </div>

                <template x-if="made">
                    <div class="grid gap-1.5 rounded-xl border border-amber-500/30 bg-amber-500/5 p-3">
                        <p class="text-xs font-semibold text-brand-ink"><span x-text="made.label"></span> · {{ __('copy the secret now; it is not shown again') }}</p>
                        @foreach ([__('Access key ID') => 'made.id', __('Secret access key') => 'made.secret'] as $objectKeyLabel => $objectKeyExpr)
                            <div class="flex items-center justify-between gap-2 font-mono text-2xs">
                                <span class="shrink-0 text-brand-moss">{{ $objectKeyLabel }}</span>
                                <span class="min-w-0 break-all text-brand-ink" x-text="{{ $objectKeyExpr }}"></span>
                                <button type="button" x-on:click="navigator.clipboard.writeText({{ $objectKeyExpr }})" class="shrink-0 font-sans font-semibold text-brand-sage hover:underline">{{ __('Copy') }}</button>
                            </div>
                        @endforeach
                        <button type="button" x-on:click="made = null; $wire.$island('resources-object').refreshObjectKeys()" class="justify-self-start text-2xs font-semibold text-brand-sage hover:underline">{{ __('Done, I copied it') }}</button>
                    </div>
                </template>

                @if ($objectKeys->isNotEmpty())
                    <ul class="divide-y divide-brand-ink/10 rounded-xl border border-brand-ink/10 text-xs dark:divide-brand-mist/15 dark:border-brand-mist/15">
                        @foreach ($objectKeys as $bucketKey)
                            <li class="flex items-center justify-between gap-3 px-3.5 py-2.5" wire:key="object-key-{{ $bucketKey->id }}">
                                <span class="grid min-w-0">
                                    <span class="font-semibold text-brand-ink">{{ $bucketKey->label }}</span>
                                    <span class="truncate font-mono text-2xs text-brand-moss">{{ $bucketKey->token_id }} · {{ $bucketKey->access === 'read' ? __('read only') : __('read and write') }} · {{ $bucketKey->created_at?->diffForHumans() }}{{ $bucketKey->status === 'disabled' ? ' · '.__('off while paused') : '' }}</span>
                                </span>
                                <x-sheet.button variant="danger" wire:click="revokeObjectKey('{{ $bucketKey->id }}')" wire:island="resources-object" wire:confirm="{{ __('Revoke :name? Anything using it stops working.', ['name' => $bucketKey->label]) }}">{{ __('Revoke') }}</x-sheet.button>
                            </li>
                        @endforeach
                    </ul>
                @endif

                <div class="grid gap-2">
                    <div class="flex flex-wrap items-center gap-2">
                        <input type="text" x-model="label" maxlength="60" x-on:keydown.enter.prevent="label.trim() && make()" placeholder="{{ __('Key name, like Backups laptop') }}" aria-label="{{ __('Key name') }}" class="dply-input mt-0 min-w-0 flex-1" />
                        <select x-model="access" aria-label="{{ __('Access') }}" class="dply-input mt-0 w-auto text-xs">
                            <option value="write">{{ __('Read and write') }}</option>
                            <option value="read">{{ __('Read only') }}</option>
                        </select>
                        <x-sheet.button variant="primary" x-on:click="make()" x-bind:disabled="busy || label.trim() === ''">{{ __('Create key') }}</x-sheet.button>
                    </div>
                    <p x-show="error" x-cloak x-text="error" class="text-xs text-rose-700 dark:text-rose-300"></p>
                </div>
            </div>

            {{-- Settings --}}
            <div x-show="tab === 'settings'" x-cloak class="grid gap-5">
                <x-sheet.section :title="__('Public on your domain')">
                    @if ($isWorker)
                        <p class="text-xs text-brand-moss">{{ __('Worker apps serve their own routes: read env.:name in your code and return the file.', ['name' => $objectConnection['name'] ?? 'UPLOADS']) }}</p>
                    @else
                        <p class="text-xs text-brand-moss">{{ __('Serve files read-only under a path on every domain of this app. Anyone can read a file there by name; the list is never public.') }}</p>
                        <form x-data="{ path: {{ \Illuminate\Support\Js::from($objectPublicPath ?: '/files') }} }" x-on:submit.prevent="$wire.$island('resources-object').setObjectPublicPath(path)" class="flex flex-wrap items-center gap-2">
                            <input x-model="path" type="text" placeholder="/files" spellcheck="false" aria-label="{{ __('Public path') }}" class="dply-input mt-0 w-48 font-mono" />
                            <x-sheet.button type="submit" variant="primary">{{ $objectPublicPath === '' ? __('Make public') : __('Save') }}</x-sheet.button>
                            @if ($objectPublicPath !== '')
                                <x-sheet.button x-on:click="$wire.$island('resources-object').setObjectPublicPath('')">{{ __('Make private') }}</x-sheet.button>
                            @endif
                        </form>
                        @error('objectPublicPath')<x-sheet.note tone="danger">{{ $message }}</x-sheet.note>@enderror
                        <p class="text-2xs text-brand-mist">{{ $objectPublicPath !== '' ? __('Public at https://:host:path/… after the next deploy.', ['host' => $hostname, 'path' => $objectPublicPath]) : __('Private now.') }}</p>
                    @endif
                </x-sheet.section>

                @if (is_array($objectConnection) && $objectBuckets->count() > 1)
                    <x-sheet.section :title="__('Default disk')">
                        <div class="flex flex-wrap items-center justify-between gap-3">
                            <p class="min-w-0 flex-1 text-xs text-brand-moss">{{ $objectDefault === $objectDisk
                                ? __('This bucket is the default: Storage::put and AWS_BUCKET use it.')
                                : __('The default is :default. Storage::put and AWS_BUCKET use that one.', ['default' => $objectDefault]) }}</p>
                            @unless ($objectDefault === $objectDisk)
                                <x-sheet.button wire:click="makeDefaultStorage" wire:island="resources-object">{{ __('Make default') }}</x-sheet.button>
                            @endunless
                        </div>
                    </x-sheet.section>
                @endif

                @if (is_array($objectConnection))
                    <x-sheet.section :title="__('Sleep')">
                        <div class="flex flex-wrap items-center justify-between gap-3">
                            <p class="min-w-0 flex-1 text-xs text-brand-moss">{{ $objectAsleep
                                ? __('Asleep. Wake it, then deploy, to give the app its files again.')
                                : __('Takes the bucket off the app on the next deploy. Files are kept and still billed.') }}</p>
                            <x-sheet.button wire:click="sleepConnection({{ \Illuminate\Support\Js::from($objectHostName) }}, {{ $objectAsleep ? 'false' : 'true' }})" wire:island="resources-object">{{ $objectAsleep ? __('Wake') : __('Sleep') }}</x-sheet.button>
                        </div>
                    </x-sheet.section>
                @endif

                <x-sheet.danger :title="__('Remove')">
                    <div class="grid gap-3">
                        <div class="flex flex-wrap items-center justify-between gap-3">
                            <p class="min-w-0 flex-1 text-xs text-brand-moss">{{ __('Detach: the app stops using the bucket on the next deploy. The bucket and files are kept for other apps.') }}</p>
                            <x-sheet.button wire:click="removeConnection({{ \Illuminate\Support\Js::from($objectHostName) }})" x-on:click="$dispatch('close-modal', 'resources-object')">{{ __('Detach') }}</x-sheet.button>
                        </div>
                        <div class="flex flex-wrap items-center justify-between gap-3">
                            <p class="min-w-0 flex-1 text-xs text-brand-moss">{{ __('Delete: removes the bucket, its files and its S3 keys.') }}</p>
                            <x-sheet.button variant="danger" wire:click="askDeleteConnection({{ \Illuminate\Support\Js::from($objectHostName) }})" wire:island="resources-delete-connection" x-on:click="$dispatch('open-modal', 'resources-delete-connection')">{{ __('Delete bucket') }}</x-sheet.button>
                        </div>
                    </div>
                </x-sheet.danger>
            </div>
        </div>
    </x-sheet.body>
</x-sheet>
