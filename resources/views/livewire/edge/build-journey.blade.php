<div @if ($polling) wire:poll.1s="tail" @endif>
    @if ($missing || $journey === null)
        <div class="rounded-2xl border border-dashed border-brand-ink/15 bg-white/40 px-5 py-6 text-center text-xs text-brand-moss">
            {{ __('Deployment no longer available.') }}
        </div>
    @elseif ($logOnly)
        @php
            $inFlight = $polling && ! $journey['hasFailed'] && ! $journey['isDone'];
            $statusLabel = $journey['hasFailed']
                ? __('Failed')
                : ($journey['isDone'] ? __('Live') : __('Building'));
            $commit = is_string($deployment->git_commit ?? null) && $deployment->git_commit !== ''
                ? substr((string) $deployment->git_commit, 0, 7)
                : null;
        @endphp
        <div class="overflow-hidden rounded-xl border border-brand-ink/10 bg-white dark:border-brand-mist/20 dark:bg-zinc-900">
            <div
                x-data="{
                    pinned: true,
                    onScroll() {
                        const el = $refs.logPre;
                        this.pinned = (el.scrollHeight - el.scrollTop - el.clientHeight) < 16;
                    },
                    init() {
                        this.$nextTick(() => { $refs.logPre.scrollTop = $refs.logPre.scrollHeight; });
                        Livewire.hook('morph.updated', () => {
                            if (this.pinned && $refs.logPre) { $refs.logPre.scrollTop = $refs.logPre.scrollHeight; }
                        });
                    },
                }"
            >
                <pre
                    x-ref="logPre"
                    x-on:scroll.throttle.100ms="onScroll"
                    class="max-h-80 min-h-40 overflow-auto bg-brand-ink px-4 py-3 font-mono text-xs leading-relaxed text-brand-cream whitespace-pre-wrap break-words"
                >@if (trim($buffer) === ''){{ __('Creating build environment…') }}@else{!! \App\Modules\Edge\Support\AnsiHtml::toHtml($buffer) !!}@endif</pre>
            </div>
            <div class="flex flex-wrap items-center justify-between gap-3 border-t border-brand-ink/10 px-4 py-3 dark:border-brand-mist/20">
                <div class="min-w-0">
                    <p class="truncate text-sm font-semibold text-brand-ink">
                        {{ $site?->name }}
                        @if ($commit)
                            <span class="font-mono text-xs font-normal text-brand-moss">· {{ $commit }}</span>
                        @endif
                    </p>
                    <p class="mt-0.5 inline-flex items-center gap-1.5 text-xs text-brand-moss">
                        <span class="inline-flex h-2 w-2 rounded-full {{ $journey['hasFailed'] ? 'bg-red-500' : ($journey['isDone'] ? 'bg-emerald-500' : 'bg-brand-sage') }}" aria-hidden="true"></span>
                        {{ $statusLabel }}
                    </p>
                </div>
                <div class="flex items-center gap-2">
                    @if ($inFlight)
                        <button
                            type="button"
                            wire:click="confirmCancelBuild"
                            class="rounded-lg px-3 py-1.5 text-sm font-semibold text-brand-moss hover:text-brand-ink"
                        >
                            {{ __('Cancel') }}
                        </button>
                    @endif
                    @if ($server && $site)
                        <a
                            href="{{ route('sites.show', ['server' => $server, 'site' => $site]) }}"
                            wire:navigate
                            class="inline-flex items-center rounded-lg bg-brand-ink px-3 py-1.5 text-sm font-semibold text-brand-cream hover:bg-brand-forest"
                        >
                            {{ __('Go to app') }}
                        </a>
                    @endif
                </div>
            </div>
            @if ($journey['hasFailed'] && $journey['error'])
                <p class="border-t border-red-200 px-4 py-2 font-mono text-xs text-red-800 dark:border-red-900/40 dark:text-red-200">{{ $journey['error'] }}</p>
            @endif
        </div>
        @include('livewire.partials.confirm-action-modal')
    @else
        @php
            $sectionFor = [
                'queued' => 'clone',
                'building' => 'build',
                'publishing' => 'publish',
                'live' => null,
            ];
        @endphp

        {{-- Nested under site/workspace chrome: hairline strips, not a second outer card. --}}
        <section class="overflow-hidden">
            <div class="border-b border-brand-ink/10 bg-brand-sand/15 px-5 py-4 sm:px-6">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div class="min-w-0">
                        <div class="flex items-center gap-2">
                            @if ($journey['hasFailed'])
                                <span class="inline-flex items-center gap-1.5 rounded-full bg-red-100 px-2.5 py-1 text-2xs font-semibold uppercase tracking-wide text-red-800 ring-1 ring-red-200">
                                    <x-heroicon-s-x-mark class="h-3 w-3" />
                                    {{ __('Failed') }}
                                </span>
                            @elseif ($journey['isDone'])
                                <span class="inline-flex items-center gap-1.5 rounded-full bg-emerald-100 px-2.5 py-1 text-2xs font-semibold uppercase tracking-wide text-emerald-800 ring-1 ring-emerald-200">
                                    <x-heroicon-s-check class="h-3 w-3" />
                                    {{ __('Live') }}
                                </span>
                            @else
                                <span class="inline-flex items-center gap-1.5 rounded-full bg-sky-100 px-2.5 py-1 text-2xs font-semibold uppercase tracking-wide text-sky-800 ring-1 ring-sky-200">
                                    <x-heroicon-o-arrow-path class="h-3 w-3 animate-spin" />
                                    {{ __('Building') }}
                                </span>
                            @endif
                        </div>
                        <h2 class="mt-2 text-base font-semibold text-brand-ink">
                            {{ __('Edge build (:done/:total)', ['done' => $journey['completedSteps'], 'total' => $journey['totalSteps']]) }}
                        </h2>
                        <p class="mt-1 text-sm text-brand-moss">{{ $journey['currentLabel'] }}</p>
                    </div>
                    <span class="shrink-0 text-sm font-semibold tabular-nums {{ $journey['hasFailed'] ? 'text-red-700' : 'text-brand-forest' }}">
                        {{ $journey['progressPercent'] }}%
                    </span>
                </div>

                <div class="mt-4 h-2.5 overflow-hidden rounded-full bg-brand-sand/80">
                    <div class="h-full rounded-full {{ $journey['hasFailed'] ? 'bg-red-500' : 'bg-brand-forest' }} transition-[width] duration-300" style="width: {{ $journey['progressPercent'] }}%"></div>
                </div>

                @if ($journey['hasFailed'] && $journey['error'])
                    <div class="mt-3 rounded-xl border border-red-300 bg-white/80 px-3 py-2 text-xs">
                        <p class="font-semibold uppercase tracking-wide text-red-700">{{ __('Reason') }}</p>
                        <p class="mt-1 break-words font-mono leading-5 text-red-900">{{ $journey['error'] }}</p>
                    </div>
                @endif
            </div>

            <ol class="divide-y divide-brand-ink/8">
                @foreach ($journey['visibleSteps'] as $key => $label)
                    @php
                        $loopIndex = array_search($key, $journey['stepKeys'], true);
                        $isDone = ! $journey['hasFailed'] && $loopIndex !== false && $loopIndex < $journey['currentStepIndex'];
                        $isCurrent = $key === $journey['state'];
                        $sectionKey = $sectionFor[$key] ?? null;
                        $stepLog = ($sectionKey !== null && isset($sections[$sectionKey])) ? $sections[$sectionKey] : '';
                        $hasLog = $stepLog !== '';
                    @endphp
                    <li class="px-5 py-4 sm:px-6">
                        <div class="flex items-start gap-3">
                            <div class="flex size-7 shrink-0 items-center justify-center rounded-full text-xs font-semibold {{ $isCurrent ? ($journey['hasFailed'] ? 'bg-red-600 text-white' : 'bg-brand-forest text-white ring-4 ring-brand-sage/30') : ($isDone ? 'bg-emerald-600 text-white' : 'bg-white text-brand-mist ring-1 ring-brand-ink/10') }}">
                                @if ($isDone)
                                    <x-heroicon-s-check class="h-4 w-4" />
                                @elseif ($isCurrent && ! $journey['hasFailed'])
                                    <span class="inline-flex h-2.5 w-2.5 animate-pulse rounded-full bg-white"></span>
                                @else
                                    {{ $loop->iteration }}
                                @endif
                            </div>
                            <div class="min-w-0 flex-1">
                                <div class="flex flex-wrap items-center gap-2">
                                    <p class="text-sm font-medium text-brand-ink">{{ $label }}</p>
                                    @if ($isCurrent && ! $journey['hasFailed'])
                                        <span class="rounded-full bg-brand-sage/20 px-2 py-0.5 text-2xs font-semibold uppercase tracking-wide text-brand-forest">{{ __('Live') }}</span>
                                    @elseif ($isDone)
                                        <span class="rounded-full bg-emerald-100 px-2 py-0.5 text-2xs font-semibold uppercase tracking-wide text-emerald-800">{{ __('Done') }}</span>
                                    @endif
                                </div>

                                @if (! $hasLog && $isCurrent && ! $journey['hasFailed'])
                                    <p class="mt-2 text-xs text-brand-mist">
                                        {{ __('Waiting for log output…') }}
                                    </p>
                                @endif

                                @if ($hasLog)
                                    {{-- Pin <details open> for any step that has content. Tying
                                         this to $isCurrent caused the previous step's output to
                                         collapse the moment the build advanced (the "goes away
                                         after it shows once" bug). Operators can still collapse
                                         manually if they want; we just don't reset it on poll. --}}
                                    <details class="group mt-3" open>
                                        <summary class="flex cursor-pointer items-center gap-2 text-2xs font-semibold uppercase tracking-[0.16em] text-brand-mist hover:text-brand-ink">
                                            <x-heroicon-m-chevron-right class="h-3 w-3 transition-transform group-open:rotate-90" />
                                            {{ __('Output') }}
                                            @if ($isCurrent && ! $journey['hasFailed'])
                                                <span class="inline-flex h-1.5 w-1.5 animate-pulse rounded-full bg-emerald-500" aria-hidden="true"></span>
                                            @endif
                                        </summary>
                                        <div
                                            x-data="{
                                                pinned: true,
                                                copied: false,
                                                onScroll() {
                                                    const el = $refs.logPre;
                                                    this.pinned = (el.scrollHeight - el.scrollTop - el.clientHeight) < 16;
                                                },
                                                copy() {
                                                    navigator.clipboard.writeText($refs.logPre.innerText);
                                                    this.copied = true;
                                                    setTimeout(() => { this.copied = false; }, 1500);
                                                },
                                                init() {
                                                    this.$nextTick(() => { $refs.logPre.scrollTop = $refs.logPre.scrollHeight; });
                                                    Livewire.hook('morph.updated', () => {
                                                        if (this.pinned) { $refs.logPre.scrollTop = $refs.logPre.scrollHeight; }
                                                    });
                                                },
                                            }"
                                            class="relative mt-2"
                                        >
                                            <button
                                                type="button"
                                                x-on:click="copy()"
                                                class="absolute right-2 top-2 z-10 inline-flex items-center gap-1 rounded-md border border-white/15 bg-white/5 px-2 py-1 text-2xs font-semibold text-brand-sand/80 backdrop-blur hover:bg-white/10 hover:text-brand-cream"
                                                :title="copied ? '{{ __('Copied') }}' : '{{ __('Copy to clipboard') }}'"
                                            >
                                                <x-heroicon-o-clipboard x-show="!copied" class="h-3 w-3" />
                                                <x-heroicon-s-check x-show="copied" x-cloak class="h-3 w-3 text-emerald-400" />
                                                <span x-show="!copied">{{ __('Copy') }}</span>
                                                <span x-show="copied" x-cloak>{{ __('Copied') }}</span>
                                            </button>
                                            <pre
                                                x-ref="logPre"
                                                x-on:scroll.throttle.100ms="onScroll"
                                                class="max-h-[28rem] overflow-auto rounded-xl border border-brand-ink/10 bg-brand-ink px-3 py-2 pr-20 font-mono text-xs leading-relaxed text-brand-cream whitespace-pre-wrap break-words"
                                            >{!! \App\Modules\Edge\Support\AnsiHtml::toHtml($stepLog) !!}</pre>
                                        </div>
                                    </details>
                                @endif
                            </div>
                        </div>
                    </li>
                @endforeach
            </ol>

            <div class="flex flex-wrap items-center justify-between gap-3 border-t border-brand-ink/10 bg-brand-sand/15 px-5 py-3 sm:px-6">
                @if ($polling && ! $journey['hasFailed'] && ! $journey['isDone'])
                    <button
                        type="button"
                        wire:click="confirmRestartFrozenBuild"
                        class="inline-flex items-center gap-1.5 text-xs font-medium text-amber-700 hover:text-amber-900 hover:underline"
                    >
                        <x-heroicon-o-arrow-path class="h-4 w-4" />
                        {{ __('Restart build') }}
                    </button>
                @else
                    <span></span>
                @endif
                <a
                    href="{{ route('sites.edge.deployments.show', ['server' => $server, 'site' => $site, 'deployment' => $deployment, 'tab' => 'log']) }}"
                    wire:navigate
                    class="text-xs font-medium text-brand-forest hover:underline dark:text-brand-sage"
                >
                    {{ __('Open full build log →') }}
                </a>
            </div>
        </section>

        @include('livewire.partials.confirm-action-modal')
    @endif
</div>
