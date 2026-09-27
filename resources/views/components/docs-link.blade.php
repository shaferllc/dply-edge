@props([
    'slug' => null,
    'docRoute' => null,
    'docSlug' => null,
    'label' => null,
    'size' => 'sm',
])

{{-- Opens the public docs page (/docs/{slug}) in a new tab. `docRoute` is
     ignored: docs.markdown is the raw .md, not a page to land on. Slugs must
     exist in docs/site/nav.json (tests/Feature/InAppDocLinksTest.php). --}}
@php
    $target = is_string($slug) && $slug !== '' ? $slug : (is_string($docSlug) && $docSlug !== '' ? $docSlug : null);
    $sizeClasses = $size === 'md'
        ? 'rounded-xl px-3 py-2 text-sm'
        : 'rounded-lg px-3 py-1.5 text-xs';
@endphp

@if ($target !== null)
    <a
        href="{{ route('docs.show', $target) }}"
        target="_blank"
        rel="noopener"
        {{ $attributes->merge(['class' => 'inline-flex items-center gap-1.5 border border-brand-ink/15 bg-white font-medium text-brand-ink shadow-sm transition-colors hover:bg-brand-sand/40 dark:border-brand-mist/20 dark:bg-zinc-900 dark:text-brand-cream dark:hover:bg-zinc-800 '.$sizeClasses]) }}
    >
        {{ $slot->isEmpty() ? ($label ?? __('Documentation')) : $slot }}
    </a>
@endif
