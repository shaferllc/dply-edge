@props([
    'label',
    /** Small line under the value: a unit, a limit, a time. */
    'note' => null,
    'tone' => null,
])

{{-- One figure in a grid of tiles (wrap several in x-sheet.metrics). The value is the slot. --}}
<div {{ $attributes->class([
    'min-w-0 rounded-xl border px-3.5 py-3',
    'border-brand-ink/10 dark:border-brand-mist/15' => $tone === null,
    'border-amber-500/40 bg-amber-500/10' => $tone === 'warn',
    'border-rose-500/30 bg-rose-500/10' => $tone === 'danger',
    'border-emerald-500/30 bg-emerald-500/10' => $tone === 'ok',
]) }}>
    <p class="truncate text-2xs font-semibold uppercase tracking-[0.14em] text-brand-mist">{{ $label }}</p>
    <div class="mt-1 truncate font-mono text-lg font-bold tabular-nums text-brand-ink">{{ $slot }}</div>
    @if ($note)
        <p class="mt-0.5 line-clamp-2 text-2xs leading-4 text-brand-moss" title="{{ $note }}">{{ $note }}</p>
    @endif
    {{ $extra ?? '' }}
</div>
