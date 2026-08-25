@php
    $repoMode = is_string($repoFirewall['country_mode'] ?? null) ? strtoupper((string) $repoFirewall['country_mode']) : 'OFF';
    $repoCountries = is_array($repoFirewall['countries'] ?? null) ? $repoFirewall['countries'] : [];
    $hasRepoFirewall = $repoFirewall !== [] && $repoCountries !== [];
    $modeHelp = match ($country_mode) {
        'allow' => __('Only the countries below can reach this site. Every other country gets HTTP 403.'),
        'block' => __('Countries below are denied with HTTP 403. Everyone else passes through.'),
        default => __('Geo rules are off — every country can reach the site. Add countries and switch mode when you need a fence.'),
    };
@endphp

<div>
    <section class="border-b border-brand-ink/10 px-5 py-4 sm:px-6">
        @include('livewire.sites.edge.workspace.partials.feature-guide', [
            'docSlug' => 'edge-firewall',
            'what' => __('Geo firewall allows or blocks visitors by country at the Edge — using the request’s country code — before your pages, forms, or origin see the traffic. Blocked visitors get a plain HTTP 403 on the same URL.'),
            'steps' => [
                __('Pick a mode: Off (everyone), Allow listed only (hard allowlist), or Block listed (deny these countries).'),
                __('Search and add ISO country codes (e.g. US, DE). Remove chips to drop a country.'),
                __('Save — Edge republishes the host map; blocked traffic is rejected immediately at the Worker.'),
            ],
            'setupLinks' => [
                [
                    'label' => __('Rate limits'),
                    'href' => route('sites.show', ['server' => $server, 'site' => $site, 'section' => 'edge-rate-limits']),
                ],
                [
                    'label' => __('Bot protection'),
                    'href' => route('sites.show', ['server' => $server, 'site' => $site, 'section' => 'edge-bot-protection']),
                ],
            ],
            'tips' => [
                __('Country comes from Edge geo (ISO alpha-2). VPNs and privacy proxies can look like another country.'),
                __('Allow listed only with an empty list does nothing — add at least one country or leave mode Off.'),
                __('Geo is coarse; pair with Rate limits / Bot protection for abuse that isn’t country-shaped.'),
                __('Requires Dply-hosted Edge delivery for Worker enforcement.'),
            ],
        ])

        <div class="mb-5 rounded-2xl border border-brand-ink/10 bg-white px-4 py-4 dark:bg-brand-ink/95/40 sm:px-5">
            <p class="text-xs font-semibold uppercase tracking-[0.16em] text-brand-sage">{{ __('Modes') }}</p>
            <div class="mt-3 grid gap-3 sm:grid-cols-3">
                <div @class([
                    'rounded-xl border px-3 py-3',
                    'border-brand-forest/40 bg-brand-forest/5' => $country_mode === 'off',
                    'border-brand-ink/10 bg-brand-sand/20' => $country_mode !== 'off',
                ])>
                    <p class="text-2xs font-semibold uppercase tracking-wide text-brand-mist">{{ __('Off') }}</p>
                    <p class="mt-1 text-sm font-medium text-brand-ink">{{ __('Allow all') }}</p>
                    <p class="mt-1 text-xs leading-relaxed text-brand-moss">{{ __('No country checks. Default for most sites.') }}</p>
                </div>
                <div @class([
                    'rounded-xl border px-3 py-3',
                    'border-brand-forest/40 bg-brand-forest/5' => $country_mode === 'allow',
                    'border-brand-ink/10 bg-brand-sand/20' => $country_mode !== 'allow',
                ])>
                    <p class="text-2xs font-semibold uppercase tracking-wide text-brand-mist">{{ __('Allow listed only') }}</p>
                    <p class="mt-1 text-sm font-medium text-brand-ink">{{ __('Hard allowlist') }}</p>
                    <p class="mt-1 text-xs leading-relaxed text-brand-moss">{{ __('Only listed countries enter. Everyone else is 403’d — easy to lock yourself out if the list is wrong.') }}</p>
                </div>
                <div @class([
                    'rounded-xl border px-3 py-3',
                    'border-brand-forest/40 bg-brand-forest/5' => $country_mode === 'block',
                    'border-brand-ink/10 bg-brand-sand/20' => $country_mode !== 'block',
                ])>
                    <p class="text-2xs font-semibold uppercase tracking-wide text-brand-mist">{{ __('Block listed') }}</p>
                    <p class="mt-1 text-sm font-medium text-brand-ink">{{ __('Deny these countries') }}</p>
                    <p class="mt-1 text-xs leading-relaxed text-brand-moss">{{ __('Safer default for geo fences: block a few countries; the rest of the world still works.') }}</p>
                </div>
            </div>

            <div class="mt-4 rounded-xl border border-dashed border-brand-ink/15 bg-brand-sand/15 px-4 py-3">
                <p class="text-2xs font-semibold uppercase tracking-wide text-brand-mist">{{ __('What blocked visitors see') }}</p>
                <div class="mt-2 rounded-lg border border-brand-ink/10 bg-brand-ink/95 px-3 py-3 font-mono text-xs leading-relaxed text-brand-sand">
                    <span class="text-rose-300">HTTP/1.1 403 Forbidden</span><br>
                    <span class="text-zinc-400">Forbidden — content is not available in this region (XX).</span>
                </div>
                <p class="mt-2 text-xs text-brand-moss">{{ __('Plain text from Edge (not your build). Custom branded block pages are not available yet.') }}</p>
            </div>
        </div>

        <div class="mt-4 space-y-4">
            <div>
                <div class="flex items-center justify-between gap-2">
                    <label class="text-2xs font-semibold uppercase tracking-wide text-brand-mist" for="country-mode">{{ __('Mode') }}</label>
                    <span wire:loading.inline-flex wire:target="country_mode" class="inline-flex items-center gap-1.5 text-xs text-brand-moss">
                        <x-spinner size="sm" variant="muted" />
                        {{ __('Updating…') }}
                    </span>
                </div>
                <select id="country-mode" wire:model.live="country_mode" wire:loading.attr="disabled" wire:target="country_mode" class="mt-1 block w-full rounded-lg border border-brand-ink/15 bg-white px-3 py-2 text-sm text-brand-ink focus:border-brand-forest focus:ring-brand-forest disabled:opacity-60 dark:border-brand-mist/20 dark:bg-brand-ink/95">
                    <option value="off">{{ __('Off — allow all') }}</option>
                    <option value="allow">{{ __('Allow listed only') }}</option>
                    <option value="block">{{ __('Block listed') }}</option>
                </select>
                <p class="mt-1.5 text-xs text-brand-moss">{{ $modeHelp }}</p>
            </div>

            <div>
                <label class="text-2xs font-semibold uppercase tracking-wide text-brand-mist">{{ __('Countries') }}</label>
                <p class="mt-0.5 text-xs text-brand-moss">
                    @if ($country_mode === 'allow')
                        {{ __('These countries are allowed. Add every market you serve before enabling Allow listed only.') }}
                    @elseif ($country_mode === 'block')
                        {{ __('These countries are blocked. Leave the list empty only while drafting — empty + Block does not enforce yet.') }}
                    @else
                        {{ __('Optional until you switch mode. Search by name or ISO code, then Save.') }}
                    @endif
                </p>
                <div
                    x-data="{
                        query: '',
                        open: false,
                        focusedIndex: 0,
                        all: @js($allCountries),
                        selected: @entangle('selected_codes').live,
                        get filtered() {
                            const q = this.query.trim().toLowerCase();
                            const sel = this.selected || [];
                            const entries = Object.entries(this.all).filter(([code]) => !sel.includes(code));
                            if (q === '') return entries.slice(0, 12);
                            return entries.filter(([code, name]) =>
                                code.toLowerCase().includes(q) || name.toLowerCase().includes(q)
                            ).slice(0, 12);
                        },
                        addCode(code) {
                            $wire.addCountry(code);
                            this.query = '';
                            this.focusedIndex = 0;
                            this.$nextTick(() => this.$refs.searchInput?.focus());
                        },
                        removeCode(code) {
                            $wire.removeCountry(code);
                        },
                        onKey(e) {
                            const list = this.filtered;
                            if (e.key === 'ArrowDown') { e.preventDefault(); this.focusedIndex = Math.min(this.focusedIndex + 1, list.length - 1); this.open = true; }
                            else if (e.key === 'ArrowUp') { e.preventDefault(); this.focusedIndex = Math.max(this.focusedIndex - 1, 0); }
                            else if (e.key === 'Enter' && list[this.focusedIndex]) { e.preventDefault(); this.addCode(list[this.focusedIndex][0]); }
                            else if (e.key === 'Escape') { this.open = false; this.query = ''; }
                            else if (e.key === 'Backspace' && this.query === '' && (this.selected || []).length) {
                                this.removeCode(this.selected[this.selected.length - 1]);
                            }
                        },
                    }"
                    @click.outside="open = false"
                    class="relative mt-1"
                >
                    <div @class([
                        'flex min-h-[44px] flex-wrap items-center gap-1.5 rounded-lg border bg-white px-2 py-1.5 focus-within:border-brand-forest focus-within:ring-1 focus-within:ring-brand-forest dark:border-brand-mist/20 dark:bg-brand-ink/95',
                        'border-brand-ink/15' => $country_mode !== 'off',
                        'border-brand-ink/10 opacity-70' => $country_mode === 'off',
                    ])>
                        <template x-for="code in (selected || [])" :key="code">
                            <span class="inline-flex items-center gap-1 rounded-md bg-brand-sand/70 px-2 py-0.5 font-mono text-xs font-semibold text-brand-ink">
                                <span x-text="code"></span>
                                <span class="text-2xs font-normal text-brand-moss" x-text="all[code] ? '· ' + all[code] : ''"></span>
                                <button
                                    type="button"
                                    @click.prevent="removeCode(code)"
                                    class="ml-0.5 inline-flex h-4 w-4 items-center justify-center rounded-full text-brand-moss hover:bg-brand-ink/10 hover:text-brand-ink"
                                    :aria-label="`Remove ${code}`"
                                >
                                    <svg class="h-3 w-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg>
                                </button>
                            </span>
                        </template>
                        <input
                            x-ref="searchInput"
                            type="text"
                            x-model="query"
                            @focus="open = true"
                            @keydown="onKey($event)"
                            @disabled($country_mode === 'off')
                            wire:key="country-search-{{ $country_mode }}"
                            class="min-w-[10rem] flex-1 border-0 bg-transparent px-1 py-0.5 text-sm text-brand-ink placeholder-brand-mist focus:outline-none focus:ring-0 disabled:cursor-not-allowed disabled:bg-transparent"
                            placeholder="{{ __('Search country or code…') }}"
                            autocomplete="off"
                        />
                    </div>

                    <ul
                        x-show="open && filtered.length > 0"
                        x-cloak
                        x-transition.opacity
                        class="absolute z-20 mt-1 max-h-72 w-full overflow-auto rounded-lg border border-brand-ink/10 bg-white py-1 shadow-lg dark:bg-brand-ink/95"
                    >
                        <template x-for="(entry, index) in filtered" :key="entry[0]">
                            <li
                                @mousedown.prevent="addCode(entry[0])"
                                @mouseenter="focusedIndex = index"
                                :class="index === focusedIndex ? 'bg-brand-sand/70 text-brand-ink' : 'text-brand-ink hover:bg-brand-sand/40'"
                                class="flex cursor-pointer items-center justify-between gap-3 px-3 py-1.5 text-sm"
                            >
                                <span x-text="entry[1]"></span>
                                <span class="font-mono text-xs text-brand-mist" x-text="entry[0]"></span>
                            </li>
                        </template>
                    </ul>

                    <p
                        x-show="open && query.trim() !== '' && filtered.length === 0"
                        x-cloak
                        class="absolute z-20 mt-1 w-full rounded-lg border border-brand-ink/10 bg-white px-3 py-2 text-xs text-brand-mist shadow-lg dark:bg-brand-ink/95"
                    >
                        {{ __('No country matches that.') }}
                    </p>
                </div>
                <p class="mt-1 text-xs text-brand-mist">{{ __('↑/↓ navigate · Enter add · Backspace remove last') }}</p>
                @if ($country_mode !== 'off' && count($selected_codes) === 0)
                    <p class="mt-2 rounded-lg border border-amber-500/25 bg-amber-50 px-3 py-2 text-xs text-amber-900 dark:bg-amber-950/40 dark:text-amber-100">
                        {{ __('No countries selected — this mode will not enforce until you add at least one code and Save.') }}
                    </p>
                @endif
            </div>
        </div>
    </section>

    <div class="flex items-center justify-end gap-3 border-b border-brand-ink/10 bg-brand-sand/25 px-5 py-3 sm:px-6">
        <span wire:loading.inline-flex wire:target="save" class="inline-flex items-center gap-1.5 text-xs text-brand-moss">
            <x-spinner size="sm" variant="muted" />
            {{ __('Saving…') }}
        </span>
        @can('update', $site)
            <button type="button" wire:click="save" wire:loading.attr="disabled" wire:target="save" class="rounded-lg bg-brand-ink px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-brand-ink/90 disabled:cursor-wait disabled:opacity-60">
                {{ __('Save') }}
            </button>
        @endcan
    </div>

    <details class="group" @if ($hasRepoFirewall) open @endif>
        <summary class="flex cursor-pointer list-none items-center justify-between gap-3 bg-brand-sand/10 px-5 py-3.5 text-sm font-semibold text-brand-ink hover:bg-brand-sand/20 sm:px-6 [&::-webkit-details-marker]:hidden">
            <span class="inline-flex items-center gap-2">
                {{ __('Advanced') }}
                @if ($hasRepoFirewall)
                    <span class="rounded-full bg-brand-sand/60 px-2 py-0.5 font-mono text-2xs font-semibold uppercase tracking-wide text-brand-moss">{{ __('Repo') }}</span>
                @endif
            </span>
            <x-heroicon-m-chevron-down class="h-4 w-4 text-brand-mist transition group-open:rotate-180" />
        </summary>

        <div class="space-y-4 border-t border-brand-ink/10 px-5 py-4 sm:px-6">
            <div class="flex flex-wrap items-center justify-between gap-2">
                <p class="text-2xs font-semibold uppercase tracking-[0.16em] text-brand-mist">{{ __('From :file', ['file' => $sourcePath]) }}</p>
                <a
                    href="{{ route('sites.edge.dply-yaml', ['server' => $site->server_id, 'site' => $site->id]) }}"
                    class="inline-flex items-center gap-1 text-xs font-medium text-brand-sage hover:underline"
                >
                    <x-heroicon-o-arrow-down-tray class="h-3.5 w-3.5" aria-hidden="true" />
                    {{ __('Generate :file', ['file' => $sourcePath]) }}
                </a>
            </div>

            @if ($hasRepoFirewall)
                <p class="font-mono text-xs text-brand-ink">
                    <span class="text-brand-mist">{{ __('Mode:') }}</span> {{ $repoMode }} ·
                    <span class="text-brand-mist">{{ __('Countries:') }}</span> {{ implode(' ', $repoCountries) }}
                </p>
                <p class="text-xs text-brand-mist">{{ __('Dashboard rules merge with the repo on deploy.') }}</p>
            @else
                <p class="text-sm text-brand-moss">{{ __('None declared in :file yet.', ['file' => $sourcePath]) }}</p>
            @endif

            <x-edge-yaml-example :file="$sourcePath" :hint="__('Commit geo rules in the repo, or set them above in the dashboard.')">
firewall:
  country_mode: "block"   # off | allow | block
  countries:
    - "RU"
    - "CN"
            </x-edge-yaml-example>
        </div>
    </details>
</div>
