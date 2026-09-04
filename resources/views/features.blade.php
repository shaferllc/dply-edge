<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="dark">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <x-seo-meta
        title="Features"
        description="Deploy static, SSG, and SSR sites straight from git to a global edge network. Preview URLs on every branch, custom domains with automatic TLS, access rules, and request analytics." />
    <meta name="csrf-token" content="{{ csrf_token() }}">

    @include('partials.theme-head')

    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @livewireStyles
    <style>[x-cloak] { display: none !important; }</style>
</head>
<body class="bg-edge-void font-display text-edge-text antialiased">
@include('partials.skip-link')
    <div class="fixed inset-0 -z-20 bg-edge-void"></div>
    <div class="fixed inset-0 -z-10 bg-mesh-brand"></div>

    <x-edge-marketing-header active="features" />

    <main id="main-content" tabindex="-1">
        @php
            /*
             * Four sections, four items each. The page used to run ten sections and
             * ~57 items, which read as a specification rather than a features page —
             * keep new entries to one line, and replace rather than append.
             */
            $sections = [
                [
                    'id' => 'deploy',
                    'title' => 'Deploys & previews',
                    'lede' => 'Push a branch. Get a URL.',
                    'items' => [
                        ['title' => 'A preview per branch', 'body' => 'Every branch and pull request builds to its own URL, posted back as a commit check and a PR comment.'],
                        ['title' => 'Review on the real thing', 'body' => 'Reviewers comment directly on a preview and mark it approved or changes-requested.'],
                        ['title' => 'Instant rollback', 'body' => 'Releases are immutable and keep their own URL, so going back is a pointer change, not a rebuild.'],
                        ['title' => 'Private by default', 'body' => 'Preview URLs need a signed link or an org login, so unreleased work is never indexable.'],
                    ],
                ],
                [
                    'id' => 'build',
                    'title' => 'Builds & frameworks',
                    'lede' => 'Settings are inferred, and all of them are yours to override.',
                    'items' => [
                        ['title' => 'Framework detection', 'body' => 'Next.js, Nuxt, Astro, SvelteKit, Remix, Gatsby, Vite, Hono, Eleventy, Hugo, Jekyll, and plain HTML.'],
                        ['title' => 'Static, SSG & SSR', 'body' => 'Static output is served from object storage; server-rendered routes run at the edge, under one hostname.'],
                        ['title' => 'Cached builds', 'body' => 'Dependencies are cached between deploys, so an unchanged lockfile skips the cold install.'],
                        ['title' => 'Environment per site', 'body' => 'Separate production and preview values, encrypted at rest, applied at build and runtime.'],
                    ],
                ],
                [
                    'id' => 'delivery',
                    'title' => 'Domains & delivery',
                    'lede' => 'What happens on the request path, before your app sees it.',
                    'items' => [
                        ['title' => 'Custom domains & TLS', 'body' => 'Guided DNS, with certificates issued and renewed for you. Every site gets a permanent hostname on day one.'],
                        ['title' => 'Redirects, rewrites & headers', 'body' => 'Declarative rules evaluated at the edge — no origin round trip to send a 301.'],
                        ['title' => 'Edge middleware', 'body' => 'Your own code on the request path for rewrites, geo routing, and A/B splits.'],
                        ['title' => 'Cache & purge', 'body' => 'Assets are cached at the edge, invalidated on deploy, and purgeable by path.'],
                    ],
                ],
                [
                    'id' => 'operate',
                    'title' => 'Protection & insight',
                    'lede' => 'Who reaches the site, and what happened when they did.',
                    'items' => [
                        ['title' => 'Access rules', 'body' => 'Put a site or a path prefix behind a password or an org login — useful for staging and internal tools.'],
                        ['title' => 'Firewall, bots & rate limits', 'body' => 'Block by country, IP, ASN, or path; challenge automated traffic; cap requests per window.'],
                        ['title' => 'Traffic & logs', 'body' => 'Requests, bandwidth, status codes, cache ratio, and response times, plus a searchable live tail.'],
                        ['title' => 'Uptime & alerts', 'body' => 'Scheduled URL checks, with failed builds and error spikes routed to the channels you already watch.'],
                    ],
                ],
            ];

            // Stated plainly rather than buried — this is the boundary of an edge product.
            $notIncluded = [
                'Managed databases' => 'Bring your own — reach it over HTTPS from a Worker or your build.',
                'Long-running services' => 'Edge runs your frontend and its request-path logic, not a persistent process.',
                'Form handling' => 'Submissions are captured per site; routing and spam filtering are still in progress.',
            ];
        @endphp

        <section class="px-4 py-12 pb-20 sm:px-6 sm:py-16 lg:px-8">
            <div class="mx-auto max-w-5xl">
                <div class="min-w-0 overflow-hidden border border-edge-line bg-edge-void">
                    {{-- Hero --}}
                    <div class="border-b border-edge-line bg-edge-panel px-5 py-7 sm:px-8 sm:py-9">
                        <p class="text-xs font-semibold uppercase tracking-[0.16em] text-edge-lime">{{ config('app.name') }}</p>
                        <h1 class="mt-2 text-3xl font-bold tracking-tight text-edge-text sm:text-4xl">
                            {{ __('Ship a site, not a server.') }}
                        </h1>
                        <p class="mt-3 max-w-xl text-sm leading-relaxed text-edge-mute sm:text-base">
                            {{ __('Connect a repository. :app builds it, puts it on a global edge network, and gives every branch its own URL.', ['app' => config('app.name')]) }}
                        </p>
                        <div class="mt-5 inline-block border border-edge-line bg-edge-void px-4 py-3 font-mono text-xs text-edge-dim">
                            <div><span class="text-edge-lime">$</span> git push origin main</div>
                            <div class="mt-1 text-edge-lime">→ release 184 · live in 12s</div>
                        </div>
                    </div>

                    {{-- Sections --}}
                    <div class="divide-y divide-edge-line">
                        @foreach ($sections as $section)
                            <section id="{{ $section['id'] }}" class="scroll-mt-24">
                                <div class="bg-edge-panel px-5 py-3 sm:px-8">
                                    <h2 class="text-sm font-semibold tracking-tight text-edge-text">{{ __($section['title']) }}</h2>
                                    <p class="mt-0.5 text-xs text-edge-mute">{{ __($section['lede']) }}</p>
                                </div>
                                {{-- Plain two-column list, not 16 bordered cells with icon
                                     tiles. The icons were decorative — 'bolt' served both
                                     "Cached builds" and "Cache & purge", which is the tell —
                                     and a lime chip on every item made 16 equal claims
                                     shout equally. The grid lattice (a top rule and a left
                                     rule per cell) drew a box around each sentence for no
                                     gain: whitespace separates them just as well. --}}
                                <div class="grid gap-x-10 gap-y-5 px-5 py-6 sm:grid-cols-2 sm:px-8">
                                    @foreach ($section['items'] as $item)
                                        <div class="min-w-0">
                                            <h3 class="text-sm font-semibold text-edge-text">{{ __($item['title']) }}</h3>
                                            <p class="mt-1 text-sm leading-relaxed text-edge-mute">{{ __($item['body']) }}</p>
                                        </div>
                                    @endforeach
                                </div>
                            </section>
                        @endforeach

                        {{-- Platform, in one line each --}}
                        <section id="platform" class="scroll-mt-24">
                            <div class="bg-edge-panel px-5 py-3 sm:px-8">
                                <h2 class="text-sm font-semibold tracking-tight text-edge-text">{{ __('Teams, API & security') }}</h2>
                            </div>
                            <ul class="grid gap-x-8 gap-y-2.5 px-5 py-5 text-sm text-edge-mute sm:grid-cols-2 sm:px-8">
                                <li class="flex gap-2"><x-heroicon-m-check class="mt-0.5 h-4 w-4 shrink-0 text-edge-lime" aria-hidden="true" /> {{ __('Orgs, invitations, roles, and an audit trail') }}</li>
                                <li class="flex gap-2"><x-heroicon-m-check class="mt-0.5 h-4 w-4 shrink-0 text-edge-lime" aria-hidden="true" /> {{ __('Metered usage visible before the invoice') }}</li>
                                <li class="flex gap-2"><x-heroicon-m-check class="mt-0.5 h-4 w-4 shrink-0 text-edge-lime" aria-hidden="true" /> {{ __('Spend guardrails that stop runaway builds') }}</li>
                                <li class="flex gap-2"><x-heroicon-m-check class="mt-0.5 h-4 w-4 shrink-0 text-edge-lime" aria-hidden="true" /> {{ __('GitHub, GitLab & Bitbucket over OAuth') }}</li>
                                <li class="flex gap-2"><x-heroicon-m-check class="mt-0.5 h-4 w-4 shrink-0 text-edge-lime" aria-hidden="true" /> {{ __('HTTP API behind every action, plus a CLI') }}</li>
                                <li class="flex gap-2"><x-heroicon-m-check class="mt-0.5 h-4 w-4 shrink-0 text-edge-lime" aria-hidden="true" /> {{ __('Org-scoped tokens with granular abilities') }}</li>
                                <li class="flex gap-2"><x-heroicon-m-check class="mt-0.5 h-4 w-4 shrink-0 text-edge-lime" aria-hidden="true" /> {{ __('Two-factor auth and passkeys') }}</li>
                                <li class="flex gap-2"><x-heroicon-m-check class="mt-0.5 h-4 w-4 shrink-0 text-edge-lime" aria-hidden="true" /> {{ __('Secrets encrypted at rest, never shown again') }}</li>
                            </ul>
                        </section>

                        {{-- What it isn't --}}
                        <section class="bg-edge-panel/40">
                            <div class="px-5 py-5 sm:px-8">
                                <h2 class="text-sm font-semibold tracking-tight text-edge-text">{{ __("What it isn't") }}</h2>
                                <dl class="mt-3 space-y-2 text-sm">
                                    @foreach ($notIncluded as $term => $detail)
                                        <div class="sm:flex sm:gap-3">
                                            <dt class="shrink-0 font-medium text-edge-text sm:w-52">{{ __($term) }}</dt>
                                            <dd class="text-edge-mute">{{ __($detail) }}</dd>
                                        </div>
                                    @endforeach
                                </dl>
                            </div>
                        </section>
                    </div>
                </div>

                {{-- CTA --}}
                <div class="mt-8 flex flex-col items-center gap-4 text-center">
                    <div class="flex flex-col items-center gap-3 sm:flex-row">
                        @auth
                            <a href="{{ route('dashboard') }}" class="inline-flex w-full items-center justify-center border border-edge-line bg-edge-raise px-6 py-3 text-sm font-semibold text-edge-text transition-colors hover:border-edge-lime/40 hover:text-edge-lime sm:w-auto">{{ __('Go to dashboard') }}</a>
                        @else
                            <a href="{{ route('register') }}" class="inline-flex w-full items-center justify-center bg-edge-lime px-6 py-3 text-sm font-semibold text-edge-void transition-colors hover:bg-edge-lime-bright sm:w-auto">{{ __('Start free trial') }}</a>
                            <a href="{{ route('pricing') }}" class="inline-flex w-full items-center justify-center border border-edge-line bg-edge-raise px-6 py-3 text-sm font-semibold text-edge-text transition-colors hover:border-edge-lime/40 hover:text-edge-lime sm:w-auto">{{ __('View pricing') }}</a>
                        @endauth
                    </div>
                </div>
            </div>
        </section>
    </main>

    <x-edge-marketing-footer />
    @livewireScripts
</body>
</html>
