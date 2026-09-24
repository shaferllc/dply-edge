@props([
    'items' => [],
    /**
     * The site in scope, when this trail is on a site workspace page. Passed
     * explicitly so the Deploy/Console controls survive Livewire update renders
     * (where request()->route('site') is the livewire route, not the page route).
     */
    'site' => null,
    /** Tailwind classes on the outer wrapper (spacing below the bar). */
    'wrapperClass' => 'mb-6',
    /** Named route for contextual docs (e.g. docs.index, docs.markdown). */
    'docRoute' => null,
    /** When docRoute is docs.markdown, pass the slug (e.g. source-control). */
    'docSlug' => null,
    /** Open the in-app docs panel for the current page (ContextualDocResolver). */
    'docContextual' => false,
    /** Pre-resolved markdown slug for docContextual. */
    'contextualDocSlug' => null,
    'docLabel' => null,
])

@php
    $crumbs = array_values(array_filter(
        $items ?? [],
        static fn ($item): bool => is_array($item) && isset($item['label'])
    ));

    $docLinkLabel = $docLabel ?? __('Documentation');
    $showDocs = filled($docRoute) || $docContextual;
    $resolvedContextualDocSlug = $docContextual
        ? $contextualDocSlug
        : null;

    /** @var array<string, string> Heroicon outline component names (allowlisted; never from raw user input). */
    $iconComponents = [
        'home' => 'heroicon-o-home',
        'map-pin' => 'heroicon-o-map-pin',
        'folder' => 'heroicon-o-folder',
        'folder-open' => 'heroicon-o-folder-open',
        'user-circle' => 'heroicon-o-user-circle',
        'user' => 'heroicon-o-user',
        'cog-6-tooth' => 'heroicon-o-cog-6-tooth',
        'cog' => 'heroicon-o-cog',
        'server' => 'heroicon-o-server',
        'code-bracket-square' => 'heroicon-o-code-bracket-square',
        'key' => 'heroicon-o-key',
        'bolt' => 'heroicon-o-bolt',
        'bolt-slash' => 'heroicon-o-bolt-slash',
        'shield-check' => 'heroicon-o-shield-check',
        'shield-exclamation' => 'heroicon-o-shield-exclamation',
        'bell-alert' => 'heroicon-o-bell-alert',
        'bell' => 'heroicon-o-bell',
        'archive-box' => 'heroicon-o-archive-box',
        'user-group' => 'heroicon-o-user-group',
        'rectangle-stack' => 'heroicon-o-rectangle-stack',
        'cloud' => 'heroicon-o-cloud',
        'document-text' => 'heroicon-o-document-text',
        'document-duplicate' => 'heroicon-o-document-duplicate',
        'gift' => 'heroicon-o-gift',
        'trash' => 'heroicon-o-trash',
        'building-office-2' => 'heroicon-o-building-office-2',
        'rectangle-group' => 'heroicon-o-rectangle-group',
        'server-stack' => 'heroicon-o-server-stack',
        'globe-alt' => 'heroicon-o-globe-alt',
        'rocket-launch' => 'heroicon-o-rocket-launch',
        'cpu-chip' => 'heroicon-o-cpu-chip',
        'light-bulb' => 'heroicon-o-light-bulb',
        'chart-bar' => 'heroicon-o-chart-bar',
        'command-line' => 'heroicon-o-command-line',
        'computer-desktop' => 'heroicon-o-computer-desktop',
        'circle-stack' => 'heroicon-o-circle-stack',
        'clock' => 'heroicon-o-clock',
        'clipboard-document-list' => 'heroicon-o-clipboard-document-list',
        'wrench-screwdriver' => 'heroicon-o-wrench-screwdriver',
        'cog-8-tooth' => 'heroicon-o-cog-8-tooth',
        'play-circle' => 'heroicon-o-play-circle',
        'heart' => 'heroicon-o-heart',
        'lock-closed' => 'heroicon-o-lock-closed',
        'wrench' => 'heroicon-o-wrench',
        'calendar-days' => 'heroicon-o-calendar-days',
        'square-3-stack-3d' => 'heroicon-o-square-3-stack-3d',
        'arrow-path-rounded-square' => 'heroicon-o-arrow-path-rounded-square',
        'finger-print' => 'heroicon-o-finger-print',
        'share' => 'heroicon-o-share',
        'signal' => 'heroicon-o-signal',
        'cube-transparent' => 'heroicon-o-cube-transparent',
        'cube' => 'heroicon-o-cube',
        'puzzle-piece' => 'heroicon-o-puzzle-piece',
        'arrows-right-left' => 'heroicon-o-arrows-right-left',
        'exclamation-circle' => 'heroicon-o-exclamation-circle',
        'exclamation-triangle' => 'heroicon-o-exclamation-triangle',
        'sparkles' => 'heroicon-o-sparkles',
    ];

    $resolveIcon = static function (?string $icon, bool $isLast, bool $isFirst, int $crumbCount) use ($iconComponents): ?string {
        if ($icon === null) {
            return match (true) {
                $crumbCount === 1 => $iconComponents['home'],
                $isFirst => $iconComponents['home'],
                $isLast => $iconComponents['map-pin'],
                default => $iconComponents['folder'],
            };
        }

        if ($icon === '') {
            return null;
        }

        if (isset($iconComponents[$icon])) {
            return $iconComponents[$icon];
        }

        if (str_starts_with($icon, 'heroicon-o-') && preg_match('/^heroicon-o-[a-z0-9-]+$/', $icon) === 1) {
            return $icon;
        }

        if (preg_match('/^[a-z0-9-]+$/', $icon) === 1) {
            return 'heroicon-o-'.$icon;
        }

        return $isLast ? $iconComponents['map-pin'] : $iconComponents['folder'];
    };

    $breadcrumbSite = $site instanceof \App\Models\Site ? $site : request()->route('site');
