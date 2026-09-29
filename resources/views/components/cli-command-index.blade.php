@props([
    /** @var list<array{key: string, label: string, description: string, count: int}> */
    'groups' => [],
    /** @var list<array{id: string, group: string, title: string, command: string, summary: string, keywords: string, scope: string|null, server_bound: bool}> */
    'entries' => [],
    'total' => 0,
    /** Highlight server-bound rows when viewing a server workspace. */
    'emphasizeServer' => false,
])

@php
    $payload = [
        'groups' => $groups,
        'entries' => $entries,
        'total' => $total,
        'emphasizeServer' => $emphasizeServer,
        'labels' => [
            'all' => __('All'),
            'search' => __('Search commands…'),
            'empty' => __('No commands match.'),
            'clear' => __('Clear'),
            'copy' => __('Copy'),
            'copied' => __('Copied'),
            'thisServer' => __('This server'),
            'scope' => __('Scope'),
            'of' => __('of'),
            'commands' => __('commands'),
        ],
    ];
@endphp

<div
    {{ $attributes->class(['border-b border-brand-ink/10']) }}
    x-data="dplyCliCommandIndex(@js($payload))"
>
    <div class="flex flex-col gap-2 border-b border-brand-ink/10 bg-brand-sand/15 px-3 py-2.5 sm:flex-row sm:items-center sm:justify-between sm:px-4">
        <div class="relative min-w-0 flex-1">
            <span class="pointer-events-none absolute inset-y-0 start-0 flex items-center ps-2.5 text-brand-mist">
                <x-heroicon-o-magnifying-glass class="h-3.5 w-3.5" aria-hidden="true" />
            </span>
            <input :aria-label="labels.search"
                type="search"
                x-model="query"
                x-ref="search"
                :placeholder="labels.search"
                class="block h-8 w-full rounded-md border-brand-ink/15 bg-white py-1 ps-8 pe-20 text-xs shadow-sm focus:border-brand-sage focus:ring-brand-sage"
                autocomplete="off"
                spellcheck="false"
            />
            <span class="absolute inset-y-0 end-2 flex items-center gap-1.5">
                <kbd class="hidden rounded border border-brand-ink/10 bg-brand-sand/40 px-1 py-px font-mono text-2xs text-brand-mist sm:inline">/</kbd>
                <button
                    type="button"
                    x-show="query.length > 0"
                    x-cloak
                    @click="query = ''"
                    class="text-2xs font-semibold text-brand-moss hover:text-brand-ink"
                    x-text="labels.clear"
                ></button>
            </span>
        </div>
        <p class="shrink-0 text-xs tabular-nums text-brand-moss">
            <span class="font-semibold text-brand-ink" x-text="visibleCount"></span>
            <span x-text="labels.of"></span>
            <span x-text="total"></span>
            <span x-text="labels.commands"></span>
        </p>
    </div>

    <nav class="flex flex-wrap gap-1 border-b border-brand-ink/10 px-3 py-2 sm:px-4" aria-label="{{ __('Command groups') }}">
        <button
            type="button"
            @click="group = ''"
            :class="group === ''
                ? 'border-brand-ink bg-brand-ink text-brand-cream'
                : 'border-brand-ink/15 bg-white text-brand-moss hover:border-brand-ink/30 hover:text-brand-ink'"
            class="inline-flex h-6 items-center gap-1 rounded-md border px-2 text-xs font-semibold shadow-sm transition"
        >
            <span x-text="labels.all"></span>
            <span
                class="rounded px-1 py-px text-2xs tabular-nums"
                :class="group === '' ? 'bg-brand-cream/20 text-brand-cream' : 'bg-brand-sand/60 text-brand-moss'"
                x-text="total"
            ></span>
        </button>
        <template x-for="g in groups" :key="g.key">
            <button
                type="button"
                @click="group = g.key"
                :class="group === g.key
                    ? 'border-brand-ink bg-brand-ink text-brand-cream'
                    : 'border-brand-ink/15 bg-white text-brand-moss hover:border-brand-ink/30 hover:text-brand-ink'"
                class="inline-flex h-6 items-center gap-1 rounded-md border px-2 text-xs font-semibold shadow-sm transition"
            >
                <span x-text="g.label"></span>
                <span
                    class="rounded px-1 py-px text-2xs tabular-nums"
                    :class="group === g.key ? 'bg-brand-cream/20 text-brand-cream' : 'bg-brand-sand/60 text-brand-moss'"
                    x-text="g.count"
                ></span>
            </button>
        </template>
    </nav>

    <div class="max-h-[36rem] overflow-y-auto">
        <template x-if="visibleCount === 0">
            <div class="px-4 py-10 text-center text-sm text-brand-moss" x-text="labels.empty"></div>
        </template>

        <template x-for="g in visibleGroups" :key="'sec-'+g.key">
            <section class="border-b border-brand-ink/10 last:border-b-0">
                <div class="sticky top-0 z-10 flex items-baseline justify-between gap-2 border-b border-brand-ink/5 bg-brand-sand/30 px-3 py-1.5 backdrop-blur-sm sm:px-4">
                    <div class="min-w-0">
                        <h3 class="text-xs font-semibold text-brand-ink" x-text="g.label"></h3>
                        <p class="truncate text-2xs text-brand-mist" x-text="g.description"></p>
                    </div>
                    <span class="shrink-0 font-mono text-2xs tabular-nums text-brand-mist" x-text="groupVisibleCount(g.key)"></span>
                </div>
                <ul class="divide-y divide-brand-ink/5">
                    <template x-for="entry in entriesFor(g.key)" :key="entry.id">
                        <li
                            class="group flex flex-col gap-1 px-3 py-2 transition-colors hover:bg-brand-sand/20 sm:flex-row sm:items-center sm:justify-between sm:gap-3 sm:px-4"
                            :class="emphasizeServer && entry.server_bound ? 'bg-brand-sage/[0.04]' : ''"
                        >
                            <div class="min-w-0 flex-1">
                                <div class="flex flex-wrap items-center gap-x-2 gap-y-0.5">
                                    <p class="text-sm font-semibold text-brand-ink" x-text="entry.title"></p>
                                    <template x-if="entry.server_bound">
                                        <span class="inline-flex items-center rounded bg-brand-sage/15 px-1.5 py-px text-2xs font-semibold text-brand-forest ring-1 ring-brand-sage/25" x-text="labels.thisServer"></span>
                                    </template>
                                    <template x-if="entry.scope">
                                        <span class="inline-flex items-center gap-1 font-mono text-2xs text-brand-mist" :title="labels.scope">
                                            <span class="text-brand-mist" x-text="labels.scope + ':'"></span>
                                            <span x-text="entry.scope"></span>
                                        </span>
                                    </template>
                                </div>
                                <p class="mt-0.5 text-xs leading-relaxed text-brand-moss" x-text="entry.summary"></p>
                                <code class="mt-1 block select-all truncate rounded-md bg-brand-sand/50 px-2 py-1 font-mono text-2xs text-brand-ink ring-1 ring-inset ring-brand-ink/10" :title="entry.command" x-text="entry.command"></code>
                            </div>
                            <button
                                type="button"
                                class="inline-flex h-7 shrink-0 items-center gap-1 self-start rounded-md border border-brand-ink/15 bg-white px-2 text-xs font-semibold text-brand-ink shadow-sm hover:bg-brand-sand/40 sm:self-center"
                                @click="copy(entry)"
                            >
                                <x-heroicon-o-clipboard class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                                <span x-text="copiedId === entry.id ? labels.copied : labels.copy"></span>
                            </button>
                        </li>
                    </template>
                </ul>
            </section>
        </template>
    </div>
