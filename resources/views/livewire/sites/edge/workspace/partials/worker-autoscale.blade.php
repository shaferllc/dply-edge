{{-- One autoscaling worker group: six hours of workers against waiting jobs, and the autoscaler's last word. --}}
@if (count($history) >= 2)
    @php
        // Six hours of the autoscaler: jobs waiting (area) and workers running (steps).
        $chartW = 240; $chartH = 44;
        $t0 = $history[0]['at']; $span = max(1, end($history)['at'] - $t0);
        $peakBacklog = max(1, max(array_column($history, 'backlog')));
        $peakWorkers = max(1, $max, max(array_column($history, 'count')));
        $x = fn ($p) => round(($p['at'] - $t0) / $span * $chartW, 1);
        $backlogPts = collect($history)->map(fn ($p) => $x($p).','.round($chartH - $p['backlog'] / $peakBacklog * ($chartH - 2), 1))->implode(' ');
        $steps = []; $prev = null;
        foreach ($history as $p) {
            $y = round($chartH - $p['count'] / $peakWorkers * ($chartH - 2), 1);
            if ($prev !== null) { $steps[] = $x($p).','.$prev; }
            $steps[] = $x($p).','.$y; $prev = $y;
        }
    @endphp
    <figure class="mt-2">
        <svg viewBox="0 0 {{ $chartW }} {{ $chartH }}" class="h-11 w-full" preserveAspectRatio="none" role="img" aria-label="{{ __('Workers and waiting jobs over the last :h hours', ['h' => max(1, (int) round($span / 3600))]) }}">
            <polygon points="0,{{ $chartH }} {{ $backlogPts }} {{ $chartW }},{{ $chartH }}" class="fill-amber-400/25" />
            <polyline points="{{ $backlogPts }}" fill="none" class="stroke-amber-500" stroke-width="1" vector-effect="non-scaling-stroke" />
            <polyline points="{{ implode(' ', $steps) }}" fill="none" class="stroke-emerald-600" stroke-width="1.5" vector-effect="non-scaling-stroke" />
        </svg>
        <figcaption class="mt-0.5 flex justify-between text-2xs text-brand-moss">
            <span>@if ($labelled)<span class="font-semibold text-brand-ink">{{ $label }}</span> · @endif<span class="text-emerald-700 dark:text-emerald-400">━</span> {{ __('workers, up to :n', ['n' => max(array_column($history, 'count'))]) }} · <span class="text-amber-600">━</span> {{ __('waiting, peak :n', ['n' => max(array_column($history, 'backlog'))]) }}</span>
            <span>{{ \Illuminate\Support\Carbon::createFromTimestamp($t0)->diffForHumans(short: true) }}</span>
        </figcaption>
    </figure>
@endif
@if (is_array($scaler))
    <p @class(['mt-1', 'text-red-700 dark:text-red-400' => $scaler['error'] ?? null, 'text-brand-moss' => ! ($scaler['error'] ?? null)])>
        {{ $labelled ? '['.$label.'] ' : '' }}{{ ($scaler['error'] ?? null)
            ? __('Autoscaler: :error', ['error' => $scaler['error']])
            : __('Autoscaler: :count running for :backlog waiting:oldest · :ago', ['count' => $scaler['count'] ?? '?', 'backlog' => $scaler['backlog'] ?? '?', 'oldest' => isset($scaler['oldest_age']) ? __(', oldest :s s', ['s' => $scaler['oldest_age']]) : '', 'ago' => \Illuminate\Support\Carbon::createFromTimestamp($scaler['at'] ?? time())->diffForHumans()]) }}
    </p>
@endif
