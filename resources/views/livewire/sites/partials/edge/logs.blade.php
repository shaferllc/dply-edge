@php
    use App\Livewire\Sites\Edge\Workspace\Logs;
    use App\Models\EdgeDeployment;
    use Illuminate\Support\Str;

    $deploys = $edgeDeployments;
    $hasBuilding = $deploys->contains(fn ($d) => in_array($d->status, [EdgeDeployment::STATUS_BUILDING, EdgeDeployment::STATUS_PUBLISHING], true));
    $sha = fn ($d) => $d->git_commit ? Str::limit($d->git_commit, 7, '') : Str::limit((string) $d->id, 8, '');
    $subject = fn ($d) => is_string($d->meta['commit']['subject'] ?? null) ? $d->meta['commit']['subject'] : null;
    $took = function ($d): ?string {
        $s = (int) ($d->build_seconds ?? 0);

        return $s > 0 ? ($s < 60 ? $s.'s' : intdiv($s, 60).'m '.str_pad((string) ($s % 60), 2, '0', STR_PAD_LEFT).'s') : null;
    };
    $reason = fn ($d) => is_string($d->failure_reason) && $d->failure_reason !== '' ? Str::limit(trim(strtok($d->failure_reason, "\n")), 90) : null;
    $isProd = fn ($d) => ($edgeActiveDeploymentId ?? null) === $d->id;
    $say = function ($d) use ($sha, $isProd): array {
        return match (true) {
            in_array($d->status, [EdgeDeployment::STATUS_BUILDING, EdgeDeployment::STATUS_PUBLISHING], true) => [__(':sha is building', ['sha' => $sha($d)]), __('Building'), 'warn'],
            $d->status === EdgeDeployment::STATUS_FAILED => [__(':sha failed', ['sha' => $sha($d)]), __('Failed'), 'bad'],
            $isProd($d) => [__(':sha is live', ['sha' => $sha($d)]), __('Live'), 'ok'],
            $d->status === EdgeDeployment::STATUS_LIVE => [__(':sha went live', ['sha' => $sha($d)]), __('Live'), 'ok'],
            default => [__(':sha was replaced', ['sha' => $sha($d)]), __('Replaced'), 'muted'],
        };
    };
    $tone = fn (string $t) => match ($t) {
        'ok' => 'text-brand-sage',
        'bad' => 'text-rose-600 dark:text-rose-300',
        'warn' => 'text-amber-600 dark:text-amber-300',
        default => 'text-brand-moss',
    };
    $latest = $deploys->first();
    $previous = $deploys->skip(1)->first();
    $appErrors = $appLogs === null ? [] : array_values(array_filter($appLogs, fn ($l) => Logs::isErrorLine($l)));
    $row = 'flex min-h-12 w-full items-center gap-3 border-b border-brand-ink/10 py-3 text-left hover:bg-brand-sand/20';
    $deploysUrl = route('sites.show', ['server' => $server ?? $site->server, 'site' => $site, 'section' => 'deploys']);
    $trafficUrl = route('sites.show', ['server' => $server ?? $site->server, 'site' => $site, 'section' => 'traffic']);
@endphp

