@props(['label'])

{{-- A label / value line; stack several inside a div. --}}
<div class="flex items-center justify-between gap-3 border-b border-brand-ink/10 py-2 text-sm last:border-b-0 dark:border-brand-mist/15">
    <span class="text-brand-moss">{{ $label }}</span>
    <span {{ $attributes->merge(['class' => 'min-w-0 truncate text-right font-mono text-xs text-brand-ink']) }}>{{ $slot }}</span>
</div>
