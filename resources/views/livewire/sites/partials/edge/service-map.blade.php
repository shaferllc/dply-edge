{{-- Overview service map: the app drawn the way a request travels, each box
     carrying its own live detail (a small chart, sleep window, backup age).
     Data: App\Support\Sites\EdgeServiceMap — stored config and samples only. --}}
@php
    use App\Support\Sites\EdgeServiceMap;

    $map = EdgeServiceMap::for($site);
    $link = fn (string $section) => route('sites.show', ['server' => $server, 'site' => $site, 'section' => $section]);
    $hasData = $map['database'] !== null || $map['stores'] !== [];
    $source = is_array($site->edgeMeta()['source'] ?? null) ? $site->edgeMeta()['source'] : [];
    $pollUrl = route('sites.edge.logs.live', ['server' => $server, 'site' => $site]);

    $node = 'group block rounded-2xl border border-brand-ink/15 bg-white p-3.5 transition hover:-translate-y-px hover:border-brand-ink/40 dark:border-brand-mist/20 dark:bg-zinc-900 dark:hover:border-brand-mist/50';
    $label = 'text-2xs font-semibold uppercase tracking-[0.16em] text-brand-mist';
    $pill = fn (string $text, string $tone) => '<span class="inline-flex items-center gap-1.5 rounded-full px-2 py-0.5 text-2xs font-semibold '.match ($tone) {
        'ok' => 'bg-emerald-500/10 text-emerald-700 dark:text-emerald-300',
        'sleep' => 'bg-violet-500/10 text-violet-700 dark:text-violet-300',
        'warn' => 'bg-amber-500/10 text-amber-700 dark:text-amber-300',
        default => 'bg-brand-sand/40 text-brand-moss',
    }.'"><span class="h-1.5 w-1.5 rounded-full bg-current'.($tone === 'ok' ? ' animate-pulse' : '').'"></span>'.e($text).'</span>';
    $mini = function (array $values, ?float $ceiling = null) {
        $line = EdgeServiceMap::path($values, 120, 28, ceiling: $ceiling);
        if ($line === null) {
            return '';
        }

        return '<svg viewBox="0 0 120 28" preserveAspectRatio="none" class="mt-2.5 block h-7 w-full" aria-hidden="true"><path d="'.EdgeServiceMap::path($values, 120, 28, true, $ceiling).'" class="fill-brand-forest/10"></path><path d="'.$line.'" fill="none" class="stroke-brand-forest" stroke-width="1.5" vector-effect="non-scaling-stroke"></path></svg>';
    };
    $hFlow = '<svg viewBox="0 0 40 100" preserveAspectRatio="none" class="hidden h-full min-h-10 w-full lg:block" aria-hidden="true"><path d="M0 50 H40" class="dply-flow stroke-brand-forest"></path></svg>';
    $vFlow = '<svg viewBox="0 0 20 28" class="mx-auto block h-7 w-5 lg:hidden" aria-hidden="true"><path d="M10 0 V28" class="dply-flow stroke-brand-forest"></path></svg>';
    $chart = EdgeServiceMap::path($map['requests'], 300, 110);
    $chartArea = EdgeServiceMap::path($map['requests'], 300, 110, true);
    $serving = $map['serving'];
@endphp

