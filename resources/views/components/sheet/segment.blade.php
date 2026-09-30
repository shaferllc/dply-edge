@props(['active' => false, 'disabled' => false, 'bind' => null])

{{-- One choice in x-sheet.segmented; pass wire:click on the element. Or pass bind (an Alpine expression) to pick it on the client with no round trip. --}}
@php
    $on = 'bg-brand-ink text-brand-cream';
    $off = 'text-brand-moss hover:text-brand-ink';
@endphp
<button
    type="button"
    @if ($bind)
        x-bind:aria-pressed="({{ $bind }}) ? 'true' : 'false'"
        x-bind:class="({{ $bind }}) ? '{{ $on }}' : '{{ $off }}'"
    @else
        aria-pressed="{{ $active ? 'true' : 'false' }}"
    @endif
    @disabled($disabled)
    {{ $attributes->class([
        'min-w-0 flex-1 whitespace-nowrap rounded-md px-2.5 py-1 text-xs font-semibold transition disabled:cursor-not-allowed disabled:opacity-40',
        $on => ! $bind && $active,
        $off => ! $bind && ! $active,
    ]) }}
>{{ $slot }}</button>
