@props(['cols' => 4])

{{-- A grid of x-sheet.metric tiles: 2 across on phones, up to $cols wide. --}}
<div {{ $attributes->class([
    'grid grid-cols-2 gap-2',
    'sm:grid-cols-3' => $cols === 3,
    'sm:grid-cols-2' => $cols === 2,
    'sm:grid-cols-4' => $cols >= 4,
]) }}>{{ $slot }}</div>
