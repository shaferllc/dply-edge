@php
    $repoMode = is_string($repoFirewall['country_mode'] ?? null) ? strtoupper((string) $repoFirewall['country_mode']) : 'OFF';
    $repoCountries = is_array($repoFirewall['countries'] ?? null) ? $repoFirewall['countries'] : [];
    $hasRepoFirewall = $repoFirewall !== [] && $repoCountries !== [];
@endphp

<div>
    <section class="border-b border-brand-ink/10 px-5 py-4 sm:px-6">
        @include('livewire.sites.edge.workspace.partials.feature-guide', [
            'docSlug' => 'firewall',
            'what' => __('Geo firewall allows or blocks visitors by country at the Edge — using the request’s country code — before your pages, forms, or origin see the traffic. Blocked visitors get a plain HTTP 403 on the same URL.'),
            'steps' => [
                __('Click the rule and pick who can reach the site: everyone, only listed countries, or everyone except listed countries.'),
                __('Search and add countries by name or code (e.g. US, DE). Remove a chip to drop one.'),
                __('Save — the rule applies on the next request.'),
            ],
            'setupLinks' => [
                [
                    'label' => __('Rate limits'),
                    'href' => route('sites.show', ['server' => $server, 'site' => $site, 'section' => 'rate-limits']),
                ],
                [
                    'label' => __('Bot protection'),
                    'href' => route('sites.show', ['server' => $server, 'site' => $site, 'section' => 'bot-protection']),
                ],
            ],
            'tips' => [
                __('Country comes from Edge geo (ISO alpha-2). VPNs and privacy proxies can look like another country.'),
                __('A country rule needs at least one country; otherwise choose Everyone.'),
                __('Geo is coarse; pair with Rate limits / Bot protection for abuse that isn’t country-shaped.'),
                __('Requires Dply-hosted Edge delivery for Worker enforcement.'),
            ],
        ])

    </section>

    @php
        $canEdit = auth()->user()?->can('update', $site);
        $names = collect($selected_codes)->map(fn ($c) => $allCountries[$c] ?? $c);
        $nameList = $names->count() > 4
            ? $names->take(3)->implode(', ').' '.__('and :n more', ['n' => $names->count() - 3])
            : ($names->count() > 1 ? $names->slice(0, -1)->implode(', ').' '.__('and').' '.$names->last() : $names->implode(''));
        $ruleSentence = match ($country_mode) {
            'allow' => trans_choice('Only let in visitors from :count country|Only let in visitors from :count countries', count($selected_codes)),
            'block' => trans_choice('Block visitors from :count country|Block visitors from :count countries', count($selected_codes)),
            default => __('No country rule — everyone gets in'),
        };
    @endphp

    <section class="space-y-8 border-b border-brand-ink/10 px-5 py-8 sm:px-10 sm:py-10">
        <div>
            <p class="text-2xs font-semibold uppercase tracking-[0.16em] text-brand-mist">{{ __('Firewall') }}</p>
            <p class="mt-3 max-w-3xl text-2xl font-medium leading-snug tracking-tight text-brand-ink sm:text-3xl">
                @if ($country_mode === 'allow' && $names->isNotEmpty())
                    {{ __('Only visitors from') }} <span class="text-brand-sage">{{ $nameList }}</span> {{ __('can reach this site. Everyone else gets a “403 Forbidden”.') }}
                @elseif ($country_mode === 'block' && $names->isNotEmpty())
                    {{ __('Everyone can reach this site') }} <span class="text-amber-600 dark:text-amber-300">{{ __('except visitors from :names', ['names' => $nameList]) }}</span>{{ __(', who get a “403 Forbidden”.') }}
                @else
                    {{ __('Everyone can reach this site — there’s no country rule.') }}
                @endif
            </p>
        </div>

        <div>
            <p class="border-b border-brand-ink/10 pb-2 text-sm font-semibold text-brand-ink">{{ __('Rule') }}</p>
            @if ($canEdit)
                <button type="button" wire:click="editRule" class="flex min-h-12 w-full items-center gap-3 border-b border-brand-ink/10 py-3 text-left hover:bg-brand-sand/20">
                    <span class="flex-1 text-sm text-brand-ink sm:text-base">{{ $ruleSentence }}</span>
                    <span class="max-w-[40%] truncate font-mono text-xs text-brand-moss">{{ implode(' ', $selected_codes) }}</span>
                    <x-heroicon-m-chevron-right class="h-4 w-4 shrink-0 text-brand-mist" aria-hidden="true" />
                </button>
            @else
                <div class="flex min-h-12 items-center gap-3 border-b border-brand-ink/10 py-3">
                    <span class="flex-1 text-sm text-brand-ink sm:text-base">{{ $ruleSentence }}</span>
                    <span class="max-w-[40%] truncate font-mono text-xs text-brand-moss">{{ implode(' ', $selected_codes) }}</span>
                </div>
            @endif
            <div class="flex min-h-12 items-center gap-3 border-b border-brand-ink/10 py-3">
                <span class="flex-1 text-sm text-brand-moss sm:text-base">
                    @if ($hasRepoFirewall)
                        {{ __(':file also sets :mode for :codes — the two merge on deploy.', ['file' => $sourcePath, 'mode' => strtolower($repoMode), 'codes' => implode(' ', $repoCountries)]) }}
                    @else
                        {{ __(':file doesn’t set a country rule', ['file' => $sourcePath]) }}
                    @endif
                </span>
            </div>
        </div>

        <p class="text-xs text-brand-moss">
            {{ __('Blocked visitors see a plain “403 Forbidden — content is not available in this region” from the Edge, or your own page if you set one under Error pages. Country comes from their connection, so VPNs can get around it.') }}
        </p>
    </section>

    <x-modal name="edge-firewall" maxWidth="2xl" overlayClass="bg-brand-ink/40" focusable>
        @if ($editing)
            <div class="space-y-5 p-6 sm:p-7">
                <div class="flex items-start justify-between gap-4">
                    <h2 class="text-lg font-semibold text-brand-ink">{{ __('Who can reach this site') }}</h2>
                    <button type="button" wire:click="closeRule" class="dply-icon-btn h-9 w-9" aria-label="{{ __('Close') }}">
                        <x-heroicon-o-x-mark class="h-5 w-5" aria-hidden="true" />
                    </button>
                </div>

                <fieldset class="space-y-2">
                    @foreach ([
                        'off' => [__('Everyone'), __('No country checks. The default for most sites.')],
                        'allow' => [__('Only these countries'), __('Everyone else gets a 403. Check the country you work from is on the list, or you’ll lock yourself out.')],
                        'block' => [__('Everyone except these countries'), __('The listed countries get a 403. The rest of the world works.')],
                    ] as $value => [$label, $desc])
                        <label @class(['flex cursor-pointer items-start gap-3 rounded-lg border px-4 py-3', 'border-brand-sage bg-brand-sage/5' => $country_mode === $value, 'border-brand-ink/10 hover:border-brand-ink/25' => $country_mode !== $value])>
                            <input type="radio" wire:model.live="country_mode" value="{{ $value }}" class="mt-0.5 h-4 w-4 border-brand-ink/30 text-brand-forest focus:ring-brand-forest" />
                            <span>
                                <span class="block text-sm font-semibold text-brand-ink">{{ $label }}</span>
                                <span class="block text-xs text-brand-moss">{{ $desc }}</span>
                            </span>
                        </label>
                    @endforeach
                </fieldset>

                @if ($country_mode !== 'off')
                    <div>
                        <p class="text-2xs font-semibold uppercase tracking-[0.14em] text-brand-mist">{{ $country_mode === 'allow' ? __('Allowed countries') : __('Blocked countries') }}</p>
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
                        class="mt-1 max-h-64 w-full overflow-auto rounded-lg border border-brand-ink/10 bg-white py-1 dark:bg-brand-ink/95"
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
                        class="mt-1 w-full rounded-lg border border-brand-ink/10 bg-white px-3 py-2 text-xs text-brand-mist dark:bg-brand-ink/95"
                    >
                        {{ __('No country matches that.') }}
                    </p>
                </div>
                        <p class="mt-1 text-xs text-brand-mist">{{ __('↑/↓ navigate · Enter add · Backspace remove last') }}</p>
                        <x-input-error :messages="$errors->get('selected_codes')" class="mt-1" />
                    </div>
                @endif

                <div class="flex justify-end gap-2">
                    <button type="button" wire:click="closeRule" class="rounded-lg border border-brand-ink/15 px-4 py-2 text-sm font-medium text-brand-ink hover:bg-brand-sand/40">{{ __('Cancel') }}</button>
                    <x-primary-button type="button" wire:click="saveRule" wire:loading.attr="disabled" wire:target="saveRule">{{ __('Save') }}</x-primary-button>
                </div>
            </div>
        @endif
    </x-modal>

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