@endphp

@if ($crumbs !== [])
    <div {{ $attributes->class(['flex flex-wrap items-center justify-between gap-x-4 gap-y-3', $wrapperClass]) }}>
        <nav class="min-w-0 flex-1 text-sm text-brand-moss" aria-label="{{ __('Breadcrumb') }}">
            <ol class="flex flex-wrap items-center gap-x-2 gap-y-1 leading-none">
                @foreach ($crumbs as $item)
                    @php
                        $href = $item['href'] ?? $item['url'] ?? null;
                        $hasHref = filled($href);
                        $isLast = $loop->last;

                        if (array_key_exists('icon', $item) && $item['icon'] === false) {
                            $resolvedIcon = null;
                        } else {
                            $iconKey = isset($item['icon']) && is_string($item['icon']) ? $item['icon'] : null;
                            $resolvedIcon = $resolveIcon($iconKey, $isLast, $loop->first, count($crumbs));
                        }

                        // A crumb may carry an `avatar` seed (e.g. a server/site
                        // name) to render the gradient initials avatar in place of
                        // the heroicon — matching the list rows + workspace header.
                        // An optional `avatar_image` URL (e.g. a site's uploaded
                        // logo) shows the image, falling back to the gradient.
                        $crumbAvatar = isset($item['avatar']) && is_string($item['avatar']) && $item['avatar'] !== ''
                            ? $item['avatar']
                            : null;
                        $crumbAvatarImage = isset($item['avatar_image']) && is_string($item['avatar_image']) && $item['avatar_image'] !== ''
                            ? $item['avatar_image']
                            : null;

                        // A crumb that names the project always carries the project
                        // logo, even when the caller only passed the label.
                        if (
                            $crumbAvatar === null
                            && $breadcrumbSite instanceof \App\Models\Site
                            && (string) ($item['label'] ?? '') !== ''
                            && (string) $item['label'] === (string) $breadcrumbSite->name
                        ) {
                            $projectCrumb = \App\Support\Sites\SiteWorkspaceBreadcrumbs::projectItem($breadcrumbSite);
                            $crumbAvatar = $projectCrumb['avatar'];
                            $crumbAvatarImage ??= $projectCrumb['avatar_image'];
                        }
                    @endphp
                    @if (! $loop->first)
                        <li class="flex h-5 select-none items-center self-center text-brand-mist" aria-hidden="true">/</li>
                    @endif
                    <li class="flex min-w-0 items-center self-center">
                        @if ($hasHref)
                            <a
                                href="{{ $href }}"
                                class="group inline-flex h-5 max-w-full min-w-0 items-center gap-1.5 text-brand-moss transition-colors hover:text-brand-ink"
                                wire:navigate
                            >
                                @if ($crumbAvatar)
                                    <x-entity-avatar :seed="$crumbAvatar" :image="$crumbAvatarImage" rounded="rounded-md" class="h-5 w-5 text-3xs" />
                                @elseif ($resolvedIcon)
                                    <x-dynamic-component
                                        :component="$resolvedIcon"
                                        @class([
                                            'h-4 w-4 shrink-0 opacity-90',
                                            'text-brand-moss group-hover:text-brand-ink',
                                        ])
                                        aria-hidden="true"
                                    />
                                @endif
                                <span class="truncate">{{ $item['label'] }}</span>
                            </a>
                        @elseif ($isLast)
                            <span class="inline-flex h-5 max-w-full min-w-0 items-center gap-1.5 font-semibold text-brand-ink" aria-current="page">
                                @if ($crumbAvatar)
                                    <x-entity-avatar :seed="$crumbAvatar" :image="$crumbAvatarImage" rounded="rounded-md" class="h-5 w-5 text-3xs" />
                                @elseif ($resolvedIcon)
                                    <x-dynamic-component
                                        :component="$resolvedIcon"
                                        class="h-4 w-4 shrink-0 text-brand-ink opacity-90"
                                        aria-hidden="true"
                                    />
                                @endif
                                <span class="truncate">{{ $item['label'] }}</span>
                            </span>
                        @else
                            <span class="inline-flex h-5 max-w-full min-w-0 items-center gap-1.5 font-medium text-brand-ink">
                                @if ($crumbAvatar)
                                    <x-entity-avatar :seed="$crumbAvatar" :image="$crumbAvatarImage" rounded="rounded-md" class="h-5 w-5 text-3xs" />
                                @elseif ($resolvedIcon)
                                    <x-dynamic-component
                                        :component="$resolvedIcon"
                                        class="h-4 w-4 shrink-0 text-brand-ink opacity-90"
                                        aria-hidden="true"
                                    />
                                @endif
                                <span class="truncate">{{ $item['label'] }}</span>
                            </span>
                        @endif
                    </li>
                @endforeach
            </ol>
        </nav>

        @if ($showDocs || isset($trailing) || $breadcrumbSite instanceof \App\Models\Site)
            <div class="flex shrink-0 flex-wrap items-center justify-end gap-2">
                @if ($docContextual)
                    <x-docs-link :slug="$resolvedContextualDocSlug">
                        <x-heroicon-o-document-text class="h-3.5 w-3.5 shrink-0 opacity-90" aria-hidden="true" />
                        {{ $docLinkLabel }}
                    </x-docs-link>
                @elseif ($docRoute)
                    <x-docs-link :doc-route="$docRoute" :doc-slug="$docSlug">
                        <x-heroicon-o-document-text class="h-3.5 w-3.5 shrink-0 opacity-90" aria-hidden="true" />
                        {{ $docLinkLabel }}
                    </x-docs-link>
                @endif
                @isset($trailing)
                    {{ $trailing }}
                @endisset
            </div>
        @endif
    </div>
@endif
