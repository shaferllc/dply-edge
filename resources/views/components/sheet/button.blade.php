@props(['variant' => 'secondary'])

{{-- Buttons inside sheets: primary (one per sheet), secondary, danger. --}}
@php $tag = $attributes->has('href') ? 'a' : 'button'; @endphp
<{{ $tag }} @if ($tag === 'button' && ! $attributes->has('type')) type="button" @endif {{ $attributes->class([
    'inline-flex items-center justify-center gap-1.5 whitespace-nowrap rounded-lg px-3 py-1.5 text-xs font-semibold transition disabled:cursor-not-allowed disabled:opacity-50',
    'bg-brand-ink text-brand-cream hover:bg-brand-forest' => $variant === 'primary',
    'border border-brand-ink/15 text-brand-ink hover:border-brand-ink/40 dark:border-brand-mist/25' => $variant === 'secondary',
    'border border-rose-500/40 text-rose-700 hover:bg-rose-500/10 dark:text-rose-300' => $variant === 'danger',
]) }}>{{ $slot }}</{{ $tag }}>