<section class="border-b border-brand-ink/10" aria-label="{{ __('Service map') }}">
    <div @class([
        'grid grid-cols-1 items-center bg-[radial-gradient(circle_at_1px_1px,rgb(23_26_14/0.12)_1px,transparent_0)] bg-[length:18px_18px] px-5 py-6 sm:px-6 lg:py-8 dark:bg-[radial-gradient(circle_at_1px_1px,rgb(232_236_227/0.10)_1px,transparent_0)]',
        'lg:grid-cols-[minmax(0,0.7fr)_40px_minmax(0,1.1fr)_40px_minmax(0,1.3fr)_48px_minmax(0,1.2fr)]' => $hasData,
        'lg:grid-cols-[minmax(0,0.7fr)_40px_minmax(0,1.2fr)_40px_minmax(0,1.3fr)]' => ! $hasData,
    ])>
        {{-- Visitors --}}
        <div class="text-center text-xs text-brand-moss">
            <div class="mx-auto mb-2 grid h-14 w-14 place-items-center rounded-full border border-brand-ink/15 bg-[repeating-radial-gradient(circle,transparent_0_8px,rgb(23_26_14/0.06)_8px_9px)] font-mono text-2xs text-brand-mist dark:border-brand-mist/20">
                {{ $map['placement']['region'] ?? '' }}
            </div>
            <span class="block font-mono text-lg font-bold tabular-nums text-brand-ink">{{ \Illuminate\Support\Number::abbreviate($map['requests30d'], maxPrecision: 1) }}</span>
            {{ __('requests, 30 days') }}
        </div>

        {!! $hFlow !!}{!! $vFlow !!}

        {{-- Edge --}}
        <a href="{{ $link('traffic') }}" wire:navigate class="{{ $node }}">
            <div class="flex items-center justify-between gap-2"><span class="{{ $label }}">{{ __('Edge') }}</span>{!! $edgeLiveUrl ? $pill(__('Routing'), 'ok') : $pill(__('Pending'), 'off') !!}</div>
            <p class="mt-1.5 break-all font-mono text-xs font-semibold text-brand-ink">{{ $edgeLiveUrl ? preg_replace('#^https?://#', '', $edgeLiveUrl) : __('No URL yet') }}</p>
            <div class="mt-2 flex flex-wrap gap-x-3 gap-y-0.5 font-mono text-2xs text-brand-mist">
                @if ($map['placement'])
                    @if ($map['placement']['rtt'] !== null)<span><b class="text-brand-ink">{{ $map['placement']['rtt'] }}</b> ms RTT</span>@endif
                    <span><b class="text-brand-ink">{{ $map['placement']['location'] }}</b></span>
                @endif
                <span><b class="text-brand-ink">{{ number_format($map['requestsToday']) }}</b> {{ __('req today') }}</span>
            </div>
            {!! $mini($map['requests']) !!}
        </a>

        {!! $hFlow !!}{!! $vFlow !!}

        {{-- Runtime --}}
        <div class="grid gap-3">
            @if ($map['container'])
                @php $c = $map['container']; @endphp
                <a href="{{ $link('container') }}" wire:navigate class="{{ $node }} border-brand-forest ring-4 ring-brand-forest/10 dark:border-brand-forest">
                    <div class="flex items-center justify-between gap-2"><span class="{{ $label }}">{{ __('Container') }}</span>{!! $edgeActiveDeploymentId ? $pill(__('Running'), 'ok') : $pill(__('Not deployed'), 'off') !!}</div>
                    <p class="mt-1.5 text-sm font-bold text-brand-ink">{{ $map['framework'] ?? __('App') }} · {{ $c['type'] }}</p>
                    <div class="mt-1 flex flex-wrap gap-x-3 gap-y-0.5 font-mono text-2xs text-brand-mist">
                        @if ($c['memMb'] !== null)<span><b class="text-brand-ink">{{ $c['memMb'] }}</b> MB {{ __('of') }} {{ rtrim(rtrim(number_format($c['memGib'], 2), '0'), '.') }} GiB</span>@endif
                        <span>{{ trans_choice('up to :count instance|up to :count instances', $c['maxInstances'], ['count' => $c['maxInstances']]) }}</span>
                    </div>
                    {!! $mini($c['memSeries'], $c['memGib'] * 1024) !!}
                    @if ($c['sleepAfter'] !== '')
                        <p class="mt-2 flex items-center gap-1.5 text-2xs text-brand-moss"><x-heroicon-o-moon class="h-3.5 w-3.5 text-violet-500" aria-hidden="true" />{{ __('Sleeps after :time idle', ['time' => EdgeServiceMap::duration($c['sleepAfter'])]) }}</p>
                    @endif
                </a>
                @if ($map['workers'])
                    @php $w = $map['workers']; @endphp
                    <a href="{{ $link('resources') }}" wire:navigate class="{{ $node }}">
                        <div class="flex items-center justify-between gap-2"><span class="{{ $label }}">{{ __('Queue workers') }}</span>{!! $w['paused'] ? $pill(__('Paused'), 'warn') : $pill(__('Running'), 'ok') !!}</div>
                        <p class="mt-1.5 text-sm font-bold text-brand-ink">{{ trans_choice(':count process|:count processes', $w['processes'], ['count' => $w['processes']]) }} · <span class="font-mono font-normal text-brand-moss">{{ $w['queues'] }}</span></p>
                        <p class="mt-1 text-xs text-brand-moss">
                            {{ $w['autoscale'] ? __('Autoscales to :count instances', ['count' => $w['maxInstances']]) : __('Fixed size') }}
                            @unless ($w['scheduler']) · <span class="text-amber-700 dark:text-amber-300">{{ __('scheduler off') }}</span>@endunless
                        </p>
                    </a>
                @endif
            @else
                <a href="{{ $link('build') }}" wire:navigate class="{{ $node }} border-brand-forest ring-4 ring-brand-forest/10 dark:border-brand-forest">
                    <div class="flex items-center justify-between gap-2">
                        <span class="{{ $label }}">{{ $map['runtime'] === 'static' ? __('Static assets') : __('Worker') }}</span>
                        {!! $edgeActiveDeploymentId ? $pill(__('Serving'), 'ok') : $pill(__('Not deployed'), 'off') !!}
                    </div>
                    <p class="mt-1.5 text-sm font-bold text-brand-ink">{{ $map['framework'] ?? __('Auto-detected') }}</p>
                    <p class="mt-1 text-xs text-brand-moss">{{ ['hybrid' => __('Static assets plus server routes on Workers'), 'ssr' => __('Server-rendered on Workers')][$map['runtime']] ?? __('Files served from the edge cache') }}</p>
                </a>
            @endif
        </div>

        @if ($hasData)
            <svg viewBox="0 0 48 200" preserveAspectRatio="none" class="hidden h-full min-h-24 w-full lg:block" aria-hidden="true">
                <path d="M0 100 C24 100 24 50 48 50" class="dply-flow stroke-brand-forest"></path>
                @if ($map['database'] && $map['stores'] !== [])
                    <path d="M0 100 C24 100 24 150 48 150" class="dply-flow stroke-brand-mist"></path>
                @endif
            </svg>
            {!! $vFlow !!}

            {{-- Data --}}
            <div class="grid gap-3">
                @if ($map['database'])
                    @php $d = $map['database']; @endphp
                    <a href="{{ $link('resources') }}" wire:navigate class="{{ $node }}">
                        <div class="flex items-center justify-between gap-2"><span class="{{ $label }}">{{ $d['engine'] }}</span>{!! $pill($d['status'] === 'ready' ? __('Ready') : (string) str($d['status'] ?: __('Unknown'))->headline(), $d['status'] === 'ready' ? 'ok' : 'warn') !!}</div>
                        <p class="mt-1.5 text-sm font-bold text-brand-ink">{{ $d['name'] ?: __('Database') }}</p>
                        <p class="mt-0.5 text-xs text-brand-moss">
                            {{ collect([$d['plan'] !== '' ? __(':plan plan', ['plan' => ucfirst($d['plan'])]) : null, $d['diskGb'] ? $d['diskGb'].' GB '.__('disk') : null])->filter()->implode(' · ') }}
                        </p>
                        <div class="mt-2 flex flex-wrap gap-x-3 gap-y-0.5 font-mono text-2xs text-brand-mist">
                            <span>{{ __('backup') }} <b class="text-brand-ink">{{ $d['backupAt']?->diffForHumans(short: true) ?? __('none') }}</b></span>
                            @if ($d['logAt'])<span>PITR <b class="text-brand-ink">{{ $d['logAt']->diffForHumans(short: true) }}</b></span>@endif
                        </div>
                        @if ($d['suspend'])
                            <p class="mt-2 flex items-center gap-1.5 text-2xs text-brand-moss"><x-heroicon-o-moon class="h-3.5 w-3.5 text-violet-500" aria-hidden="true" />{{ __('Suspends after :time idle', ['time' => EdgeServiceMap::duration(intdiv($d['suspend'], 60).'m')]) }}</p>
                        @endif
                    </a>
                @endif
                @foreach ($map['stores'] as $store)
                    <a href="{{ $link('resources') }}" wire:navigate class="{{ $node }}">
                        <div class="flex items-center justify-between gap-2"><span class="{{ $label }}">{{ __('Valkey') }}</span>{!! $store['asleep'] ? $pill(__('Asleep'), 'sleep') : $pill(__('Awake'), 'ok') !!}</div>
                        <p class="mt-1.5 font-mono text-sm font-bold text-brand-ink">{{ $store['name'] }}</p>
                        <p class="mt-0.5 text-xs text-brand-moss">{{ $store['plan'] }}</p>
                        @if ($store['sleepAfter'])
                            <p class="mt-2 flex items-center gap-1.5 text-2xs text-brand-moss"><x-heroicon-o-moon class="h-3.5 w-3.5 text-violet-500" aria-hidden="true" />{{ $store['asleep'] ? __('Wakes on the first command') : __('Sleeps after :time idle', ['time' => EdgeServiceMap::duration(intdiv($store['sleepAfter'], 60).'m')]) }}</p>
                        @endif
                    </a>
                @endforeach
            </div>
        @endif
    </div>

    <div class="grid border-t border-brand-ink/10 lg:grid-cols-[minmax(0,1.3fr)_minmax(0,1fr)] dark:border-brand-mist/15">
        <div class="px-5 py-5 sm:px-6">
            <p class="{{ $label }}">{{ __('Requests per day') }}</p>
            <p class="mt-1 font-mono text-2xl font-bold tabular-nums text-brand-ink">
                {{ number_format($map['requests30d']) }}
                <span class="text-xs font-normal text-brand-mist">{{ __('last 30 days · :today today', ['today' => number_format($map['requestsToday'])]) }}</span>
            </p>
            @if ($chart)
                <svg viewBox="0 0 300 110" preserveAspectRatio="none" class="mt-3 block h-28 w-full" role="img" aria-label="{{ __('Requests per day, last 30 days') }}">
                    <line x1="0" y1="109" x2="300" y2="109" class="stroke-brand-ink/15" vector-effect="non-scaling-stroke"></line>
                    <line x1="0" y1="55" x2="300" y2="55" class="stroke-brand-ink/10" stroke-dasharray="3 3" vector-effect="non-scaling-stroke"></line>
                    <path d="{{ $chartArea }}" class="fill-brand-forest/10"></path>
                    <path d="{{ $chart }}" fill="none" class="stroke-brand-forest" stroke-width="1.5" vector-effect="non-scaling-stroke" stroke-linejoin="round"></path>
                </svg>
                <div class="mt-1 flex justify-between font-mono text-2xs text-brand-mist"><span>{{ now()->subDays(29)->format('M j') }}</span><span>{{ __('today') }}</span></div>
            @else
                <p class="mt-3 flex h-28 items-center justify-center rounded-xl border border-dashed border-brand-ink/15 text-xs text-brand-moss">{{ __('No traffic recorded in the last 30 days.') }}</p>
            @endif
        </div>

        <div class="border-t border-brand-ink/10 px-5 py-5 sm:px-6 lg:border-s lg:border-t-0 dark:border-brand-mist/15" wire:ignore>
            <div class="flex items-center justify-between gap-2">
                <p class="{{ $label }}">{{ __('Live requests') }}</p>
                <a href="{{ $link('logs') }}" wire:navigate class="text-xs font-semibold text-brand-sage hover:underline">{{ __('Open logs') }}</a>
            </div>
            <div
                class="mt-2 overflow-x-auto font-mono text-2xs leading-6"
                x-data="{
                    rows: [], loaded: false, failed: false, timer: null,
                    async tick() {
                        if (document.hidden) return;
                        try {
                            const url = new URL(@js($pollUrl));
                            url.searchParams.set('limit', '8');
                            const res = await fetch(url, { headers: { Accept: 'application/json' }, credentials: 'same-origin' });
                            if (! res.ok) throw new Error(res.status);
                            this.rows = ((await res.json()).data || []).slice(0, 8);
                            this.failed = false;
                        } catch (e) { this.failed = true; }
                        this.loaded = true;
                    },
                    init() { this.tick(); this.timer = setInterval(() => this.tick(), 5000); },
                    destroy() { clearInterval(this.timer); },
                }"
            >
                <template x-for="row in rows" :key="row.occurred_at + row.path + row.status">
                    <div class="flex gap-3 whitespace-nowrap">
                        <span class="text-brand-mist" x-text="new Date(row.occurred_at).toLocaleTimeString([], { hour12: false })"></span>
                        <span class="w-9 font-bold text-brand-forest" x-text="row.method"></span>
                        <span class="min-w-0 flex-1 truncate text-brand-ink" x-text="row.path"></span>
                        <span :class="row.status >= 500 ? 'text-rose-600 dark:text-rose-300' : (row.status >= 400 ? 'text-amber-700 dark:text-amber-300' : 'text-brand-moss')" x-text="row.status"></span>
                        <span class="w-12 text-right text-brand-mist" x-text="row.duration_ms != null ? row.duration_ms + 'ms' : ''"></span>
                    </div>
                </template>
                <p x-show="loaded && ! rows.length && ! failed" x-cloak class="py-6 text-center font-sans text-xs text-brand-moss">{{ __('No requests in the last hour. New ones appear here as they arrive.') }}</p>
                <p x-show="failed && ! rows.length" x-cloak class="py-6 text-center font-sans text-xs text-brand-moss">{{ __('Live requests are unavailable right now.') }}</p>
                <p x-show="! loaded" class="py-6 text-center font-sans text-xs text-brand-mist">{{ __('Loading…') }}</p>
            </div>
        </div>
    </div>

    @php $latest = ($edgeDeployments ?? collect())->first(); @endphp
    @if ($latest && $latest->status === \App\Models\EdgeDeployment::STATUS_FAILED)
        {{-- The newest deploy failed; the strip below still shows what is serving. --}}
        <a href="{{ route('sites.edge.deployments.show', ['server' => $server, 'site' => $site, 'deployment' => $latest]) }}" wire:navigate class="flex flex-wrap items-center gap-x-3 gap-y-1 border-t border-rose-200 bg-rose-50/70 px-5 py-3 text-sm hover:bg-rose-50 sm:px-6 dark:border-rose-900/60 dark:bg-rose-950/30">
            <span class="font-semibold text-rose-800 dark:text-rose-300">{{ __('Latest deploy failed :when', ['when' => ($latest->failed_at ?? $latest->created_at)?->diffForHumans()]) }}</span>
            @if (filled($latest->failure_reason))
                <span class="min-w-0 flex-1 truncate font-mono text-xs text-rose-700 dark:text-rose-300">{{ $latest->failure_reason }}</span>
            @endif
            <span class="text-xs font-semibold text-rose-800 dark:text-rose-300">{{ __('View build →') }}</span>
        </a>
    @endif

    @if ($serving)
        @php
            $subject = (string) ($serving->meta['commit']['subject'] ?? '');
            $author = (string) ($serving->meta['commit']['author'] ?? '');
            $seconds = (int) $serving->build_seconds;
            $when = $serving->published_at ?? $serving->created_at;
        @endphp
        <div class="flex flex-wrap items-center justify-between gap-x-6 gap-y-2 border-t border-brand-ink/10 px-5 py-3.5 text-sm sm:px-6 dark:border-brand-mist/15">
            <div class="flex min-w-0 flex-wrap items-center gap-x-2.5 gap-y-1">
                {!! $pill(__('Serving'), 'ok') !!}
                <a href="{{ route('sites.edge.deployments.show', ['server' => $server, 'site' => $site, 'deployment' => $serving]) }}" wire:navigate class="font-semibold text-brand-ink hover:underline">{{ $subject !== '' ? $subject : __('Deploy') }}</a>
                @if ($serving->git_commit)<span class="font-mono text-xs text-brand-mist">{{ substr((string) $serving->git_commit, 0, 7) }}</span>@endif
                <span class="text-xs text-brand-moss">
                    {{ collect([
                        $serving->git_branch,
                        $author !== '' ? $author : null,
                        $seconds > 0 ? ($seconds >= 60 ? intdiv($seconds, 60).'m '.($seconds % 60).'s' : $seconds.'s') : null,
                        $when?->diffForHumans(),
                    ])->filter()->implode(' · ') }}
                </span>
                @if ($map['sameCommitRedeploys'] > 0)
                    <span class="rounded-md bg-brand-sand/40 px-2 py-0.5 text-2xs font-semibold text-brand-moss">{{ trans_choice('+:count redeploy of this commit|+:count redeploys of this commit', $map['sameCommitRedeploys'], ['count' => $map['sameCommitRedeploys']]) }}</span>
                @endif
            </div>
            <div class="flex items-center gap-4 text-xs">
                @if (filled($source['branch'] ?? null))
                    <a href="{{ $link('deploy-triggers') }}" wire:navigate class="text-brand-moss hover:text-brand-ink">{{ ($source['deploy_on_push'] ?? false) ? __(':branch deploys on push', ['branch' => $source['branch']]) : __(':branch · manual deploys', ['branch' => $source['branch']]) }}</a>
                @endif
                <a href="{{ $link('deploys') }}" wire:navigate class="font-semibold text-brand-sage hover:underline">{{ __('All deploys') }}</a>
            </div>
        </div>
    @endif
</section>
