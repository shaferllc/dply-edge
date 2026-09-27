@props([
    'selected' => false,
    'title',
    'description' => null,
    /** Right-hand detail, usually a price. */
    'meta' => null,
    'disabled' => false,
])

{{-- A radio card: pass wire:click (e.g. "$set('draftInstanceType', 'basic')") on the element. --}}
<button
    type="button"
    role="radio"
    aria-checked="{{ $selected ? 'true' : 'false' }}"
    @disabled($disabled)
    {{ $attributes->class([
        'grid w-full grid-cols-[auto_minmax(0,1fr)_auto] items-center gap-3 rounded-xl border px-3 py-2.5 text-left transition disabled:cursor-not-allowed disabled:opacity-50',
        'border-brand-forest bg-brand-forest/5 ring-1 ring-brand-forest' => $selected,
        'border-brand-ink/10 hover:border-brand-ink/30 dark:border-brand-mist/20' => ! $selected,
    ]) }}
>
    <span @class([
        'h-3.5 w-3.5 rounded-full',
        'border-4 border-brand-forest' => $selected,
        'border-[1.5px] border-brand-ink/25 dark:border-brand-mist/40' => ! $selected,
    ]) aria-hidden="true"></span>
    <span class="min-w-0">
        <span class="block text-sm font-semibold text-brand-ink">{{ $title }}</span>
        @if ($description)
            <span class="block text-2xs text-brand-mist">{{ $description }}</span>
        @endif
        {{ $slot }}
    </span>
    <span class="whitespace-nowrap font-mono text-2xs text-brand-moss">{{ $meta }}</span>
</button>
