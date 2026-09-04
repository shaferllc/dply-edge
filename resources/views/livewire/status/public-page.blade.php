@php
    $title = $statusPage->name.' · ' . config('app.name');
@endphp
<div>
    <div class="border-b border-edge-line bg-edge-panel">
        <div class="max-w-3xl mx-auto px-4 py-8">
            <div class="flex items-center gap-3 mb-2">
                <x-dply-wordmark class="text-sm text-edge-text" />
                <div>
                    <h1 class="text-xl font-semibold text-edge-text">{{ $statusPage->name }}</h1>
                    @if ($statusPage->description)
                        <p class="text-sm text-edge-mute mt-0.5">{{ $statusPage->description }}</p>
                    @endif
                </div>
            </div>
        </div>
    </div>

    <div class="max-w-3xl mx-auto px-4 py-8 space-y-8">
        @if ($banner === 'operational')
            <div class="border border-edge-lime/40 bg-edge-lime/10 px-4 py-3 text-edge-lime text-sm font-medium">
                {{ __('All systems operational') }}
            </div>
        @elseif ($banner === 'degraded')
            <div class="border border-amber-200 bg-amber-50 px-4 py-3 text-amber-900 text-sm font-medium">
                {{ __('Partial service degradation') }}
            </div>
        @elseif ($banner === 'outage')
            <div class="border border-rose-500/40 bg-rose-500/10 px-4 py-3 text-rose-300 text-sm font-medium">
                {{ __('Service disruption') }}
            </div>
        @elseif (str_starts_with($banner, 'incident'))
            <div class="border border-rose-500/40 bg-rose-500/10 px-4 py-3 text-rose-300 text-sm font-medium">
                {{ __('Active incidents') }}
            </div>
        @endif

        <section>
            <h2 class="text-sm font-semibold text-edge-text uppercase tracking-wide mb-3">{{ __('Components') }}</h2>
            <ul class="border border-edge-line bg-edge-panel divide-y divide-edge-line">
                @forelse ($rows as $row)
                    @php
                        $st = $row['state'];
                        $dot = match ($st) {
                            \App\Services\Status\MonitorOperationalState::OPERATIONAL => 'bg-edge-lime',
                            \App\Services\Status\MonitorOperationalState::DEGRADED => 'bg-amber-400',
                            \App\Services\Status\MonitorOperationalState::OUTAGE => 'bg-rose-400',
                            default => 'bg-edge-mute',
                        };
                    @endphp
                    <li class="flex items-center justify-between gap-3 px-4 py-3">
                        <span class="font-medium text-edge-text">{{ $row['label'] }}</span>
                        <span class="inline-flex items-center gap-2 text-sm text-edge-mute">
                            <span class="h-2 w-2 rounded-full {{ $dot }}" aria-hidden="true"></span>
                            {{ $resolver->label($st) }}
                        </span>
                    </li>
                @empty
                    <li class="px-4 py-6 text-sm text-edge-mute text-center">{{ __('No monitors configured yet.') }}</li>
                @endforelse
            </ul>
        </section>

        @if ($statusPage->incidents->isNotEmpty())
            <section>
                <h2 class="text-sm font-semibold text-edge-text uppercase tracking-wide mb-3">{{ __('Incidents') }}</h2>
                <div class="space-y-6">
                    @foreach ($statusPage->incidents as $incident)
                        <article class="border border-edge-line bg-edge-panel p-4">
                            <header class="mb-3">
                                <h3 class="font-semibold text-edge-text">{{ $incident->title }}</h3>
                                <p class="text-xs text-edge-mute mt-1">
                                    {{ $incident->started_at->toDayDateTimeString() }}
                                    · {{ ucfirst($incident->impact) }}
                                    · {{ ucfirst(str_replace('_', ' ', $incident->state)) }}
                                    @if ($incident->resolved_at)
                                        · {{ __('Resolved :t', ['t' => $incident->resolved_at->toDayDateTimeString()]) }}
                                    @endif
                                </p>
                            </header>
                            <ul class="space-y-3 text-sm text-edge-text">
                                @foreach ($incident->incidentUpdates as $u)
                                    <li class="border-l-2 border-edge-line pl-3">
                                        <span class="text-xs text-edge-mute">{{ $u->created_at->toDayDateTimeString() }}</span>
                                        <p class="whitespace-pre-wrap mt-0.5">{{ $u->body }}</p>
                                    </li>
                                @endforeach
                            </ul>
                        </article>
                    @endforeach
                </div>
            </section>
        @endif

        <p class="text-center text-xs text-edge-mute pb-8">
            {{ __('Powered by') }} <span class="font-medium text-edge-text">{{ config('app.name') }}</span>
        </p>
    </div>
</div>
