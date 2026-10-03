@props(['tone' => 'info'])

{{-- A short explanation or warning inside a sheet. --}}
<div {{ $attributes->class([
    'rounded-xl px-3.5 py-2.5 text-xs leading-5',
    'bg-brand-sand/25 text-brand-moss dark:bg-zinc-800/60' => $tone === 'info',
    'border border-amber-500/40 bg-amber-500/10 text-brand-ink' => $tone === 'warn',
    'border border-rose-500/30 bg-rose-500/10 text-rose-800' => $tone === 'danger',
    'border border-emerald-500/30 bg-emerald-500/10 text-emerald-900' => $tone === 'ok',
]) }}>{{ $slot }}</div>
