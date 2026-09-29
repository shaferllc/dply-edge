@props([
    /** Livewire property this editor writes to, e.g. 'noteDraft'. */
    'property',
    /** Current server-side value — seeds the textarea and the preview pane. */
    'value' => '',
    'placeholder' => '',
    'maxLength' => 10000,
    'rows' => 6,
    /** Hint shown bottom-left, beside the character counter. */
    'hint' => null,
])

{{-- Markdown editor: toolbar, live preview and keyboard shortcuts around a
     plain <textarea>.

     The preview is rendered by <x-markdown> on the server, so it is the exact
     CommonMark output the saved note will use (same escaping, same link
     rules) — no client-side parser to drift from it and nothing added to the
     JS bundle beyond the toolbar behaviour itself.

     The textarea deliberately has no wire:model: dplyMarkdownEditor pushes
     every keystroke with a deferred $wire.set and only goes live when a
     preview pane is open. See resources/js/markdown-editor.js. --}}

@php
    $toolbarButton = 'inline-flex h-7 w-7 items-center justify-center rounded-lg text-brand-moss transition hover:bg-brand-sand/50 hover:text-brand-ink focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-sage/40';
    $modeTab = 'inline-flex h-7 items-center rounded-lg px-2.5 text-xs font-semibold transition focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-sage/40';
@endphp

<div
    x-data="dplyMarkdownEditor({
        property: @js($property),
        value: @js($value),
        maxLength: {{ (int) $maxLength }},
    })"
    {{-- Only additive classes here: the static list below stays applied, so the
         two can never fight over the same utility. --}}
    x-bind:class="fullscreen ? 'fixed inset-0 z-[95] m-0 flex flex-col overflow-hidden' : ''"
    x-on:markdown-editor-reset.window="onExternalReset($event)"
    class="rounded-xl border border-brand-ink/15 bg-white shadow-sm focus-within:border-brand-sage focus-within:ring-2 focus-within:ring-brand-sage/20"
    {{ $attributes }}
