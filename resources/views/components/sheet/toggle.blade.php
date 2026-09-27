@props(['label', 'help' => null, 'checked' => null])

{{-- A labelled switch row. Attributes (wire:model.live, wire:click, disabled)
     go on the checkbox, so it reacts at once. --}}
<label class="flex cursor-pointer items-start justify-between gap-4 border-b border-brand-ink/10 py-2.5 last:border-b-0 dark:border-brand-mist/15">
    <span class="min-w-0">
        <span class="block text-sm font-medium text-brand-ink">{{ $label }}</span>
        @if ($help)
            <span class="mt-0.5 block text-2xs leading-4 text-brand-mist">{{ $help }}</span>
        @endif
        {{ $slot }}
    </span>
    <span class="relative mt-0.5 inline-flex shrink-0">
        <input type="checkbox" @if ($checked !== null) @checked($checked) @endif {{ $attributes }} class="peer sr-only" />
        <span aria-hidden="true" class="h-5 w-9 rounded-full bg-brand-ink/20 transition-colors peer-checked:bg-brand-forest peer-focus-visible:ring-2 peer-focus-visible:ring-brand-forest/40 peer-disabled:opacity-50 dark:bg-brand-mist/25"></span>
        <span aria-hidden="true" class="pointer-events-none absolute left-0.5 top-0.5 h-4 w-4 rounded-full bg-white shadow transition-transform peer-checked:translate-x-4 dark:bg-zinc-950 dark:peer-checked:bg-zinc-950"></span>
    </span>
</label>
