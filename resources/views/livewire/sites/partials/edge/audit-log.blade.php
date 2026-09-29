@php
    use App\Models\AuditLog;
    use App\Models\Site;
    use App\Support\AuditSentence;

    $entries = AuditLog::query()
        ->with('user:id,name,email')
        ->where(function ($q) use ($site): void {
            $q->where(function ($inner) use ($site): void {
                $inner->where('subject_type', Site::class)
                    ->where('subject_id', $site->id);
            })->orWhere(function ($inner) use ($site): void {
                $inner->where('organization_id', $site->organization_id)
                    ->where('subject_id', $site->id)
                    ->where('action', 'like', 'site.edge.%');
            });
        })
        ->orderByDesc('created_at')
        ->limit(100)
        ->get();

    $tz = $site->organization?->timezone ?: config('app.timezone');
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
    // "TJ Shafer created the app blog" — mapped sentences read after the name; unmapped ones are a label.
    $line = function (AuditLog $log): string {
        $s = AuditSentence::sentence($log);

        return ($log->user?->name ?? __('System')).($s['mapped'] ? ' ' : ' · ').$s['text'];
    };
    $json = fn ($v): ?string => empty($v) ? null : json_encode($v, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

    $recent = $entries->filter(fn ($e) => $e->created_at?->gt(now()->subDays(7)));
    $actors = $recent->map(fn ($e) => $e->user?->name ?? __('System'))->unique()->values();
    $latest = $entries->first();
@endphp

<div x-data="{ entry: null }">
    <section class="border-b border-brand-ink/10 px-5 py-4 sm:px-6">
        @include('livewire.sites.edge.workspace.partials.feature-guide', [
            'docSlug' => 'activity-log',
            'what' => __('Control-plane audit trail for this Edge site — who changed settings, bindings, firewall, members, and more.'),
            'steps' => [
                __('Scan recent events after an incident or unexpected config change.'),
                __('Export CSV or JSON when you need a copy outside the dashboard.'),
            ],
            'tips' => [
                __('This is the dply audit log for this app.'),
                __('Deploy history itself lives under Deploys / Build & deploy logs.'),
            ],
        ])
    </section>

    @if ($entries->isEmpty())
        <p class="px-5 py-10 text-center text-sm text-brand-moss sm:px-6">{{ __('No audited events yet.') }}</p>
    @else
        <section class="space-y-8 px-5 py-8 sm:px-10 sm:py-10">
            <div>
                <p class="text-2xs font-semibold uppercase tracking-[0.16em] text-brand-mist">{{ __('Audit log') }}</p>
                <p class="mt-3 max-w-3xl text-2xl font-medium leading-snug tracking-tight text-brand-ink sm:text-3xl">
                    @if ($recent->isEmpty())
                        {{ __('No changes in the last 7 days.') }}
                    @else
                        {{ trans_choice(':count change in the last 7 days|:count changes in the last 7 days', $recent->count()) }},
                        {{ $actors->count() === 1 ? __('all by :name.', ['name' => $actors->first()]) : __('by :n people.', ['n' => $actors->count()]) }}
                    @endif
                    {{ __('Most recently,') }} <span class="text-brand-sage">{{ $line($latest) }}</span>, {{ $latest->created_at?->diffForHumans() }}.
                </p>
            </div>

            <div class="space-y-6">
                @foreach ($entries->groupBy(fn ($e) => $dayLabel($e->created_at)) as $day => $logs)
                    <div wire:key="audit-day-{{ $day }}">
                        <p class="border-b border-brand-ink/10 pb-2 text-sm font-semibold text-brand-ink">{{ $day }}</p>
                        <ul>
                            @foreach ($logs as $entry)
                                @php
                                    $local = $entry->created_at?->copy()->setTimezone($tz);
                                    $payload = [
                                        'sentence' => $line($entry),
                                        'action' => (string) $entry->action,
                                        'actor' => $entry->user?->name ?? __('System'),
                                        'email' => $entry->user?->email,
                                        'when' => $local?->toDayDateTimeString(),
                                        'iso' => $entry->created_at?->toIso8601String(),
                                        'ago' => $entry->created_at?->diffForHumans(),
                                        'ip' => $entry->ip_address,
                                        'changes' => AuditSentence::changes($entry),
                                        'before' => $json($entry->old_values),
                                        'after' => $json($entry->new_values),
                                    ];
                                @endphp
                                <li class="border-b border-brand-ink/10" wire:key="audit-{{ $entry->id }}">
                                    <button
                                        type="button"
                                        x-on:click="entry = @js($payload); $dispatch('open-modal', 'edge-audit-entry')"
                                        class="flex min-h-12 w-full items-center gap-3 py-3 text-left hover:bg-brand-sand/20"
                                    >
                                        <span class="flex-1 text-sm text-brand-ink sm:text-base">{{ $payload['sentence'] }}</span>
                                        <time datetime="{{ $payload['iso'] }}" class="shrink-0 font-mono text-xs tabular-nums text-brand-mist">{{ $local?->format('g:i a') }}</time>
                                        <x-heroicon-m-chevron-right class="h-4 w-4 shrink-0 text-brand-mist" aria-hidden="true" />
                                    </button>
                                </li>
                            @endforeach
                        </ul>
                    </div>
                @endforeach
            </div>

            <div class="flex flex-wrap items-center justify-between gap-3 text-sm text-brand-moss">
                <span>{{ __('Last 100 events. Deploys are under Deploys.') }}</span>
                <span class="flex gap-4">
                    <a href="{{ route('sites.edge.audit.export', ['server' => $site->server_id, 'site' => $site->id, 'format' => 'csv']) }}" class="inline-flex min-h-11 items-center gap-1 font-medium text-brand-sage hover:underline">
                        <x-heroicon-o-arrow-down-tray class="h-3.5 w-3.5" aria-hidden="true" />{{ __('Download CSV') }}
                    </a>
                    <a href="{{ route('sites.edge.audit.export', ['server' => $site->server_id, 'site' => $site->id, 'format' => 'json']) }}" class="inline-flex min-h-11 items-center gap-1 font-medium text-brand-sage hover:underline">
                        <x-heroicon-o-arrow-down-tray class="h-3.5 w-3.5" aria-hidden="true" />{{ __('Download JSON') }}
                    </a>
                </span>
            </div>
        </section>
    @endif

    <x-modal name="edge-audit-entry" maxWidth="2xl" overlayClass="bg-brand-ink/40" focusable>
        <template x-if="entry">
            <div class="space-y-6 p-6 sm:p-7">
                <div class="flex items-start justify-between gap-4">
                    <div class="min-w-0 space-y-1.5">
                        <code class="font-mono text-xs text-brand-sage" x-text="entry.action"></code>
                        <h2 class="text-lg font-semibold leading-snug text-brand-ink" x-text="entry.sentence"></h2>
                    </div>
                    <button type="button" x-on:click="$dispatch('close-modal', 'edge-audit-entry')" class="dply-icon-btn h-9 w-9" aria-label="{{ __('Close') }}">
                        <x-heroicon-o-x-mark class="h-5 w-5" aria-hidden="true" />
                    </button>
                </div>

                <dl class="grid gap-4 text-sm sm:grid-cols-3">
                    <div>
                        <dt class="text-2xs font-semibold uppercase tracking-[0.14em] text-brand-mist">{{ __('Who') }}</dt>
                        <dd class="mt-1 text-brand-ink" x-text="entry.actor"></dd>
                        <dd class="truncate text-xs text-brand-moss" x-show="entry.email" x-text="entry.email"></dd>
                    </div>
                    <div>
                        <dt class="text-2xs font-semibold uppercase tracking-[0.14em] text-brand-mist">{{ __('When') }}</dt>
                        <dd class="mt-1 text-brand-ink" x-text="entry.when" x-bind:title="entry.iso"></dd>
                        <dd class="text-xs text-brand-moss" x-text="entry.ago"></dd>
                    </div>
                    <div>
                        <dt class="text-2xs font-semibold uppercase tracking-[0.14em] text-brand-mist">{{ __('From') }}</dt>
                        <dd class="mt-1 font-mono text-xs text-brand-ink" x-text="entry.ip || '—'"></dd>
                    </div>
                </dl>

                <template x-if="entry.changes.length">
                    <div>
                        <p class="text-2xs font-semibold uppercase tracking-[0.14em] text-brand-mist">{{ __('What changed') }}</p>
                        <table class="mt-2 w-full overflow-hidden rounded-lg bg-brand-sand/30 font-mono text-xs">
                            <thead>
                                <tr class="text-left text-brand-mist">
                                    <th class="px-3 py-2 font-normal">{{ __('Field') }}</th>
                                    <th class="px-3 py-2 font-normal">{{ __('Before') }}</th>
                                    <th class="px-3 py-2 font-normal">{{ __('After') }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                <template x-for="c in entry.changes" :key="c.field">
                                    <tr class="border-t border-brand-ink/10">
                                        <td class="px-3 py-2 text-brand-ink" x-text="c.field"></td>
                                        <td class="px-3 py-2 text-red-700 line-through dark:text-red-300" x-text="c.from"></td>
                                        <td class="px-3 py-2 text-brand-forest" x-text="c.to"></td>
                                    </tr>
                                </template>
                            </tbody>
                        </table>
                    </div>
                </template>

                <div class="grid gap-3 sm:grid-cols-2" x-show="entry.before || entry.after">
                    @foreach (['before' => __('Recorded before'), 'after' => __('Recorded after')] as $side => $sideLabel)
                        <div>
                            <p class="text-2xs font-semibold uppercase tracking-[0.14em] text-brand-mist">{{ $sideLabel }}</p>
                            <pre x-show="entry.{{ $side }}" x-text="entry.{{ $side }}" class="mt-1 max-h-56 overflow-auto rounded-md border border-brand-ink/10 bg-brand-sand/20 p-2 font-mono text-xs leading-relaxed text-brand-ink"></pre>
                            <p x-show="! entry.{{ $side }}" class="mt-1 rounded-md border border-dashed border-brand-ink/10 p-2 text-xs text-brand-mist">—</p>
                        </div>
                    @endforeach
                </div>
            </div>
        </template>
    </x-modal>
</div>
