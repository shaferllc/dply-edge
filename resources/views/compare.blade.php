@php
    /*
     * /vs/{competitor}: one page per rival the ICP is leaving (product-marketing.md).
     * Claims about the other product stay to what docs/site/guides/migrate-from-forge-cloud.md
     * maps and what is public knowledge; each page says when the other product fits better.
     */
    $pages = [
        'forge' => [
            'name' => 'Laravel Forge',
            'title' => 'dply vs Laravel Forge: Laravel hosting without a server',
            'description' => 'Forge deploys to an always-on server you own. dply runs the same Laravel app in containers that scale and sleep, with managed databases and queue workers.',
            'intro' => 'Forge provisions and deploys to a server you own, which runs and bills around the clock. dply runs the same Laravel app as containers on a global edge network, with the database, Valkey and queue workers attached as managed resources. There is no server to size or patch, and an idle app sleeps.',
            'rows' => [
                ['Server + site', 'A container app, detected from the repo'],
                ['Deploy script', 'A generated image, or your own Dockerfile'],
                ['php artisan migrate in the deploy script', 'Migrations when a container starts, or on demand'],
                ['Daemons running queue:work or Horizon', 'Queue workers that autoscale on queue depth'],
                ['Scheduler cron', 'The Laravel scheduler, every minute'],
                ['MySQL or Postgres on the server', 'Managed Postgres, MySQL or MongoDB'],
                ['Redis on the server', 'dply Valkey'],
                ['Reverb daemon', 'Realtime (WebSockets for broadcasting)'],
                ['Server size', 'Instance size, sleep after idle, minimum instances'],
            ],
            'better' => [
                'You need SSH, a persistent disk, or software installed on the box itself.',
                'You want one fixed server bill no matter how much or little traffic arrives.',
            ],
            'guide' => ['guides/migrate-from-forge-cloud', 'Migrate from Forge or Laravel Cloud'],
        ],
        'laravel-cloud' => [
            'name' => 'Laravel Cloud',
            'title' => 'dply vs Laravel Cloud: Laravel, Rails & Node in one place',
            'description' => 'dply runs Laravel like Laravel Cloud does, plus Rails, Node and static or SSR frontends in one project, with managed databases and autoscaling queue workers.',
            'intro' => 'Laravel Cloud is a managed platform built for Laravel. dply runs Laravel the same way, as containers with managed databases, Valkey and queue workers, and it also runs Rails and Node apps and static or server-rendered frontends in the same project, on one bill.',
            'rows' => [
                ['App cluster', 'A container app, detected from the repo'],
                ['Build and deploy commands', 'A generated image, or your own Dockerfile'],
                ['Migrations in the deploy command', 'Migrations when a container starts, or on demand'],
                ['Queue clusters', 'Queue workers that autoscale on queue depth'],
                ['Scheduler toggle', 'The Laravel scheduler, every minute'],
                ['Cloud databases', 'Managed Postgres, MySQL or MongoDB'],
                ['Key-value store', 'dply Valkey'],
                ['Reverb / WebSockets', 'Realtime'],
                ['Compute size and hibernation', 'Instance size, sleep after idle, minimum instances'],
            ],
            'better' => [
                'Every app you run is Laravel and you want the platform made by the framework’s own team.',
            ],
            'guide' => ['guides/migrate-from-forge-cloud', 'Migrate from Forge or Laravel Cloud'],
        ],
        'heroku' => [
            'name' => 'Heroku',
            'title' => 'dply vs Heroku: git-push apps that sleep when idle',
            'description' => 'Git-push deploys like Heroku, but apps sleep when idle and bill by the second. Laravel and Rails need no Procfile; databases and queue workers are built in.',
            'intro' => 'Heroku made git-push deploys the default. dply keeps that and changes the bill: apps sleep when idle and bill by the second they run. Laravel, Rails and Node are detected without a Procfile, databases, Valkey and queue workers are built-in resources rather than add-ons, and static or SSR frontends run from the edge in the same project.',
            'rows' => [
                ['Dynos', 'Container instances, billed per second while awake'],
                ['Procfile web and worker processes', 'A detected app, plus queue workers'],
                ['Release phase', 'Migrations when a container starts, or on demand'],
                ['Heroku Postgres', 'Managed Postgres, MySQL or MongoDB'],
                ['Redis / Key-Value Store add-on', 'dply Valkey'],
                ['Review apps', 'A preview URL for every branch and pull request'],
                ['Config vars', 'The Environment tab, per production and preview'],
            ],
            'better' => [
                'Your stack depends on add-ons from the Heroku marketplace.',
                'Your team is built around Heroku Pipelines and promoting a slug between stages.',
            ],
            'guide' => ['guides/laravel', 'Deploy a Laravel app'],
        ],
    ];
    $c = $pages[$competitor];

    // Read by @head below, so this block sits above the document.
    \Laravel\Head\Facades\Head::title($c['title'], exact: true)
        ->description($c['description'])
        ->schema(\Laravel\Head\Facades\Schema::breadcrumbs()->items([
            'dply' => url('/'),
            'dply vs '.$c['name'] => route('compare', $competitor),
        ]));

    // The trade-offs every move to dply shares (the Forge guide's "Differences to plan for").
    $planFor = [
        'No SSH' => 'Containers have no shell. Use the logs and the database tools (Migrate, Seed, Connect).',
        'No persistent disk' => 'Files written at runtime are lost on restart. Put uploads in object storage.',
        'Cold starts' => 'An idle app sleeps after 5 minutes by default. Set a minimum instance to keep one warm.',
    ];