<div @if ($isContainer) wire:init="loadAppLogs" @endif>
    <section class="space-y-8 px-5 py-8 sm:px-10 sm:py-10" @if ($hasBuilding) wire:poll.5s="refreshEdgeLogDeployments" @endif>
        <div>
            <p class="text-2xs font-semibold uppercase tracking-[0.16em] text-brand-mist">{{ __('Logs') }}</p>
            <p class="mt-3 max-w-3xl text-2xl font-medium leading-snug tracking-tight text-brand-ink sm:text-3xl">
                @if ($latest === null)
                    {{ __('Nothing has been deployed yet. Build output shows up here with the first deploy.') }}
                @elseif (in_array($latest->status, [EdgeDeployment::STATUS_BUILDING, EdgeDeployment::STATUS_PUBLISHING], true))
                    {{ __('A deploy of') }} <span class="font-mono text-brand-sage">{{ $sha($latest) }}</span> {{ __('is building now.') }}
                @elseif ($latest->status === EdgeDeployment::STATUS_FAILED)
                    {{ __('The last deploy,') }} <span class="font-mono text-rose-600 dark:text-rose-300">{{ $sha($latest) }}</span>, <span class="text-rose-600 dark:text-rose-300">{{ __('failed') }}</span> {{ $latest->created_at?->diffForHumans() }}.
                    {{ __('Production stays on the version before it.') }}
                @else
                    {{ __('The last deploy,') }} <span class="font-mono text-brand-sage">{{ $sha($latest) }}</span>,
                    @if ($took($latest)) {{ __('built in :t and', ['t' => $took($latest)]) }} @endif
                    {{ __('went live :when.', ['when' => ($latest->published_at ?? $latest->created_at)?->diffForHumans()]) }}
                    @if ($previous && $previous->status === EdgeDeployment::STATUS_FAILED)
                        <span class="text-rose-600 dark:text-rose-300">{{ __('The one before it failed.') }}</span>
                    @endif
                @endif
                @if ($isContainer)
                    @if ($appLogs === null)
                        <span class="inline-block h-6 w-56 translate-y-1 rounded-md bg-brand-ink/10 align-baseline motion-safe:animate-pulse" aria-label="{{ __('Loading app output…') }}"></span>
                    @elseif ($appLogsError === null)
                        @if ($appErrors !== [])
                            {{ __('Your app logged') }} <span class="text-amber-600 dark:text-amber-300">{{ trans_choice(':count error|:count errors', count($appErrors)) }}</span> {{ __('in the last 15 minutes.') }}
                        @else
                            {{ __('Your app logged no errors in the last 15 minutes.') }}
                        @endif
                    @endif
                @endif
            </p>
            <p class="mt-3 text-xs text-brand-moss">{{ __('Build and app output — not visitor HTTP logs.') }}</p>
        </div>

        @if ($isContainer)
            <div>
                <p class="border-b border-brand-ink/10 pb-2 text-sm font-semibold text-brand-ink">{{ __('What your app is printing') }}</p>
                @if ($appLogsError !== null)
                    <div class="border-b border-brand-ink/10 py-3"><x-sheet.note tone="danger">{{ __('Could not load app output: :error', ['error' => $appLogsError]) }}</x-sheet.note></div>
                @else
                    <button type="button" wire:click="openAppLogs('errors')" class="{{ $row }}" @disabled($appLogs === null || $appErrors === [])>
                        <span class="min-w-0 flex-1 text-sm text-brand-ink sm:text-base">
                            @if ($appLogs === null)
                                <span class="inline-block h-4 w-64 rounded bg-brand-ink/10 align-middle motion-safe:animate-pulse"></span>
                            @elseif ($appErrors === [])
                                {{ __('No errors in the last 15 minutes') }}
                            @else
                                {{ trans_choice(':count error in the last 15 minutes, most recent:|:count errors in the last 15 minutes, most recent:', count($appErrors)) }}
                                <span class="font-mono text-sm">{{ Str::limit((string) end($appErrors)['message'], 70) }}</span>
                            @endif
                        </span>
                        <span @class(['shrink-0 font-mono text-xs', 'text-amber-600 dark:text-amber-300' => $appErrors !== [], 'text-brand-moss' => $appErrors === []])>{{ $appLogs === null ? '' : count($appErrors) }}</span>
                        @if ($appErrors !== [])
                            <x-heroicon-m-chevron-right class="h-4 w-4 shrink-0 text-brand-mist" aria-hidden="true" />
                        @endif
                    </button>
                    <button type="button" wire:click="openAppLogs('all')" class="{{ $row }}" @disabled($appLogs === null)>
                        <span class="flex-1 text-sm text-brand-ink sm:text-base">{{ __('Everything it printed in the last 15 minutes') }}</span>
                        <span class="shrink-0 font-mono text-xs text-brand-moss">{{ $appLogs === null ? '' : trans_choice(':count line|:count lines', count($appLogs)) }}</span>
                        <x-heroicon-m-chevron-right class="h-4 w-4 shrink-0 text-brand-mist" aria-hidden="true" />
                    </button>
                @endif
            </div>
        @endif

        <div>
            <p class="border-b border-brand-ink/10 pb-2 text-sm font-semibold text-brand-ink">{{ __('Recent deploys') }}</p>
            @forelse ($deploys as $d)
                @php [$sentence, $state, $t] = $say($d); @endphp
                <button type="button" wire:click="openDeploy(@js($d->id))" class="{{ $row }}" wire:key="log-row-{{ $d->id }}">
                    <span class="min-w-0 flex-1 text-sm text-brand-ink sm:text-base">
                        <span class="font-mono">{{ $sentence }}</span>@if ($subject($d)) <span class="text-brand-moss">· {{ Str::limit($subject($d), 60) }}</span>@endif
                        @if ($d->status === EdgeDeployment::STATUS_FAILED && $reason($d))
                            <span class="mt-0.5 block truncate font-mono text-xs text-rose-600 dark:text-rose-300">{{ $reason($d) }}</span>
                        @endif
                    </span>
                    <span class="shrink-0 text-xs {{ $tone($t) }}">{{ $state }} · {{ $d->created_at?->diffForHumans(short: true) }}</span>
                    <x-heroicon-m-chevron-right class="h-4 w-4 shrink-0 text-brand-mist" aria-hidden="true" />
                </button>
            @empty
                <p class="border-b border-brand-ink/10 py-4 text-sm text-brand-moss">{{ __('No deploys yet. Start one from Overview.') }}</p>
            @endforelse
            <a href="{{ $deploysUrl }}" wire:navigate class="{{ $row }}">
                <span class="flex-1 text-sm text-brand-ink sm:text-base">{{ __('See every deploy') }}</span>
                <x-heroicon-m-chevron-right class="h-4 w-4 shrink-0 text-brand-mist" aria-hidden="true" />
            </a>
            <a href="{{ $trafficUrl }}" wire:navigate class="{{ $row }}">
                <span class="flex-1 text-sm text-brand-ink sm:text-base">{{ __('Requests visitors made are under Traffic') }}</span>
                <x-heroicon-m-chevron-right class="h-4 w-4 shrink-0 text-brand-mist" aria-hidden="true" />
            </a>
        </div>
    </section>

    {{-- One deploy's build log --}}
    <x-modal name="deploy-log" maxWidth="5xl" overlayClass="bg-brand-ink/40" focusable>
        @if ($openDeploy)
            @php
                [$sentence, $state, $t] = $say($openDeploy);
                $stillBuilding = in_array($openDeploy->status, [EdgeDeployment::STATUS_BUILDING, EdgeDeployment::STATUS_PUBLISHING], true);
                $failure = is_string($openDeploy->failure_reason) && $openDeploy->failure_reason !== '' ? $openDeploy->failure_reason : null;
                $detailUrl = route('sites.edge.deployments.show', ['server' => $server ?? $site->server, 'site' => $site, 'deployment' => $openDeploy]);
            @endphp
            <div
                class="flex max-h-[85vh] flex-col gap-4 p-6 sm:p-7"
                @if ($stillBuilding) wire:poll.5s="refreshOpenDeployment" @endif
                x-data="{
                    q: '',
                    download() {
                        const text = this.$refs.log?.innerText ?? '';
                        const a = document.createElement('a');
                        a.href = URL.createObjectURL(new Blob([text], { type: 'text/plain' }));
                        a.download = @js('build-'.$sha($openDeploy).'.log');
                        a.click();
                        URL.revokeObjectURL(a.href);
                    },
                    jump() {
                        const el = [...(this.$refs.log?.querySelectorAll('[data-line]') ?? [])].find((l) => /error|failed|exited with code [1-9]/i.test(l.textContent));
                        el?.scrollIntoView({ block: 'center' });
                        el?.classList.add('bg-rose-500/10');
                    },
                }"
            >
                <div class="flex items-start justify-between gap-4">
                    <div class="min-w-0">
                        <h2 class="text-lg font-semibold {{ $tone($t) }}"><span class="font-mono">{{ $sentence }}</span></h2>
                        <p class="mt-0.5 text-sm text-brand-moss">
                            {{ collect([
                                $subject($openDeploy),
                                $openDeploy->git_branch,
                                $openDeploy->created_at?->diffForHumans(),
                                $took($openDeploy) ? __('built in :t', ['t' => $took($openDeploy)]) : null,
                            ])->filter()->implode(' · ') }}
                            · <a href="{{ $detailUrl }}" wire:navigate class="font-medium text-brand-sage hover:underline">{{ __('Deploy details') }}</a>
                        </p>
                    </div>
                    <button type="button" x-on:click="$dispatch('close-modal', 'deploy-log')" class="dply-icon-btn h-9 w-9 shrink-0" aria-label="{{ __('Close') }}">
                        <x-heroicon-o-x-mark class="h-5 w-5" aria-hidden="true" />
                    </button>
                </div>

                @if ($failure)
                    @include('livewire.sites.partials.edge.build-log-lint-callout', [
                        'buildLog' => $openLog,
                        'failureReason' => $failure,
                        'site' => $site,
                        'server' => $server ?? $site->server,
                        'deployment' => $openDeploy,
                    ])
                    @unless (str_contains($failure, 'dply config lint failed'))
                        <pre class="max-h-32 overflow-auto rounded-lg border border-rose-200/60 bg-rose-50/50 p-2.5 font-mono text-xs whitespace-pre-wrap text-rose-900 dark:border-raw-rose-900/30 dark:bg-rose-950/20 dark:text-raw-rose-200">{{ $failure }}</pre>
                    @endunless
                @endif

                <div class="flex flex-wrap items-center gap-2">
                    <input type="search" x-model="q" placeholder="{{ __('Find in log') }}" aria-label="{{ __('Find in log') }}" class="dply-input mt-0 min-w-0 flex-1 text-xs" />
                    @if ($failure)
                        <x-sheet.button type="button" x-on:click="jump()">{{ __('Jump to error') }}</x-sheet.button>
                    @endif
                    <x-sheet.button type="button" x-on:click="download()">{{ __('Download') }}</x-sheet.button>
                </div>

                <div x-ref="log" class="min-h-40 flex-1 overflow-auto rounded-lg bg-zinc-950 p-3 font-mono text-xs leading-relaxed text-raw-zinc-200">
                    @if ($openLog === null || $openLog === '')
                        <p class="text-zinc-400">{{ $stillBuilding ? __('Waiting for build output…') : __('No build log stored for this deploy.') }}</p>
                    @else
                        @foreach (preg_split('/\r?\n/', (string) $openLog) as $i => $line)
                            <div data-line x-show="q === '' || $el.textContent.toLowerCase().includes(q.toLowerCase())" class="whitespace-pre-wrap break-words">{!! \App\Modules\Edge\Support\AnsiHtml::toHtml($line) !!}&#8203;</div>
                        @endforeach
                    @endif
                </div>
            </div>
        @endif
    </x-modal>

    {{-- The app's recent output --}}
    @if ($isContainer)
        <x-modal name="app-logs" maxWidth="5xl" overlayClass="bg-brand-ink/40" focusable>
            @php
                $sources = ['app' => __('App'), 'workers' => __('Queue workers'), 'routing' => __('Routing')];
                $sourceCounts = array_count_values(array_column($appLogs ?? [], 'source'));
                $workerNames = collect(array_column($appLogs ?? [], 'worker'))->filter()->unique()->sort()->values()->all();
            @endphp
            <div class="flex max-h-[85vh] flex-col gap-4 p-6 sm:p-7" x-data="{ only: @entangle('appFilter'), q: '', source: 'all', worker: '' }">
                <div class="flex items-start justify-between gap-4">
                    <div>
                        <h2 class="text-lg font-semibold text-brand-ink">{{ __('What your app printed') }}</h2>
                        <p class="mt-0.5 text-sm text-brand-moss">{{ __('Last 15 minutes: your app, queue workers, and the router in front.') }}</p>
                    </div>
                    <button type="button" x-on:click="$dispatch('close-modal', 'app-logs')" class="dply-icon-btn h-9 w-9 shrink-0" aria-label="{{ __('Close') }}">
                        <x-heroicon-o-x-mark class="h-5 w-5" aria-hidden="true" />
                    </button>
                </div>
                <div class="flex flex-wrap items-center gap-2 text-xs">
                    <button type="button" x-on:click="only = 'all'" :class="only === 'all' ? 'bg-brand-ink text-white' : 'border border-brand-ink/15 text-brand-moss'" class="rounded-full px-3 py-1">{{ __('Everything') }}</button>
                    <button type="button" x-on:click="only = 'errors'" :class="only === 'errors' ? 'bg-brand-ink text-white' : 'border border-brand-ink/15 text-brand-moss'" class="rounded-full px-3 py-1">{{ trans_choice('Errors (:count)|Errors (:count)', count($appErrors)) }}</button>
                    <span class="mx-1 h-4 w-px bg-brand-ink/15" aria-hidden="true"></span>
                    <button type="button" x-on:click="source = 'all'; worker = ''" :class="source === 'all' ? 'bg-brand-ink text-white' : 'border border-brand-ink/15 text-brand-moss'" class="rounded-full px-3 py-1">{{ __('All sources') }}</button>
                    @foreach ($sources as $key => $label)
                        @if (($sourceCounts[$key] ?? 0) > 0)
                            <button type="button" x-on:click="source = '{{ $key }}'; worker = ''" :class="source === '{{ $key }}' ? 'bg-brand-ink text-white' : 'border border-brand-ink/15 text-brand-moss'" class="rounded-full px-3 py-1">{{ $label }} <span class="tabular-nums opacity-70">{{ $sourceCounts[$key] }}</span></button>
                        @endif
                    @endforeach
                    @if (count($workerNames) > 1)
                        <select x-model="worker" x-on:change="if (worker) source = 'workers'" class="rounded-full border-brand-ink/15 py-0.5 pl-2.5 pr-7 text-xs text-brand-moss" aria-label="{{ __('Queue worker') }}">
                            <option value="">{{ __('Every worker') }}</option>
                            @foreach ($workerNames as $name)
                                <option value="{{ $name }}">{{ $name }}</option>
                            @endforeach
                        </select>
                    @endif
                    <input type="search" x-model="q" placeholder="{{ __('Find') }}" aria-label="{{ __('Find in output') }}" class="dply-input mt-0 min-w-0 flex-1 text-xs" />
                    <x-sheet.button type="button" wire:click="loadAppLogs" wire:loading.attr="disabled" wire:target="loadAppLogs">
                        <span wire:loading.remove wire:target="loadAppLogs">{{ __('Refresh') }}</span>
                        <span wire:loading wire:target="loadAppLogs">{{ __('Loading…') }}</span>
                    </x-sheet.button>
                </div>
                <div class="min-h-40 flex-1 overflow-auto rounded-lg bg-zinc-950 p-3 font-mono text-xs leading-relaxed text-raw-zinc-200">
                    @forelse ($appLogs ?? [] as $line)
                        @php $err = Logs::isErrorLine($line); @endphp
                        <div
                            x-show="(only === 'all' || {{ $err ? 'true' : 'false' }}) && (source === 'all' || source === @js($line['source'])) && (! worker || worker === @js($line['worker'])) && (q === '' || $el.textContent.toLowerCase().includes(q.toLowerCase()))"
                            @class(['grid grid-cols-[5.5rem_4.5rem_minmax(0,1fr)] gap-3', 'bg-rose-500/10 text-rose-200' => $err])
                        >
                            <span class="text-zinc-500">{{ $line['at'] ? \Illuminate\Support\Carbon::parse($line['at'])->timezone(config('app.timezone'))->format('H:i:s') : '' }}</span>
                            <span class="text-zinc-500">{{ $line['worker'] ?? $line['source'] }}</span>
                            <span class="whitespace-pre-wrap break-words">{{ $line['message'] }}</span>
                        </div>
                    @empty
                        <p class="text-zinc-400">{{ __('Nothing printed in the last 15 minutes. Containers log to stdout and stderr.') }}</p>
                    @endforelse
                </div>
            </div>
        </x-modal>
    @endif
</div>
