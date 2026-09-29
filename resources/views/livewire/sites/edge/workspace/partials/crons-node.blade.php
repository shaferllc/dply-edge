{{-- Map box for scheduled tasks (crons_overrides + dply.yaml crons). The sheets belong to the Crons component beside Resources on Overview. --}}
@if (is_array($crons) && $crons['rows'] !== [])
    @php $cronDropped = collect($crons['rows'])->where('dropped', true)->count(); @endphp
    <button type="button" x-on:click="$dispatch('open-modal', 'edge-crons')" class="{{ $node }}">
        <span class="flex items-center justify-between gap-2">
            <span class="{{ $eyebrow }}">{{ __('Scheduled tasks') }}</span>
            {!! $cronDropped > 0 ? $pill(__(':count won’t run', ['count' => $cronDropped]), 'warn') : $pill(__(':used of :max slots', ['used' => $crons['used'], 'max' => $crons['max']]), 'ok') !!}
        </span>
        <span class="mt-1.5 grid gap-0.5">
            @foreach (array_slice($crons['rows'], 0, 3) as $row)
                <span class="block truncate text-xs text-brand-ink">{{ \Illuminate\Support\Str::ucfirst($row['when']) }}@if ($row['handler']) · <span class="font-mono text-brand-moss">{{ $row['handler'] }}</span>@endif</span>
            @endforeach
            @if (count($crons['rows']) > 3)
                <span class="text-2xs text-brand-mist">{{ __('+ :count more', ['count' => count($crons['rows']) - 3]) }}</span>
            @endif
        </span>
        {!! $more !!}
    </button>
@elseif (is_array($crons) && ! $hasCode)
    {{-- No Add resource here (static site with middleware), so offer the task directly. --}}
    <button type="button" x-on:click="$dispatch('edge-cron-new')" class="-mt-1 text-left text-xs font-semibold text-brand-sage hover:underline">{{ __('Add a scheduled task') }}</button>
@endif
