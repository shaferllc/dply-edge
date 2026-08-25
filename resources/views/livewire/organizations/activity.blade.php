@php
    // Tone → tile / dot styles. Centralized here so every row uses the
    // same vocabulary instead of inline conditionals on every entry.
    $tonePalette = [
        'success' => ['tile' => 'bg-brand-sage/15 text-brand-forest ring-brand-sage/25', 'dot' => 'bg-brand-sage'],
        'info' => ['tile' => 'bg-sky-50 text-sky-700 ring-sky-200', 'dot' => 'bg-sky-500'],
        'warning' => ['tile' => 'bg-amber-50 text-amber-900 ring-amber-200', 'dot' => 'bg-amber-500'],
        'danger' => ['tile' => 'bg-red-50 text-red-700 ring-red-200', 'dot' => 'bg-red-500'],
        'neutral' => ['tile' => 'bg-brand-sand/45 text-brand-moss ring-brand-ink/10', 'dot' => 'bg-brand-mist'],
    ];

    $eventsTotal = $this->familyTotals[''] ?? 0;
    $activeFamilies = collect($this->familyTotals)->except('')->filter(fn ($n) => $n > 0)->count();
    $allTotal = $eventsTotal;
@endphp

<div>
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
        <x-organization-shell
            :organization="$organization"
            section="activity"
            :title="__('Activity')"
            :description="__('Audit trail — filter by family or search action / subject.')"
            icon="heroicon-o-clock"
            :breadcrumb="[
                ['label' => __('Dashboard'), 'href' => route('dashboard'), 'icon' => 'home'],
                ['label' => $organization->name, 'href' => route('organizations.show', $organization), 'icon' => 'building-office-2'],
                ['label' => __('Activity'), 'icon' => 'clock'],
            ]"
        >
            <x-slot:actions>
                <a
                    href="{{ route('organizations.compliance-export', $organization) }}"
                    class="inline-flex h-6 items-center gap-1 rounded-md border border-brand-ink/15 bg-white px-2 text-xs font-semibold text-brand-ink shadow-sm hover:bg-brand-sand/40"
                >
                    <x-heroicon-o-archive-box-arrow-down class="h-3.5 w-3.5 shrink-0 opacity-90" aria-hidden="true" />
                    {{ __('Compliance export') }}
                </a>
                @if ($family !== '' || $search !== '')
                    <button
                        type="button"
                        wire:click="clearFilters"
                        class="inline-flex h-6 items-center gap-1 rounded-md border border-brand-ink/15 bg-white px-2 text-xs font-semibold text-brand-ink shadow-sm hover:bg-brand-sand/40"
                    >
                        <x-heroicon-o-x-mark class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                        {{ __('Clear filters') }}
                    </button>
                @endif
            </x-slot:actions>

            <x-slot:stats>
                <dl class="grid grid-cols-3 gap-px bg-brand-ink/5" aria-label="{{ __('Activity at a glance') }}">
                    <div class="bg-white px-3 py-2">
                        <dt class="text-2xs font-semibold uppercase tracking-wide text-brand-mist">{{ __('Events') }}</dt>
                        <dd class="mt-0.5 font-mono text-base font-semibold tabular-nums text-brand-ink">{{ number_format($eventsTotal) }}</dd>
                    </div>
                    <div class="bg-white px-3 py-2">
                        <dt class="text-2xs font-semibold uppercase tracking-wide text-brand-mist">{{ __('Families') }}</dt>
                        <dd class="mt-0.5 font-mono text-base font-semibold tabular-nums text-brand-ink">{{ $activeFamilies }}</dd>
                    </div>
                    <div class="bg-white px-3 py-2">
                        <dt class="text-2xs font-semibold uppercase tracking-wide text-brand-mist">{{ __('Audit log') }}</dt>
                        <dd class="mt-0.5 flex items-center gap-1 text-sm font-semibold text-brand-forest">
                            <x-heroicon-m-lock-closed class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                            {{ __('Append-only') }}
                        </dd>
                    </div>
                </dl>
            </x-slot:stats>

            {{-- Flush filter strip: family pills + search. --}}
            <x-slot:tabs>
                <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                    <nav class="flex min-w-0 flex-1 flex-wrap items-center gap-1 overflow-x-auto" aria-label="{{ __('Family filter') }}">
                        <button
                            type="button"
                            wire:click="setFamily('')"
                            @class([
                                'inline-flex h-6 items-center gap-1 rounded-md border px-2 text-xs font-semibold transition shadow-sm',
                                'border-brand-ink bg-brand-ink text-brand-cream' => $family === '',
                                'border-brand-ink/15 bg-white text-brand-moss hover:border-brand-ink/30 hover:text-brand-ink' => $family !== '',
                            ])
                        >
                            <x-heroicon-o-squares-2x2 class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                            {{ __('All') }}
                            <span @class([
                                'ms-0.5 rounded px-1 py-px text-2xs tabular-nums',
                                'bg-brand-cream/20 text-brand-cream' => $family === '',
                                'bg-brand-sand/60 text-brand-moss' => $family !== '',
                            ])>{{ $allTotal }}</span>
                        </button>
                        @foreach ($families as $f)
                            @php $count = $this->familyTotals[$f['id']] ?? 0; @endphp
                            <button
                                type="button"
                                wire:click="setFamily('{{ $f['id'] }}')"
                                @disabled($count === 0 && $family !== $f['id'])
                                @class([
                                    'inline-flex h-6 items-center gap-1 rounded-md border px-2 text-xs font-semibold transition shadow-sm',
                                    'border-brand-ink bg-brand-ink text-brand-cream' => $family === $f['id'],
                                    'border-brand-ink/15 bg-white text-brand-moss hover:border-brand-ink/30 hover:text-brand-ink' => $family !== $f['id'] && $count > 0,
                                    'border-brand-ink/10 bg-white text-brand-mist cursor-not-allowed opacity-60' => $count === 0 && $family !== $f['id'],
                                ])
                            >
                                <x-dynamic-component :component="$f['icon']" class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                                {{ $f['label'] }}
                                <span @class([
                                    'ms-0.5 rounded px-1 py-px text-2xs tabular-nums',
                                    'bg-brand-cream/20 text-brand-cream' => $family === $f['id'],
                                    'bg-brand-sand/60 text-brand-moss' => $family !== $f['id'],
                                ])>{{ $count }}</span>
                            </button>
                        @endforeach
                    </nav>

                    <div class="relative w-full sm:max-w-xs sm:shrink-0">
                        <span class="pointer-events-none absolute inset-y-0 start-0 flex items-center ps-2.5 text-brand-mist">
                            <x-heroicon-o-magnifying-glass class="h-3.5 w-3.5" aria-hidden="true" />
                        </span>
                        <input
                            type="search"
                            wire:model.live.debounce.300ms="search"
                            placeholder="{{ __('Search action or subject…') }}"
                            class="block h-7 w-full rounded-md border-brand-ink/15 bg-white py-1 ps-8 pe-2.5 text-xs shadow-sm focus:border-brand-sage focus:ring-brand-sage"
                        />
                    </div>
                </div>
            </x-slot:tabs>

            {{-- Timeline as a hairline strip (not a nested card). --}}
            <section class="border-b border-brand-ink/10 last:border-b-0">
                <x-workspace-panel-head
                    dense
                    class="border-b border-brand-ink/10"
                    icon="heroicon-o-queue-list"
                    :title="__('Recent activity')"
                    :note="__('Expand a row for before/after when a change was recorded.')"
                />

                @if ($this->auditLogs->isEmpty())
                    <div class="px-3 py-10 text-center sm:px-4">
                        <span class="mx-auto inline-flex h-9 w-9 items-center justify-center rounded-lg bg-brand-sand/45 text-brand-moss ring-1 ring-brand-ink/10">
                            <x-heroicon-o-inbox class="h-4 w-4" aria-hidden="true" />
                        </span>
                        <p class="mt-3 text-sm font-medium text-brand-ink">
                            @if ($family !== '' || $search !== '')
                                {{ __('No activity matches the current filters.') }}
                            @else
                                {{ __('No activity yet.') }}
                            @endif
                        </p>
                        @if ($family !== '' || $search !== '')
                            <button type="button" wire:click="clearFilters" class="mt-2 text-xs font-semibold text-brand-sage hover:text-brand-ink">
                                {{ __('Clear filters') }}
                            </button>
                        @endif
                    </div>
                @else
                    <ul class="divide-y divide-brand-ink/10">
                        @foreach ($this->auditLogs as $log)
                            @php
                                $meta = \App\Support\AuditActionMeta::meta((string) $log->action);
                                $palette = $tonePalette[$meta['tone']] ?? $tonePalette['neutral'];
                                $expanded = in_array($log->id, $expandedIds, true);
                                $hasDiff = ! empty($log->old_values ?? []) || ! empty($log->new_values ?? []);
                            @endphp
                            <li wire:key="log-{{ $log->id }}" class="group">
                                <button
                                    type="button"
                                    @if ($hasDiff) wire:click="toggleRow({{ $log->id }})" @endif
                                    @disabled(! $hasDiff)
                                    class="flex w-full items-start gap-2.5 px-3 py-2.5 text-left transition-colors hover:bg-brand-sand/15 disabled:cursor-default sm:px-4"
                                >
                                    <span class="relative shrink-0">
                                        <span class="inline-flex h-7 w-7 items-center justify-center rounded-lg ring-1 {{ $palette['tile'] }}">
                                            <x-dynamic-component :component="$meta['icon']" class="h-3.5 w-3.5" aria-hidden="true" />
                                        </span>
                                        <span class="absolute -end-0.5 -bottom-0.5 inline-block h-1.5 w-1.5 rounded-full ring-2 ring-white {{ $palette['dot'] }}" aria-hidden="true"></span>
                                    </span>

                                    <div class="min-w-0 flex-1">
                                        <div class="flex flex-wrap items-baseline justify-between gap-x-3 gap-y-0.5">
                                            <p class="text-sm font-semibold text-brand-ink">
                                                {{ $meta['label'] }}
                                            </p>
                                            <p class="shrink-0 text-xs text-brand-mist tabular-nums" title="{{ $log->created_at->toDayDateTimeString() }}">
                                                {{ $log->created_at->diffForHumans() }}
                                            </p>
                                        </div>
                                        <p class="mt-0.5 flex flex-wrap items-center gap-x-1.5 gap-y-0.5 text-xs text-brand-moss">
                                            @if ($log->user)
                                                <span class="inline-flex items-center gap-1">
                                                    <x-heroicon-m-user-circle class="h-3.5 w-3.5 shrink-0 text-brand-mist" aria-hidden="true" />
                                                    {{ $log->user->name }}
                                                </span>
                                            @else
                                                <span class="inline-flex items-center gap-1 text-brand-mist">
                                                    <x-heroicon-m-bolt class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                                                    {{ __('System') }}
                                                </span>
                                            @endif
                                            @if ($log->subject_summary)
                                                <span class="text-brand-mist">·</span>
                                                <span class="truncate font-mono text-xs text-brand-ink/85">{{ $log->subject_summary }}</span>
                                            @endif
                                            <span class="text-brand-mist">·</span>
                                            <code class="font-mono text-2xs text-brand-mist">{{ $log->action }}</code>
                                        </p>
                                    </div>

                                    @if ($hasDiff)
                                        <span class="shrink-0 self-center text-brand-mist transition group-hover:text-brand-moss">
                                            @if ($expanded)
                                                <x-heroicon-m-chevron-up class="h-4 w-4" aria-hidden="true" />
                                            @else
                                                <x-heroicon-m-chevron-down class="h-4 w-4" aria-hidden="true" />
                                            @endif
                                        </span>
                                    @endif
                                </button>

                                @if ($expanded && $hasDiff)
                                    <div class="border-t border-brand-ink/10 bg-brand-cream/40 px-3 py-2.5 sm:px-4">
                                        <div class="grid gap-2 sm:grid-cols-2">
                                            <div>
                                                <p class="text-2xs font-semibold uppercase tracking-wide text-brand-mist">{{ __('Before') }}</p>
                                                @if (! empty($log->old_values))
                                                    <pre class="mt-1 max-h-40 overflow-auto rounded-md border border-brand-ink/10 bg-white p-2 font-mono text-xs leading-relaxed text-brand-ink">{{ json_encode($log->old_values, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) }}</pre>
                                                @else
                                                    <p class="mt-1 rounded-md border border-dashed border-brand-ink/10 bg-white/60 p-2 text-xs text-brand-mist">{{ __('—') }}</p>
                                                @endif
                                            </div>
                                            <div>
                                                <p class="text-2xs font-semibold uppercase tracking-wide text-brand-mist">{{ __('After') }}</p>
                                                @if (! empty($log->new_values))
                                                    <pre class="mt-1 max-h-40 overflow-auto rounded-md border border-brand-ink/10 bg-white p-2 font-mono text-xs leading-relaxed text-brand-ink">{{ json_encode($log->new_values, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) }}</pre>
                                                @else
                                                    <p class="mt-1 rounded-md border border-dashed border-brand-ink/10 bg-white/60 p-2 text-xs text-brand-mist">{{ __('—') }}</p>
                                                @endif
                                            </div>
                                        </div>
                                    </div>
                                @endif
                            </li>
                        @endforeach
                    </ul>

                    <div class="flex flex-col items-stretch gap-3 border-t border-brand-ink/10 bg-brand-sand/25 px-3 py-2.5 sm:flex-row sm:items-center sm:justify-between sm:gap-6 sm:px-4">
                        <label class="inline-flex items-center gap-2 text-xs text-brand-moss" for="activity-per-page">
                            <span class="whitespace-nowrap">{{ __('Rows per page') }}</span>
                            <select
                                id="activity-per-page"
                                wire:model.live="perPage"
                                class="h-7 rounded-md border-brand-ink/15 bg-white py-1 pl-2 pr-7 text-xs text-brand-ink shadow-sm focus:border-brand-sage focus:ring-brand-sage"
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
            </section>
        </x-organization-shell>
    </div>
</div>