</div>

<script>
    window.dplyCliCommandIndex = window.dplyCliCommandIndex || function (payload) {
        return {
            groups: payload.groups ?? [],
            entries: payload.entries ?? [],
            total: payload.total ?? 0,
            emphasizeServer: !!payload.emphasizeServer,
            labels: payload.labels ?? {},
            query: '',
            group: '',
            copiedId: null,
            _copyTimer: null,
            _onSlash: null,

            init() {
                this._onSlash = (e) => {
                    if (e.key !== '/' || e.metaKey || e.ctrlKey || e.altKey) return;
                    const tag = (e.target?.tagName || '').toLowerCase();
                    if (tag === 'input' || tag === 'textarea' || e.target?.isContentEditable) return;
                    e.preventDefault();
                    this.$refs.search?.focus();
                };
                window.addEventListener('keydown', this._onSlash);
                this.$el.addEventListener('alpine:destroy', () => {
                    window.removeEventListener('keydown', this._onSlash);
                });
            },

            get q() {
                return this.query.trim().toLowerCase();
            },

            matches(entry) {
                if (this.group && entry.group !== this.group) return false;
                if (!this.q) return true;
                return (entry.keywords || '').toLowerCase().includes(this.q);
            },

            entriesFor(key) {
                return this.entries.filter((e) => e.group === key && this.matches(e));
            },

            groupVisibleCount(key) {
                return this.entriesFor(key).length;
            },

            get visibleGroups() {
                return this.groups.filter((g) => this.groupVisibleCount(g.key) > 0);
            },

            get visibleCount() {
                return this.entries.filter((e) => this.matches(e)).length;
            },

            copy(entry) {
                navigator.clipboard.writeText(entry.command);
                this.copiedId = entry.id;
                clearTimeout(this._copyTimer);
                this._copyTimer = setTimeout(() => { this.copiedId = null; }, 1500);
            },
        };
    };
</script>
