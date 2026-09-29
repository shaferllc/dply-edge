@props([
    /** Livewire property holding the comma-separated tag string. */
    'property',
    'value' => '',
    /** @var array<int, string> Existing tags offered as autocomplete. */
    'suggestions' => [],
    'placeholder' => null,
    'max' => 12,
    'label' => null,
])

{{-- Chip-style editor over a plain comma-separated string.

     The bound property stays a string on purpose: ServerNote::parseTags() is
     the single normalisation point (trim, lowercase, de-dupe, cap), so the
     server never has to trust what the browser assembled. This input mirrors
     those rules client-side purely so the chips look like what will be saved. --}}

@php
    $listId = 'tag-suggestions-'.md5($property);
@endphp

<div
    x-data="{
        raw: @js($value),
        draft: '',
        max: {{ (int) $max }},
        parse(value) {
            return (value || '')
                .split(/[,\n]+/)
                .map((tag) => tag.trim().replace(/\s+/g, ' ').toLowerCase().slice(0, 32))
                .filter(Boolean)
                .filter((tag, i, all) => all.indexOf(tag) === i)
                .slice(0, this.max);
        },
        get chips() { return this.parse(this.raw); },
        get atLimit() { return this.chips.length >= this.max; },
        commit(chips) {
            this.raw = chips.join(', ');
            this.$wire.set(@js($property), this.raw, false);
        },
        add() {
            const next = this.parse(this.draft);
            this.draft = '';
            if (! next.length) return;
            this.commit(this.parse([...this.chips, ...next].join(', ')));
        },
        remove(tag) {
            this.commit(this.chips.filter((chip) => chip !== tag));
        },
        backspace() {
            // Only eat the keystroke when the field is empty, so backspacing
            // through a half-typed tag still behaves normally.
            if (this.draft !== '') return;
            this.commit(this.chips.slice(0, -1));
        },
    }"
    x-on:tag-input-reset.window="if ($event.detail.property === @js($property)) { raw = $event.detail.value ?? ''; draft = ''; }"
    class="flex flex-wrap items-center gap-1.5 rounded-xl border border-brand-ink/15 bg-white px-2 py-1.5 shadow-sm focus-within:border-brand-sage focus-within:ring-2 focus-within:ring-brand-sage/20"
    role="group"
    @if ($label) aria-label="{{ $label }}" @endif
    {{ $attributes }}
>
    <x-heroicon-o-tag class="ml-1 h-4 w-4 shrink-0 text-brand-moss" aria-hidden="true" />

    <template x-for="tag in chips" :key="tag">
        <span class="inline-flex items-center gap-1 rounded-full bg-brand-sand/60 py-0.5 pl-2 pr-1 text-xs font-medium text-brand-ink">
            <span x-text="tag"></span>
            <button
                type="button"
                x-on:click="remove(tag)"
                class="inline-flex h-4 w-4 items-center justify-center rounded-full text-brand-moss transition hover:bg-brand-ink/10 hover:text-brand-ink"
                x-bind:aria-label="@js(__('Remove tag')) + ' ' + tag"
            >
                <x-heroicon-o-x-mark class="h-3 w-3" aria-hidden="true" />
            </button>
        </span>
    </template>

    <input
        type="text"
        x-model="draft"
        x-on:keydown.enter.prevent="add()"
        x-on:keydown.tab="if (draft !== '') { $event.preventDefault(); add(); }"
        x-on:keydown="if ($event.key === ',') { $event.preventDefault(); add(); }"
        x-on:keydown.backspace="backspace()"
        x-on:blur="add()"
        x-bind:disabled="atLimit"
        list="{{ $listId }}"
        autocomplete="off"
        placeholder="{{ $placeholder ?? __('Add a tag…') }}"
        class="min-w-[8rem] flex-1 border-0 bg-transparent px-1 py-0.5 text-sm text-brand-ink placeholder:text-brand-mist focus:outline-none focus:ring-0 disabled:cursor-not-allowed"
    >

    <datalist id="{{ $listId }}">
        @foreach ($suggestions as $suggestion)
            <option value="{{ $suggestion }}"></option>
        @endforeach
    </datalist>

    <span class="pr-1 text-xs text-brand-moss" x-show="atLimit" x-cloak>
        {{ __('Tag limit reached') }}
    </span>
</div>
