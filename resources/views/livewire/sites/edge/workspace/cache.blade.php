<div>
    <section class="border-b border-brand-ink/10 px-5 py-4 sm:px-6">
        @include('livewire.sites.edge.workspace.partials.feature-guide', [
            'what' => __('The edge cache stores public responses so the next visit does not wait on the app. Hashed files such as JavaScript, CSS, and images are kept. HTML that sets a session cookie is not.'),
            'steps' => [
                __('A public GET that returns 200 with a cache lifetime is stored.'),
                __('The next matching request is served from that copy.'),
                __('Purge a path or a cache tag when you need the following request to fetch a fresh copy.'),
            ],
        ])

        <div class="grid gap-5">
        <form wire:submit="saveOptions" class="grid gap-4 rounded-2xl border border-brand-ink/10 p-4 dark:border-brand-mist/15">
            <x-sheet.section :title="__('Cache options')">
                <p class="text-xs text-brand-moss">{{ __('These apply on the next request after you save. HTML that sets a session cookie is still skipped.') }}</p>
            </x-sheet.section>
            <x-sheet.field :label="__('What to store')">
                <x-sheet.options>
                    @foreach (['off' => __('Off'), 'assets' => __('Static assets'), 'standard' => __('Honor response cache headers'), 'everything' => __('Public GET responses')] as $value => $label)
                        <x-sheet.option wire:click="$set('mode', '{{ $value }}')" :selected="$mode === $value" :title="$label" />
                    @endforeach
                </x-sheet.options>
            </x-sheet.field>
            <x-sheet.field :label="__('Query string')">
                <x-sheet.segmented>
                    @foreach (['ignore' => __('Ignore'), 'include' => __('Include in the cache key')] as $value => $label)
                        <x-sheet.segment wire:click="$set('queryString', '{{ $value }}')" :active="$queryString === $value">{{ $label }}</x-sheet.segment>
                    @endforeach
                </x-sheet.segmented>
            </x-sheet.field>
            <div class="grid gap-3 sm:grid-cols-2">
                <x-sheet.field :label="__('Edge TTL')" for="cache-edge-ttl">
                    <select id="cache-edge-ttl" wire:model="edgeTtl" class="dply-input mt-0">
                        @foreach (['60' => __('1 minute'), '300' => __('5 minutes'), '3600' => __('1 hour'), '14400' => __('4 hours'), '86400' => __('1 day'), '604800' => __('7 days'), '2592000' => __('30 days'), '31536000' => __('1 year')] as $value => $label)
                            <option value="{{ $value }}" @selected($edgeTtl === (string) $value)>{{ $label }}</option>
                        @endforeach
                    </select>
                </x-sheet.field>
                <x-sheet.field :label="__('Browser TTL')" for="cache-browser-ttl">
                    <select id="cache-browser-ttl" wire:model="browserTtl" class="dply-input mt-0">
                        @foreach (['0' => __('Revalidate each visit'), '300' => __('5 minutes'), '3600' => __('1 hour'), '86400' => __('1 day'), '604800' => __('7 days'), '2592000' => __('30 days'), '31536000' => __('1 year')] as $value => $label)
                            <option value="{{ $value }}" @selected($browserTtl === (string) $value)>{{ $label }}</option>
                        @endforeach
                    </select>
                </x-sheet.field>
            </div>
            @can('update', $site)
                <div>
                    <x-sheet.button type="submit" variant="primary" wire:loading.attr="disabled" wire:target="saveOptions">
                        <span wire:loading.remove wire:target="saveOptions">{{ __('Save') }}</span>
                        <span wire:loading wire:target="saveOptions">{{ __('Saving…') }}</span>
                    </x-sheet.button>
                </div>
            @endcan
        </form>

        <section class="grid gap-2" wire:init="loadEntries">
            <div class="flex items-center justify-between gap-3">
                <h3 class="text-2xs font-semibold uppercase tracking-[0.16em] text-brand-mist">{{ __('Stored copies') }}</h3>
            @can('update', $site)
                    <x-sheet.button
                        variant="danger"
                        wire:click="openConfirmActionModal('clearAll', [], {{ \Illuminate\Support\Js::from(__('Clear all cache')) }}, {{ \Illuminate\Support\Js::from(__('Drop every stored copy for this app. The next visit fetches a fresh response.')) }}, {{ \Illuminate\Support\Js::from(__('Clear all')) }}, true)"
                    >{{ __('Clear all') }}</x-sheet.button>
            @endcan
            </div>
            @if ($entries === null)
                <p class="text-xs text-brand-mist">{{ __('Loading stored copies…') }}</p>
            @elseif ($listMessage !== '')
                <x-sheet.note>{{ $listMessage }}</x-sheet.note>
            @elseif ($entries === [])
                <x-sheet.empty :message="__('Nothing stored yet. Cached responses show up here after visitors request them.')" />
            @else
                <ul class="divide-y divide-brand-ink/10 rounded-xl border border-brand-ink/10 dark:divide-brand-mist/15 dark:border-brand-mist/15">
                    @foreach ($entries as $entry)
                        <li class="flex items-center justify-between gap-3 px-3.5 py-2.5">
                            <div class="min-w-0">
                                <p class="truncate font-mono text-xs text-brand-ink">{{ $entry['path'] }}</p>
                                <p class="text-2xs text-brand-mist">
                                    @if ($entry['expires_at'])
                                        {{ __('Expires :time', ['time' => \Illuminate\Support\Carbon::createFromTimestamp($entry['expires_at'])->diffForHumans()]) }}
                                    @else
                                        {{ __('No expiry recorded') }}
                                    @endif
                                </p>
                            </div>
                            @can('update', $site)
                                <x-sheet.button wire:click="purgeStored({{ \Illuminate\Support\Js::from($entry['path']) }})" class="shrink-0">{{ __('Purge') }}</x-sheet.button>
                            @endcan
                        </li>
                    @endforeach
                </ul>
            @endif
        </section>

        <div class="grid gap-4 lg:grid-cols-2">
            @foreach ([['purgeByPath', 'purgePath', __('Purge a path'), __('Drop the stored copy for one URL path. The next request fetches it again.'), '/build/assets/app.js'], ['purgeByTag', 'purgeTag', __('Purge a tag'), __('Send Cache-Tag: assets on the response, then purge that name. This drops the latest copy stored under the tag.'), 'article-42']] as [$action, $model, $title, $help, $placeholder])
                <form wire:submit="{{ $action }}" class="grid gap-3 rounded-2xl border border-brand-ink/10 p-4 dark:border-brand-mist/15">
                    <x-sheet.field :label="$title" :help="$help" for="cache-{{ $model }}">
                        <div class="flex gap-2">
                            <input id="cache-{{ $model }}" type="text" wire:model="{{ $model }}" autocomplete="off" spellcheck="false" placeholder="{{ $placeholder }}" class="dply-input mt-0 min-w-0 flex-1 font-mono text-xs" />
                            @can('update', $site)
                                <x-sheet.button type="submit" wire:loading.attr="disabled" wire:target="{{ $action }}" class="shrink-0">
                                    <span wire:loading.remove wire:target="{{ $action }}">{{ __('Purge') }}</span>
                                    <span wire:loading wire:target="{{ $action }}">{{ __('Purging…') }}</span>
                                </x-sheet.button>
                            @endcan
                        </div>
                    </x-sheet.field>
                    @error($model) <x-sheet.note tone="danger">{{ $message }}</x-sheet.note> @enderror
                </form>
            @endforeach
        </div>
        </div>
    </section>

    @include('livewire.partials.confirm-action-modal')
</div>
