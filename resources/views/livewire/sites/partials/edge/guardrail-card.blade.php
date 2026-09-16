@php
    $guardrail = $site->edgeGuardrail();
@endphp

<section class="border-b border-brand-ink/10 px-5 py-4 sm:px-6">
    <div class="flex flex-wrap items-baseline justify-between gap-2">
        <div>
            <p class="text-2xs font-semibold uppercase tracking-[0.16em] text-brand-mist">{{ __('Monthly quota') }}</p>
            <p class="mt-1 text-xs text-brand-moss">{{ __('Soft cap — warn at :pct%, flag at 100%.', ['pct' => $guardrail['warn_at_percent'] ?? config('edge.guardrail.warn_at_percent', 80)]) }}</p>
        </div>
        @if ($guardrail !== null)
            @php
                $state = $guardrail['state'] ?? 'ok';
                $stateBadge = match ($state) {
                    'over' => 'bg-red-100 text-red-800 dark:bg-raw-red-950/40 dark:text-red-300',
                    'warn' => 'bg-amber-100 text-amber-800 dark:bg-raw-amber-950/40 dark:text-amber-300',
                    default => 'bg-emerald-100 text-emerald-800 dark:bg-raw-emerald-950/40 dark:text-emerald-300',
                };
                $stateLabel = match ($state) {
                    'over' => __('Over'),
                    'warn' => __('Warn'),
                    default => __('OK'),
                };
            @endphp
            <span class="rounded-full px-2 py-0.5 text-2xs font-semibold uppercase tracking-wide {{ $stateBadge }}">{{ $stateLabel }}</span>
        @endif
    </div>

    @if ($guardrail === null)
        <p class="mt-3 text-sm text-brand-moss">{{ __('Not evaluated yet — checked daily.') }}</p>
    @else
        @php
            $requests = (int) ($guardrail['requests'] ?? 0);
            $bytes = (int) ($guardrail['bytes_egress'] ?? 0);
            $requestsCap = (int) ($guardrail['requests_cap'] ?? 0);
            $bytesCap = (int) ($guardrail['bytes_egress_cap'] ?? 0);
            $reqPct = (int) ($guardrail['requests_percent'] ?? 0);
            $bytesPct = (int) ($guardrail['bytes_percent'] ?? 0);
            $evaluatedAt = $guardrail['evaluated_at'] ?? null;

            $humanBytes = static function (int $b): string {
                if ($b <= 0) {
                    return '0 B';
                }
                $units = ['B', 'KB', 'MB', 'GB', 'TB'];
                $i = (int) min(count($units) - 1, floor(log($b, 1024)));

                return sprintf('%.1f %s', $b / (1024 ** $i), $units[$i]);
            };

            $barColor = static function (int $pct) use ($state): string {
                return match (true) {
                    $pct >= 100 => 'bg-red-500',
                    $state === 'warn' && $pct >= 50 => 'bg-amber-500',
                    $pct >= 50 => 'bg-amber-400',
                    default => 'bg-emerald-500',
                };
            };
        @endphp
        <div class="mt-3 grid gap-4 sm:grid-cols-2">
            <div>
                <div class="flex items-baseline justify-between gap-2">
                    <span class="text-2xs font-semibold uppercase tracking-wide text-brand-mist">{{ __('Requests') }}</span>
                    <span class="font-mono text-xs text-brand-moss">{{ $reqPct }}%</span>
                </div>
                <div class="mt-1.5 h-1.5 overflow-hidden rounded-full bg-brand-sand/80">
                    <div class="h-full rounded-full {{ $barColor($reqPct) }}" style="width: {{ max(0, min(100, $reqPct)) }}%"></div>
                </div>
                <p class="mt-1.5 text-sm tabular-nums text-brand-ink">
                    {{ number_format($requests) }}
                    <span class="text-xs text-brand-mist">/ {{ number_format($requestsCap) }}</span>
                </p>
            </div>
            <div>
                <div class="flex items-baseline justify-between gap-2">
                    <span class="text-2xs font-semibold uppercase tracking-wide text-brand-mist">{{ __('Bandwidth') }}</span>
                    <span class="font-mono text-xs text-brand-moss">{{ $bytesPct }}%</span>
                </div>
                <div class="mt-1.5 h-1.5 overflow-hidden rounded-full bg-brand-sand/80">
                    <div class="h-full rounded-full {{ $barColor($bytesPct) }}" style="width: {{ max(0, min(100, $bytesPct)) }}%"></div>
                </div>
                <p class="mt-1.5 text-sm tabular-nums text-brand-ink">
                    {{ $humanBytes($bytes) }}
                    <span class="text-xs text-brand-mist">/ {{ $humanBytes($bytesCap) }}</span>
                </p>
            </div>
        </div>
        @if ($evaluatedAt)
            <p class="mt-2 text-right text-xs text-brand-mist">{{ __('Checked :ts', ['ts' => \Illuminate\Support\Carbon::parse($evaluatedAt)->diffForHumans()]) }}</p>
        @endif
    @endif
</section>
