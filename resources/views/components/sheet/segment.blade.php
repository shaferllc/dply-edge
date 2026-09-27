@props(['active' => false, 'disabled' => false])

{{-- One choice in x-sheet.segmented; pass wire:click on the element. --}}
<button
    type="button"
    aria-pressed="{{ $active ? 'true' : 'false' }}"
    @disabled($disabled)
    {{ $attributes->class([
        'min-w-0 flex-1 whitespace-nowrap rounded-md px-2.5 py-1 text-xs font-semibold transition disabled:cursor-not-allowed disabled:opacity-40',
        'bg-brand-ink text-brand-cream' => $active,
        'text-brand-moss hover:text-brand-ink' => ! $active,
    ]) }}
>{{ $slot }}</button>