>
    {{-- Toolbar --}}
    <div class="flex flex-wrap items-center gap-1 border-b border-brand-ink/10 px-2 py-1.5">
        <div class="flex items-center gap-0.5 rounded-lg bg-brand-sand/30 p-0.5" role="group" aria-label="{{ __('Editor view') }}">
            <button
                type="button"
                x-on:click="setMode('write')"
                x-bind:class="mode === 'write' ? 'bg-white text-brand-ink shadow-sm' : 'text-brand-moss hover:text-brand-ink'"
                x-bind:aria-pressed="mode === 'write'"
                class="{{ $modeTab }}"
            >{{ __('Write') }}</button>
            <button
                type="button"
                x-on:click="setMode('preview')"
                x-bind:class="mode === 'preview' ? 'bg-white text-brand-ink shadow-sm' : 'text-brand-moss hover:text-brand-ink'"
                x-bind:aria-pressed="mode === 'preview'"
                class="{{ $modeTab }}"
            >{{ __('Preview') }}</button>
            <button
                type="button"
                x-on:click="setMode('split')"
                x-bind:class="mode === 'split' ? 'bg-white text-brand-ink shadow-sm' : 'text-brand-moss hover:text-brand-ink'"
                x-bind:aria-pressed="mode === 'split'"
                class="{{ $modeTab }} hidden sm:inline-flex"
            >{{ __('Split') }}</button>
        </div>

        <span class="mx-1 h-5 w-px bg-brand-ink/10" aria-hidden="true"></span>

        <div class="flex flex-wrap items-center gap-0.5" x-show="mode !== 'preview'">
            <button type="button" class="{{ $toolbarButton }} font-bold" x-on:click="wrap('**', 'bold text')" data-tooltip="{{ __('Bold') }} (⌘B)" aria-label="{{ __('Bold') }}">B</button>
            <button type="button" class="{{ $toolbarButton }} font-serif italic" x-on:click="wrap('_', 'italic text')" data-tooltip="{{ __('Italic') }} (⌘I)" aria-label="{{ __('Italic') }}">I</button>
            <button type="button" class="{{ $toolbarButton }} font-semibold" x-on:click="prefixLines('## ', /^#{1,6}\s/)" data-tooltip="{{ __('Heading') }}" aria-label="{{ __('Heading') }}">H</button>

            <span class="mx-1 h-5 w-px bg-brand-ink/10" aria-hidden="true"></span>

            <button type="button" class="{{ $toolbarButton }}" x-on:click="link()" data-tooltip="{{ __('Link') }} (⌘K)" aria-label="{{ __('Link') }}">
                <x-heroicon-o-link class="h-4 w-4" aria-hidden="true" />
            </button>
            <button type="button" class="{{ $toolbarButton }}" x-on:click="wrap('`', 'code')" data-tooltip="{{ __('Inline code') }} (⌘E)" aria-label="{{ __('Inline code') }}">
                <x-heroicon-o-code-bracket class="h-4 w-4" aria-hidden="true" />
            </button>
            <button type="button" class="{{ $toolbarButton }} font-serif text-base leading-none" x-on:click="prefixLines('&gt; ')" data-tooltip="{{ __('Quote') }}" aria-label="{{ __('Quote') }}">&ldquo;</button>

            <span class="mx-1 h-5 w-px bg-brand-ink/10" aria-hidden="true"></span>

            <button type="button" class="{{ $toolbarButton }}" x-on:click="prefixLines('- ', /^[-*+]\s/)" data-tooltip="{{ __('Bulleted list') }}" aria-label="{{ __('Bulleted list') }}">
                <x-heroicon-o-list-bullet class="h-4 w-4" aria-hidden="true" />
            </button>
            <button type="button" class="{{ $toolbarButton }}" x-on:click="orderedList()" data-tooltip="{{ __('Numbered list') }}" aria-label="{{ __('Numbered list') }}">
                <x-heroicon-o-numbered-list class="h-4 w-4" aria-hidden="true" />
            </button>
            <button type="button" class="{{ $toolbarButton }}" x-on:click="prefixLines('- [ ] ', /^-\s\[[ xX]\]\s/)" data-tooltip="{{ __('Task list') }}" aria-label="{{ __('Task list') }}">
                <x-heroicon-o-check-circle class="h-4 w-4" aria-hidden="true" />
            </button>

            <span class="mx-1 hidden h-5 w-px bg-brand-ink/10 sm:inline-block" aria-hidden="true"></span>

            <button type="button" class="{{ $toolbarButton }} hidden sm:inline-flex" x-on:click="codeBlock()" data-tooltip="{{ __('Code block') }}" aria-label="{{ __('Code block') }}">
                <x-heroicon-o-code-bracket-square class="h-4 w-4" aria-hidden="true" />
            </button>
            <button type="button" class="{{ $toolbarButton }} hidden sm:inline-flex" x-on:click="table()" data-tooltip="{{ __('Table') }}" aria-label="{{ __('Table') }}">
                <x-heroicon-o-table-cells class="h-4 w-4" aria-hidden="true" />
            </button>
        </div>

        <button
            type="button"
            class="{{ $toolbarButton }} ml-auto"
            x-on:click="toggleFullscreen()"
            x-bind:data-tooltip="fullscreen ? @js(__('Exit full screen')) : @js(__('Full screen'))"
            x-bind:aria-label="fullscreen ? @js(__('Exit full screen')) : @js(__('Full screen'))"
        >
            <x-heroicon-o-arrows-pointing-out class="h-4 w-4" x-show="! fullscreen" aria-hidden="true" />
            <x-heroicon-o-arrows-pointing-in class="h-4 w-4" x-show="fullscreen" x-cloak aria-hidden="true" />
        </button>
    </div>

    {{-- Editor + preview --}}
    <div
        class="flex min-h-0 flex-1 flex-col sm:flex-row"
        x-bind:class="mode === 'split' ? 'sm:divide-x sm:divide-brand-ink/10' : ''"
    >
        <textarea
            x-ref="input"
            x-show="mode !== 'preview'"
            x-on:input="onInput($event)"
            x-on:keydown="onKeydown($event)"
            x-on:paste="onPaste($event)"
            x-bind:class="mode === 'split' ? 'sm:w-1/2' : 'w-full'"
            rows="{{ (int) $rows }}"
            maxlength="{{ (int) $maxLength }}"
            spellcheck="true"
            @if ($placeholder) placeholder="{{ $placeholder }}" @endif
            class="block w-full flex-1 resize-y border-0 bg-transparent px-3 py-2.5 font-mono text-sm leading-relaxed text-brand-ink placeholder:font-sans placeholder:text-brand-mist focus:outline-none focus:ring-0"
        >{{ $value }}</textarea>

        <div
            x-show="mode !== 'write'"
            x-cloak
            x-bind:class="mode === 'split' ? 'sm:w-1/2' : 'w-full'"
            class="min-h-[6rem] flex-1 overflow-y-auto px-3 py-2.5"
            aria-live="polite"
        >
            @if (trim((string) $value) === '')
                <p class="text-sm italic text-brand-moss">{{ __('Nothing to preview yet.') }}</p>
            @else
                <x-markdown :content="$value" />
            @endif
        </div>
    </div>

    {{-- Footer: hint + counter --}}
    <div class="flex flex-wrap items-center gap-x-3 gap-y-1 border-t border-brand-ink/10 px-3 py-1.5">
        <p class="text-xs text-brand-moss">
            {{ $hint ?? __('Markdown supported. ⌘B bold · ⌘I italic · ⌘K link.') }}
        </p>
        <p
            class="ml-auto text-xs tabular-nums"
            x-bind:class="overLimit ? 'font-semibold text-rose-600' : 'text-brand-moss'"
        >
            <span x-text="characterCount.toLocaleString()">{{ number_format(mb_strlen((string) $value)) }}</span>
            <span aria-hidden="true">/</span>
            <span>{{ number_format((int) $maxLength) }}</span>
        </p>
    </div>
</div>
