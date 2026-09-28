{{--
  Org activity — "readable timeline" (redesign 2026-09-27, Activity 1).
  Sentences + field chips come from App\Support\AuditSentence; unmapped action codes fall back to the AuditActionMeta label.
  The raw before/after JSON stays one click away per row (toggleRow).
--}}
@php
    $allTotal = $this->familyTotals[''] ?? 0;
    $filtered = $family !== '' || $search !== '';
    // Times and day headings read in the org's timezone (General → Timezone).
    $tz = $organization->timezone ?: config('app.timezone');
    $today = now($tz);
    $dayLabel = function ($at) use ($tz, $today): string {
        $local = $at->copy()->setTimezone($tz);

        return match (true) {
            $local->isSameDay($today) => __('Today'),
            $local->isSameDay($today->copy()->subDay()) => __('Yesterday'),
            $local->isSameYear($today) => $local->format('D, M j'),
            default => $local->format('D, M j, Y'),
        };
    };
    $chip = 'inline-flex h-8 items-center gap-1.5 rounded-full border px-3 text-sm font-medium transition-colors';
    $chipOn = 'border-brand-ink bg-brand-ink text-brand-cream';
    $chipOff = 'border-brand-ink/15 text-brand-moss hover:border-brand-ink/30 hover:text-brand-ink';
    $chipEmpty = 'cursor-not-allowed border-brand-ink/10 text-brand-mist opacity-60';
    $initials = fn (string $name): string => mb_strtoupper(collect(preg_split('/\s+/', trim($name)) ?: [])->filter()->take(2)->map(fn ($w) => mb_substr($w, 0, 1))->implode('')) ?: '?';
@endphp

