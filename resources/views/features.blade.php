<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="dark">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    @head
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
             * Four sections, four items each, apps first (the ICP is Laravel/Rails).
             * The page used to run ten sections and
             * ~57 items, which read as a specification rather than a features page —
             * keep new entries to one line, and replace rather than append.
             */
            $sections = [
                [
                    'id' => 'apps',
                    'title' => 'Apps & workers',
                    'lede' => 'Laravel, Symfony, Rails and Node run as they are, in containers.',
                    'items' => [
                        ['title' => 'Detected from the repo', 'body' => 'dply reads the framework, runtime and build from your code and generates the image. Bring a Dockerfile when you want one.'],
                        ['title' => 'Scales out, sleeps when idle', 'body' => 'Instances follow traffic and sleep after a quiet spell. You pay for the seconds they run; keep one warm if you need to.'],
                        ['title' => 'Queue workers & scheduler', 'body' => 'queue:work processes that autoscale on queue depth, worker groups, failed jobs, and the Laravel scheduler every minute.'],
                        ['title' => 'Migrations on deploy', 'body' => 'Run migrations when a container starts, or on demand from the database sheet.'],
                    ],
                ],
                [
                    'id' => 'data',
                    'title' => 'Databases & data',
                    'lede' => 'Attach what the app needs; the credentials arrive as environment variables.',
                    'items' => [
                        ['title' => 'Postgres, MySQL & MongoDB', 'body' => 'Managed, in your app’s region, backed up continuously, and asleep when nothing is connected.'],
                        ['title' => 'Valkey', 'body' => 'A Redis-compatible store for cache, sessions and queues.'],
                        ['title' => 'Object storage & realtime', 'body' => 'Buckets for uploads, and WebSockets for Laravel broadcasting.'],
                        ['title' => 'Bring your data', 'body' => 'Upload a pg_dump or mysqldump and load it, or connect with the URL and restore it yourself.'],
                    ],
                ],
                [
                    'id' => 'deploy',
                    'title' => 'Deploys & previews',
                    'lede' => 'Push a branch. Get a URL.',
                    'items' => [
                        ['title' => 'A preview per branch', 'body' => 'Every branch and pull request builds to its own URL, posted back as a commit check and a PR comment.'],
                        ['title' => 'Instant rollback', 'body' => 'Releases are immutable and keep their own URL, so going back is a pointer change, not a rebuild.'],
                        ['title' => 'Static, SSG & SSR too', 'body' => 'Next.js, Nuxt, Astro, SvelteKit, Remix, Hugo and more, served from the edge under the same project.'],
                        ['title' => 'Environment per app', 'body' => 'Separate production and preview values, encrypted at rest, applied at build and runtime.'],
                    ],
                ],
                [
                    'id' => 'delivery',
                    'title' => 'Domains & protection',
                    'lede' => 'What happens on the request path, before your app sees it.',
                    'items' => [
                        ['title' => 'Custom domains & TLS', 'body' => 'Guided DNS, with certificates issued and renewed for you. Every app gets a permanent hostname on day one.'],
                        ['title' => 'Redirects, rewrites & middleware', 'body' => 'Declarative rules and your own code on the request path, evaluated at the edge.'],
                        ['title' => 'Firewall & access rules', 'body' => 'Block by country, IP or path, challenge bots, rate-limit, or put staging behind a login.'],
                        ['title' => 'Traffic, logs & uptime', 'body' => 'Requests, status codes and response times, a live tail of what the app prints, and URL checks with alerts.'],
                    ],
                ],
            ];

            // Stated plainly rather than buried: the trade-offs of containers that sleep.
            $notIncluded = [
                'A server to SSH into' => 'Containers start fresh and have no shell. Use the logs and the database tools instead.',
                'A persistent disk' => 'Files written at runtime are lost on restart. Put uploads in object storage.',
                'Zero cold starts by default' => 'An idle app sleeps and the next request waits for it to wake. Set a minimum instance to keep one warm.',
            ];
        @endphp

        <section class="px-4 py-12 pb-20 sm:px-6 sm:py-16 lg:px-8">
            <div class="mx-auto max-w-5xl">
                <div class="min-w-0 overflow-hidden border border-edge-line bg-edge-void">
                    {{-- Hero --}}
                    <div class="border-b border-edge-line bg-edge-panel px-5 py-7 sm:px-8 sm:py-9">
                        <p class="text-xs font-semibold uppercase tracking-[0.16em] text-edge-lime">dply</p>
                        <h1 class="mt-2 text-3xl font-bold tracking-tight text-edge-text sm:text-4xl">
                            {{ __('Your whole app, not just the frontend.') }}
                        </h1>
                        <p class="mt-3 max-w-xl text-sm leading-relaxed text-edge-mute sm:text-base">
                            {{ __('Connect a repository. dply detects Laravel, Rails or Node, builds it, runs it next to its database and queue workers, and gives every branch its own URL.') }}
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
