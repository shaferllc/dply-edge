@props(['title'])

{{-- A destructive action with its consequence spelled out; put the button in the slot. --}}
<div {{ $attributes->merge(['class' => 'grid gap-2 rounded-xl border border-rose-500/30 p-3.5']) }}>
    <p class="text-sm font-semibold text-rose-700 dark:text-rose-300">{{ $title }}</p>
    {{ $slot }}
</div>