<div>
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
        <x-organization-shell
            :organization="$organization"
            section="activity"
            :breadcrumb="[
                ['label' => __('Dashboard'), 'href' => route('dashboard'), 'icon' => 'home'],
                ['label' => $organization->name, 'href' => route('organizations.show', $organization), 'icon' => 'building-office-2'],
                ['label' => __('Activity'), 'icon' => 'clock'],
            ]"
        >
            <div class="max-w-4xl space-y-5">
                <div class="flex flex-wrap items-end justify-between gap-3">
                    <div>
                        <h1 class="text-2xl font-semibold tracking-tight text-brand-ink">{{ __('Activity') }}</h1>
                        <p class="mt-1 text-sm text-brand-moss">{{ __('Who changed what, in plain words. Append-only.') }}</p>
                    </div>
                    <div class="flex flex-wrap items-center gap-2">
                        @if ($filtered)
                            <button type="button" wire:click="clearFilters" class="inline-flex h-9 items-center gap-1.5 rounded-lg px-3 text-sm font-medium text-brand-moss transition-colors hover:text-brand-ink">
                                <x-heroicon-o-x-mark class="h-4 w-4 shrink-0" aria-hidden="true" />
                                {{ __('Clear filters') }}
                            </button>
                        @endif
                        <a href="{{ route('organizations.compliance-export', $organization) }}" class="inline-flex h-9 items-center gap-1.5 rounded-lg border border-brand-ink/15 px-3.5 text-sm font-medium text-brand-ink transition-colors hover:bg-brand-sand/40">
                            <x-heroicon-o-archive-box-arrow-down class="h-4 w-4 shrink-0" aria-hidden="true" />
                            {{ __('Compliance export') }}
                        </a>
                    </div>
                </div>

                {{-- Search + family chips on one row (wraps on narrow screens). --}}
                <div class="flex flex-wrap items-center gap-2">
                    <div class="relative min-w-[14rem] flex-1">
                        <span class="pointer-events-none absolute inset-y-0 start-0 flex items-center ps-3 text-brand-mist">
                            <x-heroicon-o-magnifying-glass class="h-4 w-4" aria-hidden="true" />
                        </span>
                        <input
                            type="search"
                            wire:model.live.debounce.300ms="search"
                            placeholder="{{ __('Search people, apps or changes') }}"
                            aria-label="{{ __('Search activity') }}"
                            class="block h-9 w-full rounded-lg border-brand-ink/15 bg-transparent py-1 ps-9 pe-3 text-sm text-brand-ink focus:border-brand-sage focus:ring-brand-sage"
                        />
                    </div>
                    <nav class="flex flex-wrap items-center gap-1.5" aria-label="{{ __('Family filter') }}">
                        <button type="button" wire:click="setFamily('')" @class([$chip, $family === '' ? $chipOn : $chipOff])>
                            {{ __('Everything') }}
                            <span class="text-xs tabular-nums opacity-70">{{ $allTotal }}</span>
                        </button>
                        @foreach ($families as $f)
                            @php $count = $this->familyTotals[$f['id']] ?? 0; $on = $family === $f['id']; @endphp
                            <button
                                type="button"
                                wire:click="setFamily('{{ $f['id'] }}')"
                                @disabled($count === 0 && ! $on)
                                @class([$chip, $on ? $chipOn : ($count > 0 ? $chipOff : $chipEmpty)])
                            >
                                {{ $f['label'] }}
                                <span class="text-xs tabular-nums opacity-70">{{ $count }}</span>
                            </button>
                        @endforeach
                    </nav>
                </div>

                @if ($this->auditLogs->isEmpty())
                    <div class="dply-card px-5 py-10 text-center">
                        <span class="mx-auto inline-flex h-9 w-9 items-center justify-center rounded-lg bg-brand-sand/45 text-brand-moss ring-1 ring-brand-ink/10">
                            <x-heroicon-o-inbox class="h-4 w-4" aria-hidden="true" />
                        </span>
                        <p class="mt-3 text-sm font-medium text-brand-ink">
                            {{ $filtered ? __('No activity matches the current filters.') : __('No activity yet.') }}
                        </p>
                        @if ($filtered)
                            <button type="button" wire:click="clearFilters" class="mt-2 text-sm font-medium text-brand-forest hover:underline">
                                {{ __('Clear filters') }}
                            </button>
                        @endif
                    </div>
                @else
                    @foreach ($this->auditLogs->getCollection()->groupBy(fn ($log) => $dayLabel($log->created_at)) as $day => $logs)
                        <section class="space-y-2" wire:key="day-{{ $day }}">
                            <h2 class="text-2xs font-semibold uppercase tracking-[0.14em] text-brand-mist">{{ $day }}</h2>
                            <ul class="dply-card divide-y divide-brand-ink/8 overflow-hidden p-0">
                                @foreach ($logs as $log)
                                    @php
                                        $sentence = \App\Support\AuditSentence::sentence($log);
                                        $changes = \App\Support\AuditSentence::changes($log);
                                        $expanded = in_array($log->id, $expandedIds, true);
                                        $hasRaw = ! empty($log->old_values ?? []) || ! empty($log->new_values ?? []);
                                        $actor = $log->user?->name;
                                    @endphp
                                    <li wire:key="log-{{ $log->id }}">
                                        <div class="flex gap-3.5 px-5 py-3.5">
                                            @if ($actor)
                                                <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-brand-sage/20 text-2xs font-bold text-brand-forest" aria-hidden="true">{{ $initials($actor) }}</span>
                                            @else
                                                <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-brand-sand/60 text-brand-mist" aria-hidden="true">
                                                    <x-heroicon-m-bolt class="h-4 w-4" />
                                                </span>
                                            @endif

                                            <div class="min-w-0 flex-1 space-y-1.5">
                                                <p class="text-sm leading-relaxed text-brand-ink">
                                                    <span class="font-semibold">{{ $actor ?? __('System') }}</span>{{ $sentence['mapped'] ? ' ' : ' · ' }}{{ $sentence['text'] }}
                                                </p>
                                                @if ($changes !== [])
                                                    <div class="flex flex-wrap gap-1.5">
                                                        @foreach ($changes as $c)
                                                            <span class="inline-flex max-w-full items-center gap-1.5 rounded-md border border-brand-ink/10 bg-brand-sand/30 px-2 py-1 font-mono text-xs text-brand-moss">
                                                                <span>{{ $c['field'] }}</span>
                                                                <span class="truncate text-red-700 line-through dark:text-red-300">{{ $c['from'] }}</span>
                                                                <span aria-hidden="true">→</span><span class="sr-only">{{ __('to') }}</span>
                                                                <span class="truncate text-brand-forest">{{ $c['to'] }}</span>
                                                            </span>
                                                        @endforeach
                                                    </div>
                                                @endif
                                                {{-- Raw code stays visible (small) — it is what people paste into search. --}}
                                                <p class="flex flex-wrap items-center gap-x-2 text-2xs text-brand-mist">
                                                    <code class="font-mono">{{ $log->action }}</code>
                                                    @if ($hasRaw)
                                                        <button type="button" wire:click="toggleRow('{{ $log->id }}')" aria-expanded="{{ $expanded ? 'true' : 'false' }}" class="inline-flex items-center gap-0.5 font-medium hover:text-brand-ink">
                                                            {{ __('Raw event') }}
                                                            @svg($expanded ? 'heroicon-m-chevron-up' : 'heroicon-m-chevron-down', 'h-3 w-3', ['aria-hidden' => 'true'])
                                                        </button>
                                                    @endif
                                                </p>
                                            </div>

                                            <time datetime="{{ $log->created_at->toIso8601String() }}" title="{{ $log->created_at->copy()->setTimezone($tz)->toDayDateTimeString() }}" class="shrink-0 whitespace-nowrap text-xs tabular-nums text-brand-mist">
                                                {{ $log->created_at->copy()->setTimezone($tz)->format('g:i a') }}
                                            </time>
                                        </div>

                                        @if ($expanded && $hasRaw)
                                            <div class="grid gap-2 border-t border-brand-ink/10 bg-brand-sand/20 px-5 py-3 sm:grid-cols-2">
                                                @foreach (['old_values' => __('Before'), 'new_values' => __('After')] as $side => $sideLabel)
                                                    <div>
                                                        <p class="text-2xs font-semibold uppercase tracking-[0.14em] text-brand-mist">{{ $sideLabel }}</p>
                                                        @if (! empty($log->{$side}))
                                                            <pre class="mt-1 max-h-40 overflow-auto rounded-md border border-brand-ink/10 bg-brand-cream/60 p-2 font-mono text-xs leading-relaxed text-brand-ink">{{ json_encode($log->{$side}, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) }}</pre>
                                                        @else
                                                            <p class="mt-1 rounded-md border border-dashed border-brand-ink/10 p-2 text-xs text-brand-mist">—</p>
                                                        @endif
                                                    </div>
                                                @endforeach
                                            </div>
                                        @endif
                                    </li>
                                @endforeach
                            </ul>
                        </section>
                    @endforeach

                    <div class="flex flex-col items-stretch gap-3 sm:flex-row sm:items-center sm:justify-between sm:gap-6">
                        <label class="inline-flex items-center gap-2 text-xs text-brand-moss" for="activity-per-page">
                            <span class="whitespace-nowrap">{{ __('Rows per page') }}</span>
                            <select
                                id="activity-per-page"
                                wire:model.live="perPage"
                                class="h-8 rounded-md border-brand-ink/15 bg-transparent py-1 pl-2 pr-7 text-xs text-brand-ink focus:border-brand-sage focus:ring-brand-sage"
                            >
                                @foreach ([10, 25, 50, 100] as $n)
                                    <option value="{{ $n }}">{{ $n }}</option>
                                @endforeach
                            </select>
                        </label>
                        @if ($this->auditLogs->hasPages())
                            <div class="flex-1">
                                {{ $this->auditLogs->links() }}
                            </div>
                        @else
                            <span class="text-end text-xs tabular-nums text-brand-moss">{{ __(':n total', ['n' => $this->auditLogs->total()]) }}</span>
                        @endif
                    </div>
                @endif
            </div>
        </x-organization-shell>
    </div>
</div>
