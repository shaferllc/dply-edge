@props([
    // list<array{label?:string,name?:string,url:string}> — the repos to show.
    'repositories' => [],
    // Livewire property (a repo URL) this picker reads + writes.
    'property' => 'repository_selection',
    // wire:target that drives the loading state (usually the account select).
    'target' => 'source_control_account_id',
    // id for the trigger button (label `for=` should match).
    'triggerId' => 'repository_selection',
    // Currently-selected URL, so the trigger can show its label on first paint.
    'selected' => null,
    'placeholder' => null,
    'searchPlaceholder' => null,
    'mono' => true,
    'emptyMessage' => null,
])

@php
    $placeholder ??= __('Select a repository…');
    $searchPlaceholder ??= __('Filter repositories…');
    $emptyMessage ??= __('No repositories match your filter.');
    // Normalize to a flat {label,url} list once, server-side, so the Alpine
    // filter has a stable shape regardless of what the provider browser returns.
    $normalized = collect($repositories)
        ->map(fn ($r) => [
            'label' => (string) ($r['label'] ?? $r['name'] ?? $r['url'] ?? $r['id'] ?? ''),
            'url' => (string) ($r['url'] ?? $r['value'] ?? $r['id'] ?? ''),
        ])
        ->values();
    $selectedRepository = $normalized->firstWhere('url', $selected);
@endphp

{{--
    Searchable repository combobox shared by the Edge and BYO/VM site-creation
    flows. The full list is seeded via @js and filtered entirely client-side in
    Alpine (no Livewire round-trip per keystroke); choosing a row writes the URL
    into the `property` Livewire prop via $wire.set — which fires that prop's
    updated* hook exactly like a native <select wire:model.live> would.
--}}
<div
    x-data="{
        open: false,
        search: '',
        active: 0,
        prop: @js($property),
        label: @js($selectedRepository['label'] ?? ''),
        repos: @js($normalized),
        get filtered() {
            const q = this.search.trim().toLowerCase();
            if (q === '') return this.repos;
            return this.repos.filter(r => r.label.toLowerCase().includes(q) || r.url.toLowerCase().includes(q));
        },
        get current() { return $wire.get(this.prop); },
        toggle() { this.open ? this.close() : this.openList(); },
        openList() { this.open = true; this.active = 0; this.$nextTick(() => this.$refs.repoSearch && this.$refs.repoSearch.focus()); },
        close() { this.open = false; this.search = ''; },
        move(delta) {
            const n = this.filtered.length;
            if (n === 0) return;
            this.active = (this.active + delta + n) % n;
            this.$nextTick(() => { const el = this.$refs.list && this.$refs.list.querySelector('[data-active=true]'); el && el.scrollIntoView({ block: 'nearest' }); });
        },
        chooseActive() { const r = this.filtered[this.active]; if (r) this.choose(r.url); },
        choose(url) {
            const repo = this.repos.find(r => r.url === url);
            if (repo) this.label = repo.label;
            this.close();
            $wire.set(this.prop, url);
            this.$nextTick(() => this.$refs.trigger && this.$refs.trigger.focus());
        },
    }"
    {{ $attributes->merge(['class' => 'relative']) }}
    wire:loading.class="opacity-60 pointer-events-none" wire:target="{{ $target }}"
    x-on:keydown.escape.window="close()"
>
    <button
        id="{{ $triggerId }}"
        x-ref="trigger"
        type="button"
        x-on:click="toggle()"
        x-on:keydown.arrow-down.prevent="openList()"
        x-bind:aria-expanded="open.toString()"
        aria-haspopup="listbox"
        wire:loading.attr="disabled" wire:target="{{ $target }}"
        class="flex w-full items-center justify-between gap-3 rounded-xl border border-brand-ink/15 bg-white px-3.5 py-2.5 text-left text-sm shadow-sm transition focus:border-brand-ink focus:outline-none focus:ring-1 focus:ring-brand-ink dark:bg-brand-ink/20"
    >
        <span @class(['min-w-0 flex-1 truncate text-sm text-brand-ink', 'font-mono' => $mono])>
            <span wire:loading.remove wire:target="{{ $target }}" x-text="label || @js($placeholder)"></span>
            <span wire:loading wire:target="{{ $target }}" class="inline-flex items-center gap-1.5 text-brand-moss">
                <x-spinner size="sm" />
                {{ __('Loading repositories…') }}
            </span>
        </span>
        <x-heroicon-m-chevron-down class="h-4 w-4 shrink-0 text-brand-moss transition-transform" x-bind:class="{ 'rotate-180': open }" aria-hidden="true" />
    </button>

    <div
        x-cloak
        x-show="open"
        x-transition.origin.top
        x-on:click.outside="close()"
        role="listbox"
        class="absolute z-30 mt-2 w-full rounded-2xl border border-brand-ink/10 bg-white p-2 shadow-xl shadow-brand-ink/10 dark:bg-zinc-900"
    >
        <div class="relative">
            <x-heroicon-o-magnifying-glass class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-brand-moss" aria-hidden="true" />
            <input
                x-ref="repoSearch"
                x-model="search"
                x-on:input="active = 0"
                x-on:keydown.arrow-down.prevent="move(1)"
                x-on:keydown.arrow-up.prevent="move(-1)"
                x-on:keydown.enter.prevent="chooseActive()"
                type="text"
                placeholder="{{ $searchPlaceholder }}"
                class="block w-full rounded-xl border border-brand-ink/15 bg-white py-2 pl-9 pr-3 text-sm text-brand-ink placeholder:text-brand-mist focus:border-brand-ink focus:outline-none focus:ring-1 focus:ring-brand-ink"
            />
        </div>

        <div x-ref="list" class="mt-2 max-h-64 space-y-1 overflow-y-auto overscroll-contain pr-1">
            <template x-for="(repo, i) in filtered" :key="repo.url">
                <button
                    type="button"
                    role="option"
                    x-on:click="choose(repo.url)"
                    x-on:mousemove="active = i"
                    x-bind:data-active="(i === active).toString()"
                    x-bind:aria-selected="(repo.url === current).toString()"
                    x-bind:class="{
                        'bg-brand-sage/15 ring-1 ring-brand-sage/30': i === active,
                        'bg-brand-sand/40 ring-1 ring-brand-ink/15': repo.url === current && i !== active,
                        'hover:bg-brand-sand/30': i !== active && repo.url !== current,
                    }"
                    class="block w-full rounded-lg px-3 py-2 text-left text-sm text-brand-ink transition {{ $mono ? 'font-mono' : '' }}"
                    x-text="repo.label"
                ></button>
            </template>
            <p x-show="filtered.length === 0" class="px-3 py-2 text-xs text-brand-moss">{{ $emptyMessage }}</p>
        </div>
    </div>
</div>