@endphp
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

    <x-edge-marketing-header />

    <main id="main-content" tabindex="-1">
        <section class="px-4 py-12 pb-20 sm:px-6 sm:py-16 lg:px-8">
            <div class="mx-auto max-w-5xl">
                <div class="min-w-0 overflow-hidden border border-edge-line bg-edge-void">
                    <div class="border-b border-edge-line bg-edge-panel px-5 py-7 sm:px-8 sm:py-9">
                        <p class="text-xs font-semibold uppercase tracking-[0.16em] text-edge-lime">{{ __('Compare') }}</p>
                        <h1 class="mt-2 text-3xl font-bold tracking-tight text-edge-text sm:text-4xl">dply vs {{ $c['name'] }}</h1>
                        <p class="mt-3 max-w-2xl text-sm leading-relaxed text-edge-mute sm:text-base">{{ __($c['intro']) }}</p>
                    </div>

                    <div class="divide-y divide-edge-line">
                        <section>
                            <div class="bg-edge-panel px-5 py-3 sm:px-8">
                                <h2 class="text-sm font-semibold tracking-tight text-edge-text">{{ __('How the pieces map') }}</h2>
                            </div>
                            <div class="overflow-x-auto px-5 py-4 sm:px-8">
                                <table class="w-full text-left text-sm">
                                    <thead>
                                        <tr class="text-edge-mute">
                                            <th scope="col" class="py-2 pr-6 font-medium">{{ $c['name'] }}</th>
                                            <th scope="col" class="py-2 font-medium">dply</th>
                                        </tr>
                                    </thead>
                                    <tbody class="divide-y divide-edge-line">
                                        @foreach ($c['rows'] as [$theirs, $ours])
                                            <tr>
                                                <td class="py-2.5 pr-6 align-top text-edge-mute">{{ __($theirs) }}</td>
                                                <td class="py-2.5 align-top text-edge-text">{{ __($ours) }}</td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>
                        </section>

                        <section>
                            <div class="bg-edge-panel px-5 py-3 sm:px-8">
                                <h2 class="text-sm font-semibold tracking-tight text-edge-text">{{ __('What to plan for on dply') }}</h2>
                            </div>
                            <dl class="space-y-2 px-5 py-5 text-sm sm:px-8">
                                @foreach ($planFor as $term => $detail)
                                    <div class="sm:flex sm:gap-3">
                                        <dt class="shrink-0 font-medium text-edge-text sm:w-44">{{ __($term) }}</dt>
                                        <dd class="text-edge-mute">{{ __($detail) }}</dd>
                                    </div>
                                @endforeach
                            </dl>
                        </section>

                        <section class="bg-edge-panel/40">
                            <div class="px-5 py-5 sm:px-8">
                                <h2 class="text-sm font-semibold tracking-tight text-edge-text">{{ __('When :name is the better fit', ['name' => $c['name']]) }}</h2>
                                <ul class="mt-3 space-y-2 text-sm text-edge-mute">
                                    @foreach ($c['better'] as $line)
                                        <li>{{ __($line) }}</li>
                                    @endforeach
                                </ul>
                            </div>
                        </section>
                    </div>
                </div>

                <div class="mt-8 flex flex-col items-center gap-4 text-center">
                    <div class="flex flex-col items-center gap-3 sm:flex-row">
                        <a href="{{ route('register') }}" class="inline-flex w-full items-center justify-center bg-edge-lime px-6 py-3 text-sm font-semibold text-edge-void transition-colors hover:bg-edge-lime-bright sm:w-auto">{{ __('Start a 5-day trial') }}</a>
                        <a href="{{ route('docs.show', $c['guide'][0]) }}" class="inline-flex w-full items-center justify-center border border-edge-line bg-edge-raise px-6 py-3 text-sm font-semibold text-edge-text transition-colors hover:border-edge-lime/40 hover:text-edge-lime sm:w-auto">{{ __($c['guide'][1]) }}</a>
                    </div>
                    <p class="text-sm text-edge-mute">
                        {{ __('Also compare:') }}
                        @foreach (array_diff_key($pages, [$competitor => true]) as $slug => $other)
                            <a href="{{ route('compare', $slug) }}" class="text-edge-text underline decoration-edge-line underline-offset-4 hover:text-edge-lime">dply vs {{ $other['name'] }}</a>@if (! $loop->last) · @endif
                        @endforeach
                    </p>
                </div>
            </div>
        </section>
    </main>

    <x-edge-marketing-footer />
    @livewireScripts
</body>
</html>
