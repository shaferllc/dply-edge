@php
    use App\Models\EdgeDeployment;

    $hasBuilding = $edgeDeployments->contains(fn ($d) => in_array($d->status, [EdgeDeployment::STATUS_BUILDING, EdgeDeployment::STATUS_PUBLISHING], true));
@endphp

<div>
    <div class="flex flex-wrap items-center justify-between gap-2 border-b border-brand-ink/10 px-5 py-3 sm:px-6">
        <p class="text-xs text-brand-moss">
            {{ __('Build and deploy output — not visitor HTTP logs.') }}
            <a
                href="{{ route('sites.show', ['server' => $server ?? $site->server, 'site' => $site, 'section' => 'traffic']) }}"
                wire:navigate
                class="font-medium text-brand-sage hover:underline"
            >{{ __('Live requests') }}</a>
            {{ __('are under Traffic.') }}
        </p>
        <a
            href="{{ route('sites.show', ['server' => $server ?? $site->server, 'site' => $site, 'section' => 'deploys']) }}"
            wire:navigate
            class="text-xs font-medium text-brand-sage hover:underline"
        >
            {{ __('Full history') }}
        </a>
    </div>

    <section class="border-b border-brand-ink/10" @if ($hasBuilding) wire:poll.5s="refreshEdgeLogDeployments" @endif>
        <div class="flex items-center justify-between gap-2 px-5 py-3 sm:px-6">
            <p class="text-2xs font-semibold uppercase tracking-[0.16em] text-brand-mist">{{ __('Recent deploys') }}</p>
        </div>

        @if ($edgeDeployments->isEmpty())
            <p class="px-5 pb-8 text-center text-sm text-brand-moss sm:px-6">{{ __('No activity yet — trigger a deploy from Overview.') }}</p>
        @else
            <ul class="divide-y divide-brand-ink/8 border-t border-brand-ink/8">
                @foreach ($edgeDeployments as $deployment)
                    @php
                        $depBadge = match ($deployment->status) {
                            EdgeDeployment::STATUS_LIVE => 'text-emerald-700 dark:text-emerald-400',
                            EdgeDeployment::STATUS_FAILED => 'text-rose-700 dark:text-rose-400',
                            default => 'text-brand-moss',
                        };
                        $failureReason = $deployment->failure_reason;
                        $buildLogLoaded = isset($edgeDeploymentBuildLogsLoaded[$deployment->id]);
                        $loadedBuildLog = $buildLogLoaded ? $this->edgeDeploymentBuildLog($deployment->id) : null;
                        $statusLabel = str_replace('_', ' ', (string) $deployment->status);
                    @endphp
                    <li class="px-5 py-3 sm:px-6" wire:key="edge-log-{{ $deployment->id }}">
                        @php
                            $commitMeta = is_array($deployment->meta['commit'] ?? null) ? $deployment->meta['commit'] : [];
                            $commitSubject = is_string($commitMeta['subject'] ?? null) ? $commitMeta['subject'] : null;
                            $commitAuthor = is_string($commitMeta['author'] ?? null) ? $commitMeta['author'] : null;
                            $containerMeta = is_array($deployment->meta['container'] ?? null) ? $deployment->meta['container'] : [];
                            $containerStack = is_string($containerMeta['stack'] ?? null) ? $containerMeta['stack'] : null;
                            $healthMeta = is_array($containerMeta['health'] ?? null) ? $containerMeta['health'] : [];
                            $healthStatus = isset($healthMeta['status']) ? (int) $healthMeta['status'] : null;
                            $healthMs = isset($healthMeta['ms']) ? (int) $healthMeta['ms'] : null;
                            $rolloutHealth = is_array($containerMeta['rollout']['health'] ?? null) ? $containerMeta['rollout']['health'] : [];
                            $healthyInstances = isset($rolloutHealth['healthy']) ? (int) $rolloutHealth['healthy'] : null;
                            $buildSeconds = (int) ($deployment->build_seconds ?? 0);
                            $buildDuration = $buildSeconds > 0
                                ? ($buildSeconds < 60 ? $buildSeconds.'s' : intdiv($buildSeconds, 60).'m '.str_pad((string) ($buildSeconds % 60), 2, '0', STR_PAD_LEFT).'s')
                                : null;
                            $isProduction = ($edgeActiveDeploymentId ?? null) === $deployment->id;
                            $detailUrl = route('sites.edge.deployments.show', ['server' => $server ?? $site->server, 'site' => $site, 'deployment' => $deployment]);
                        @endphp
                        <div class="flex flex-wrap items-baseline justify-between gap-x-3 gap-y-1">
                            <p class="text-sm capitalize {{ $depBadge }}">
                                <span class="font-semibold">{{ $statusLabel }}</span>
                                @if ($isProduction)
                                    <span class="ms-1 text-xs font-semibold uppercase tracking-wide text-brand-mist">{{ __('Production') }}</span>
                                @endif
                                @if ($deployment->git_branch)
                                    <span class="font-mono text-xs text-brand-mist">· {{ $deployment->git_branch }}</span>
                                @endif
                                @if ($deployment->git_commit)
                                    <a href="{{ $detailUrl }}" wire:navigate class="font-mono text-xs text-brand-sage hover:underline">· {{ \Illuminate\Support\Str::limit($deployment->git_commit, 7, '') }}</a>
                                @endif
                            </p>
                            <time class="shrink-0 text-xs text-brand-mist">
                                {{ $deployment->created_at?->timezone(config('app.timezone'))->format('M j, g:i A') ?? '—' }}
                            </time>
                        </div>
                        @if ($commitSubject)
                            <p class="mt-1 text-sm text-brand-ink">{{ $commitSubject }}</p>
                        @endif
                        <p class="mt-1 flex flex-wrap gap-x-3 gap-y-1 text-xs text-brand-moss">
                            @if ($commitAuthor)
                                <span>{{ $commitAuthor }}</span>
                            @endif
                            @if ($buildDuration)
                                <span>{{ __('Built in :time', ['time' => $buildDuration]) }}</span>
                            @endif
                            @if ($deployment->published_at)
                                <span>{{ __('Published :time', ['time' => $deployment->published_at->diffForHumans()]) }}</span>
                            @endif
                            @if ($containerStack)
                                <span class="uppercase">{{ $containerStack }}</span>
                            @endif
                            @if ($healthStatus !== null)
                                <span>{{ __('Health :status', ['status' => $healthStatus]) }}@if ($healthMs !== null) · {{ $healthMs }} ms @endif</span>
                            @endif
                            @if ($healthyInstances !== null)
                                <span>{{ trans_choice(':count healthy instance|:count healthy instances', $healthyInstances, ['count' => $healthyInstances]) }}</span>
                            @endif
                        </p>
                        <p class="mt-0.5 truncate font-mono text-xs text-brand-mist" title="{{ $deployment->id }}">
                            <a href="{{ $detailUrl }}" wire:navigate class="hover:text-brand-sage hover:underline">{{ $deployment->id }}</a>
                        </p>

                        @if (is_string($failureReason) && $failureReason !== '')
                            @include('livewire.sites.partials.edge.build-log-lint-callout', [
                                'buildLog' => $buildLogLoaded ? $loadedBuildLog : null,
                                'failureReason' => $failureReason,
                                'site' => $site,
                                'server' => $server ?? $site->server,
                                'deployment' => $deployment,
                            ])
                            @if (! str_contains($failureReason, 'dply config lint failed'))
                                <pre class="mt-2 max-h-40 overflow-auto rounded-lg border border-rose-200/60 bg-rose-50/50 p-2.5 font-mono text-xs text-rose-900 dark:border-raw-rose-900/30 dark:bg-rose-950/20 dark:text-raw-rose-200">{{ $failureReason }}</pre>
                            @endif
                        @endif

                        @if ($deployment->build_log_path || (is_string($failureReason) && $failureReason !== ''))
                            <details
                                wire:ignore.self
                                class="mt-2 group"
                                x-on:toggle="if ($el.open) $wire.loadEdgeDeploymentBuildLog(@js($deployment->id))"
                            >
                                <summary class="flex cursor-pointer list-none items-center gap-1 text-xs font-medium text-brand-sage hover:underline [&::-webkit-details-marker]:hidden">
                                    <x-heroicon-m-chevron-right class="h-3.5 w-3.5 transition group-open:rotate-90" />
                                    {{ __('Build log') }}
                                </summary>
                                <div class="mt-2 rounded-lg border border-brand-ink/10 bg-brand-sand/10 p-2.5 dark:border-brand-mist/20">
                                    <div wire:loading wire:target="loadEdgeDeploymentBuildLog('{{ $deployment->id }}')" class="text-xs text-brand-moss">
                                        {{ __('Loading…') }}
                                    </div>
                                    @if ($buildLogLoaded)
                                        @if ($loadedBuildLog !== null && $loadedBuildLog !== '')
                                            @if ($deployment->status !== EdgeDeployment::STATUS_FAILED || ! is_string($failureReason) || $failureReason === '')
                                                @include('livewire.sites.partials.edge.build-log-lint-callout', [
                                                    'buildLog' => $loadedBuildLog,
                                                    'failureReason' => null,
                                                    'site' => $site,
                                                    'server' => $server ?? $site->server,
                                                    'deployment' => $deployment,
                                                ])
                                            @endif
                                            <pre class="max-h-64 overflow-auto whitespace-pre-wrap break-words font-mono text-xs text-brand-ink">{!! \App\Modules\Edge\Support\AnsiHtml::toHtml((string) $loadedBuildLog) !!}</pre>
                                        @else
                                            <x-empty-state
                                                borderless
                                                compact
                                                icon="heroicon-o-document-text"
                                                :title="__('No build log stored for this deployment')"
                                            />
                                        @endif
                                    @endif
                                </div>
                            </details>
                        @endif
                    </li>
                @endforeach
            </ul>
        @endif
    </section>
</div>
