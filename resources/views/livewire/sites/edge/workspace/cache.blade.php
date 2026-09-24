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

        <form wire:submit="saveOptions" class="mb-4 rounded-2xl border border-brand-ink/10 px-4 py-4">
            <p class="text-xs font-semibold text-brand-ink">{{ __('Cache options') }}</p>
            <p class="mt-1 text-sm text-brand-moss">{{ __('These apply on the next request after you save. HTML that sets a session cookie is still skipped.') }}</p>
            <div class="mt-3 grid gap-3 sm:grid-cols-2">
                <label class="block text-xs text-brand-moss">
                    {{ __('What to store') }}
                    <select wire:model="mode" class="mt-1 block w-full rounded-lg border border-brand-ink/15 bg-white px-3 py-2 text-sm text-brand-ink dark:border-brand-mist/20 dark:bg-zinc-900">
                        <option value="off">{{ __('Off') }}</option>
                        <option value="assets">{{ __('Static assets') }}</option>
                        <option value="standard">{{ __('Honor response cache headers') }}</option>
                        <option value="everything">{{ __('Public GET responses') }}</option>
                    </select>
                </label>
                <label class="block text-xs text-brand-moss">
                    {{ __('Query string') }}
                    <select wire:model="queryString" class="mt-1 block w-full rounded-lg border border-brand-ink/15 bg-white px-3 py-2 text-sm text-brand-ink dark:border-brand-mist/20 dark:bg-zinc-900">
                        <option value="ignore">{{ __('Ignore') }}</option>
                        <option value="include">{{ __('Include in the cache key') }}</option>
                    </select>
                </label>
                <label class="block text-xs text-brand-moss">
                    {{ __('Edge TTL') }}
                    <select wire:model="edgeTtl" class="mt-1 block w-full rounded-lg border border-brand-ink/15 bg-white px-3 py-2 text-sm text-brand-ink dark:border-brand-mist/20 dark:bg-zinc-900">
                        <option value="60">{{ __('1 minute') }}</option>
                        <option value="300">{{ __('5 minutes') }}</option>
                        <option value="3600">{{ __('1 hour') }}</option>
                        <option value="14400">{{ __('4 hours') }}</option>
                        <option value="86400">{{ __('1 day') }}</option>
                        <option value="604800">{{ __('7 days') }}</option>
                        <option value="2592000">{{ __('30 days') }}</option>
                        <option value="31536000">{{ __('1 year') }}</option>
                    </select>
                </label>
                <label class="block text-xs text-brand-moss">
                    {{ __('Browser TTL') }}
                    <select wire:model="browserTtl" class="mt-1 block w-full rounded-lg border border-brand-ink/15 bg-white px-3 py-2 text-sm text-brand-ink dark:border-brand-mist/20 dark:bg-zinc-900">
                        <option value="0">{{ __('Revalidate each visit') }}</option>
                        <option value="300">{{ __('5 minutes') }}</option>
                        <option value="3600">{{ __('1 hour') }}</option>
                        <option value="86400">{{ __('1 day') }}</option>
                        <option value="604800">{{ __('7 days') }}</option>
                        <option value="2592000">{{ __('30 days') }}</option>
                        <option value="31536000">{{ __('1 year') }}</option>
                    </select>
                </label>
            </div>
            @can('update', $site)
                <button type="submit" wire:loading.attr="disabled" wire:target="saveOptions" class="mt-3 inline-flex items-center rounded-lg bg-brand-ink px-3 py-2 text-xs font-semibold text-white hover:bg-brand-ink/90 disabled:cursor-wait disabled:opacity-60 dark:bg-brand-sand dark:text-brand-ink">
                    <span wire:loading.remove wire:target="saveOptions">{{ __('Save') }}</span>
                    <span wire:loading wire:target="saveOptions">{{ __('Saving…') }}</span>
                </button>
            @endcan
        </form>

        <div class="mb-4">
            <p class="text-xs font-semibold text-brand-ink">{{ __('Stored copies') }}</p>
            @if ($listMessage !== '')
                <p class="mt-1 text-sm text-brand-moss">{{ $listMessage }}</p>
            @elseif ($entries === [])
                <p class="mt-1 text-sm text-brand-moss">{{ __('Nothing stored yet. Cached responses show up here after visitors request them.') }}</p>
            @else
                <ul class="mt-2 divide-y divide-brand-ink/10 rounded-2xl border border-brand-ink/10">
                    @foreach ($entries as $entry)
                        <li class="flex items-center justify-between gap-3 px-4 py-2">
                            <div class="min-w-0">
                                <p class="truncate font-mono text-xs text-brand-ink">{{ $entry['path'] }}</p>
                                <p class="text-xs text-brand-mist">
                                    @if ($entry['expires_at'])
                                        {{ __('Expires :time', ['time' => \Illuminate\Support\Carbon::createFromTimestamp($entry['expires_at'])->diffForHumans()]) }}
                                    @else
                                        {{ __('No expiry recorded') }}
                                    @endif
                                </p>
                            </div>
                            @can('update', $site)
                                <button type="button" wire:click="purgeStored(@js($entry['path']))" class="shrink-0 rounded-lg border border-brand-ink/15 px-2 py-1 text-xs font-semibold text-brand-ink hover:bg-brand-sand/40">
                                    {{ __('Purge') }}
                                </button>
                            @endcan
                        </li>
                    @endforeach
                </ul>
            @endif
        </div>

        <div class="grid gap-4 lg:grid-cols-2">
            <form wire:submit="purgeByPath" class="rounded-2xl border border-brand-ink/10 px-4 py-4">
                <p class="text-xs font-semibold text-brand-ink">{{ __('Purge a path') }}</p>
                <p class="mt-1 text-sm text-brand-moss">{{ __('Drop the stored copy for one URL path. The next request fetches it again.') }}</p>
                <div class="mt-3 flex flex-wrap items-center gap-2">
                    <input
                        type="text"
                        wire:model="purgePath"
                        autocomplete="off"
                        spellcheck="false"
                        placeholder="/build/assets/app.js"
                        class="min-w-0 flex-1 rounded-lg border border-brand-ink/15 bg-white px-3 py-2 font-mono text-xs text-brand-ink shadow-sm focus:border-brand-sage focus:ring-1 focus:ring-brand-sage dark:border-brand-mist/20 dark:bg-zinc-900"
                    />
                    @can('update', $site)
                        <button type="submit" wire:loading.attr="disabled" wire:target="purgeByPath" class="inline-flex items-center rounded-lg border border-brand-ink/15 bg-white px-3 py-2 text-xs font-semibold text-brand-ink hover:bg-brand-sand/40 disabled:cursor-wait disabled:opacity-60">
                            <span wire:loading.remove wire:target="purgeByPath">{{ __('Purge') }}</span>
                            <span wire:loading wire:target="purgeByPath">{{ __('Purging…') }}</span>
                        </button>
                    @endcan
                </div>
                @error('purgePath') <p class="mt-1 text-xs text-rose-700">{{ $message }}</p> @enderror
            </form>

            <form wire:submit="purgeByTag" class="rounded-2xl border border-brand-ink/10 px-4 py-4">
                <p class="text-xs font-semibold text-brand-ink">{{ __('Purge a tag') }}</p>
                <p class="mt-1 text-sm text-brand-moss">{{ __('Drop entries marked with a cache tag, such as article-42.') }}</p>
                <div class="mt-3 flex flex-wrap items-center gap-2">
                    <input
                        type="text"
                        wire:model="purgeTag"
                        autocomplete="off"
                        spellcheck="false"
                        placeholder="article-42"
                        class="min-w-0 flex-1 rounded-lg border border-brand-ink/15 bg-white px-3 py-2 font-mono text-xs text-brand-ink shadow-sm focus:border-brand-sage focus:ring-1 focus:ring-brand-sage dark:border-brand-mist/20 dark:bg-zinc-900"
                    />
                    @can('update', $site)
                        <button type="submit" wire:loading.attr="disabled" wire:target="purgeByTag" class="inline-flex items-center rounded-lg border border-brand-ink/15 bg-white px-3 py-2 text-xs font-semibold text-brand-ink hover:bg-brand-sand/40 disabled:cursor-wait disabled:opacity-60">
                            <span wire:loading.remove wire:target="purgeByTag">{{ __('Purge') }}</span>
                            <span wire:loading wire:target="purgeByTag">{{ __('Purging…') }}</span>
                        </button>
                    @endcan
                </div>
                @error('purgeTag') <p class="mt-1 text-xs text-rose-700">{{ $message }}</p> @enderror
            </form>
        </div>
    </section>
</div>
