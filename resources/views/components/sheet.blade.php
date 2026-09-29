@props([
    'name',
    'show' => false,
    'maxWidth' => 'lg',
])

@php
$maxWidth = [
    'sm' => 'sm:max-w-md',
    'md' => 'sm:max-w-md',
    'lg' => 'sm:max-w-lg',
    'xl' => 'sm:max-w-xl',
    '2xl' => 'sm:max-w-xl',
    '3xl' => 'sm:max-w-2xl',
    '4xl' => 'sm:max-w-3xl',
    '5xl' => 'sm:max-w-4xl',
    '6xl' => 'sm:max-w-5xl',
][$maxWidth];
@endphp

{{-- A side sheet that layers: a drop-in for <x-modal> (same name, show, and
     open-modal / close-modal events). A sheet opened while another is open
     sits on top of it; the one underneath steps back and peeks out on the
     left, and clicking it returns there. Esc closes the top sheet, the scrim
     closes them all. Stack: Alpine.store('sheets') in resources/js/app.js. --}}
@teleport('body')
<div
    x-data="{
        name: @js($name),
        get index() { return $store.sheets.stack.indexOf(this.name) },
        get depth() { return this.index < 0 ? -1 : $store.sheets.stack.length - 1 - this.index },
    }"
    {{-- In a Livewire island (edge Resources) a sheet re-renders only with its
         own island's actions. Render its island when it first opens with its
         body still pending (<x-sheet.pending>), and when it is back on top
         after the sheet over it closed: that one may have changed it. --}}
    x-init="
        @js($show) && $store.sheets.open(name);
        $watch('depth', (depth, was) => {
            const stale = was > 0 || $el.querySelector('[data-sheet-pending]');
            const island = depth === 0 && stale && window.dplySheetIsland?.($el);
            if (island) $wire.$island(island).$refresh();
        });
    "
    x-on:open-modal.window="$event.detail == name && $store.sheets.open(name)"
    x-on:close-modal.window="$event.detail == name && $store.sheets.close(name)"
    {{-- Every sheet hears the key; the first to act marks it so the one
         newly on top does not close too. --}}
    x-on:keydown.escape.window="if (depth === 0 && ! $event.defaultPrevented) { $event.preventDefault(); $store.sheets.close(name) }"
    x-on:close.stop="$store.sheets.close(name)"
    {{-- Teleported nodes survive a wire:navigate swap; drop the stack with the page. --}}
    x-on:livewire:navigating.window="$store.sheets.clear()"
    class="pointer-events-none fixed inset-0"
    x-bind:style="`z-index: ${100 + Math.max(index, 0)}`"
>
    <div
        x-show="index === 0"
        x-cloak
        x-transition.opacity.duration.200ms
        class="pointer-events-auto absolute inset-0 bg-brand-ink/30 dark:bg-black/60"
        x-on:click="$store.sheets.clear()"
        aria-hidden="true"
    ></div>

    {{-- Focus: x-trap holds Tab inside the open sheet (focus-trap's own stack
         pauses the one underneath); the store puts focus back on the opener
         when the last sheet closes. --}}
    <section
        role="dialog"
        aria-modal="true"
        tabindex="-1"
        x-bind:aria-labelledby="'sheet-title-' + name"
        x-trap.noreturn="index >= 0"
        x-show="index >= 0"
        x-cloak
        x-transition:enter="duration-300 ease-[cubic-bezier(.2,.8,.2,1)]"
        x-transition:enter-start="translate-x-full"
        x-transition:enter-end="translate-x-0"
        x-transition:leave="duration-200 ease-in"
        x-transition:leave-start="translate-x-0"
        x-transition:leave-end="translate-x-full"
        {{-- Object form: a style string would wipe the display:none x-show sets.
             Slide-in uses Tailwind v4's translate-x (the CSS `translate`
             property); stepping back uses `transform`. Both are transitioned. --}}
        x-bind:style="{ transform: depth > 0 ? `translateX(${-depth * 28}px) scale(${1 - depth * 0.035})` : '', transformOrigin: 'left center' }"
        x-bind:class="depth > 0 && 'brightness-90 dark:brightness-75'"
        class="pointer-events-auto absolute inset-y-0 right-0 flex w-full outline-none {{ $maxWidth }} flex-col border-l border-brand-ink/15 bg-white shadow-2xl transition-[translate,transform,filter] duration-300 ease-[cubic-bezier(.2,.8,.2,1)] sm:rounded-l-2xl dark:border-brand-mist/20 dark:bg-zinc-900"
    >
        <div class="min-h-0 flex-1 overflow-y-auto" x-bind:inert="depth > 0">
            {{ $slot }}
        </div>
        {{-- Pushed back: the visible strip is the way back to this sheet. --}}
        <button
            type="button"
            x-show="depth > 0"
            x-on:click="$store.sheets.back(name)"
            class="absolute inset-0 cursor-pointer sm:rounded-l-2xl"
            aria-label="{{ __('Back') }}"
        ></button>
    </section>
</div>
@endteleport
