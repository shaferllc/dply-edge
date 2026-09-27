@props(['label', 'sub' => null])

{{-- A money figure with its label, set apart from the fields around it. --}}
<div {{ $attributes->merge(['class' => 'rounded-xl border border-brand-ink/10 px-3.5 py-2.5 dark:border-brand-mist/20']) }}>
    <div class="flex items-baseline justify-between gap-3">
        <span class="text-xs text-brand-moss">{{ $label }}</span>
        <span class="font-mono text-lg font-bold tabular-nums text-brand-ink">{{ $slot }}</span>
    </div>
    @if ($sub)
        <p class="mt-0.5 text-2xs text-brand-mist">{{ $sub }}</p>
    @endif
</div>
