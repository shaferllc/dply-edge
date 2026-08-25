@props([
    /** Current page label (last crumb). */
    'current',
    /**
     * Icon key for the current page (allowlisted). Falls back to map-pin when omitted.
     * @var 'rectangle-group'|'globe-alt'|'server-stack'|'building-office-2'|'map-pin'|null
     */
    'currentIcon' => null,
    'docRoute' => null,
    'docSlug' => null,
    'docContextual' => false,
    'contextualDocSlug' => null,
    'docLabel' => null,
    'wrapperClass' => 'mb-6',
])

@php
    /** @var array<string, string> Heroicon outline names — never from raw user input */
    $iconComponents = [
        'home' => 'heroicon-o-home',
        'map-pin' => 'heroicon-o-map-pin',
        'rectangle-group' => 'heroicon-o-rectangle-group',
        'globe-alt' => 'heroicon-o-globe-alt',
        'server-stack' => 'heroicon-o-server-stack',
        'building-office-2' => 'heroicon-o-building-office-2',
    ];

    $currentKey = is_string($currentIcon) && array_key_exists($currentIcon, $iconComponents)
        ? $currentIcon
        : 'map-pin';
    $resolvedCurrentIcon = $iconComponents[$currentKey];

    $docLinkLabel = $docLabel ?? __('Documentation');
    $showDocs = filled($docRoute) || $docContextual;
    $resolvedContextualDocSlug = $docContextual
        ? $contextualDocSlug
        : null;
@endphp

<div {{ $attributes->class(['flex flex-wrap items-center justify-between gap-x-4 gap-y-3', $wrapperClass]) }}>
    <nav class="min-w-0 flex-1 text-sm text-brand-moss" aria-label="{{ __('Breadcrumb') }}">
        <ol class="flex flex-wrap items-center gap-x-2 gap-y-1">
            <li class="min-w-0">
                <a
                    href="{{ route('dashboard') }}"
                    class="group inline-flex h-5 max-w-full min-w-0 items-center gap-1.5 text-brand-moss transition-colors hover:text-brand-ink"
                    wire:navigate
                >
                    <x-dynamic-component
                        :component="$iconComponents['home']"
                        @class([
                            'h-4 w-4 shrink-0 opacity-90',
                            'text-brand-moss group-hover:text-brand-ink',
                        ])
                        aria-hidden="true"
                    />
                    <span class="truncate">{{ __('Dashboard') }}</span>
                </a>
            </li>
            <li class="flex h-5 select-none items-center text-brand-mist" aria-hidden="true">/</li>
            <li class="min-w-0">
                <span class="inline-flex h-5 max-w-full min-w-0 items-center gap-1.5 font-semibold text-brand-ink" aria-current="page">
                    <x-dynamic-component
                        :component="$resolvedCurrentIcon"
                        class="h-4 w-4 shrink-0 text-brand-ink opacity-90"
                        aria-hidden="true"
                    />
                    <span class="truncate">{{ $current }}</span>
                </span>
            </li>
        </ol>
    </nav>

    @if ($showDocs || isset($trailing))
        <div class="flex shrink-0 flex-wrap items-center justify-end gap-2">
            @if ($docContextual)
                <x-docs-link :slug="$resolvedContextualDocSlug">
                    <x-heroicon-o-document-text class="h-4 w-4 shrink-0 opacity-90" aria-hidden="true" />
                    {{ $docLinkLabel }}
                </x-docs-link>
            @elseif ($docRoute)
                <x-docs-link :doc-route="$docRoute" :doc-slug="$docSlug">
                    <x-heroicon-o-document-text class="h-4 w-4 shrink-0 opacity-90" aria-hidden="true" />
                    {{ $docLinkLabel }}
                </x-docs-link>
            @endif
            @isset($trailing)
                {{ $trailing }}
            @endisset
        </div>
    @endif
</div>
