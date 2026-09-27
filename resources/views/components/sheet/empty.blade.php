{{-- Nothing to show yet: a dashed box with one line of text (and an optional action in the slot). --}}
@props(['message'])
<div {{ $attributes->merge(['class' => 'grid place-items-center gap-2 rounded-xl border border-dashed border-brand-ink/20 px-4 py-8 text-center text-xs text-brand-moss dark:border-brand-mist/25']) }}>
    <p>{{ $message }}</p>
    {{ $slot }}
</div>
