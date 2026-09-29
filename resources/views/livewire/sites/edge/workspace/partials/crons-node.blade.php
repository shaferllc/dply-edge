{{-- Map box for scheduled tasks (crons_overrides + dply.yaml crons) and the Laravel scheduler when it runs in the app
     (with queue workers it runs in worker 0 and the workers box says so). The sheets belong to the Crons component. --}}
@php $cronScheduler = is_array($crons) && $crons['scheduler'] && ! $crons['schedulerInWorker']; @endphp
@if (is_array($crons) && ($crons['rows'] !== [] || $cronScheduler))
    @php
        $cronDropped = collect($crons['rows'])->where('dropped', true)->count();
        // Everything here runs inside the app, so it sleeps with it until something is due.
        $cronsAsleep = $appAsleep ?? false;
        $cronCount = $crons['container'] ? trans_choice(':count task|:count tasks', $crons['used']) : __(':used of :max slots', ['used' => $crons['used'], 'max' => $crons['max']]);
    @endphp
    <button type="button" x-on:click="$dispatch('open-modal', 'edge-crons')" @class([$node, 'resource-asleep border-dashed' => $cronsAsleep])>
        <span class="flex items-center justify-between gap-2">
            <span class="{{ $eyebrow }}">{{ __('Scheduled tasks') }}</span>
            @if ($cronDropped > 0)
                {!! $pill(__(':count won’t run', ['count' => $cronDropped]), 'warn') !!}
            @elseif ($cronsAsleep)
                <span class="flex items-center">{!! $pill(__('Asleep'), 'off') !!}{!! $snore !!}</span>
            @else
                {!! $pill($crons['rows'] === [] ? __('Running') : $cronCount, 'ok') !!}
            @endif
        </span>
        <span class="mt-1.5 grid gap-0.5">
            @if ($cronScheduler)
                <span class="block truncate text-xs text-brand-ink">{{ __('Every minute') }} · <span class="font-mono text-brand-moss">schedule:run</span> <span class="text-brand-mist">{{ __('(Laravel scheduler)') }}</span></span>
            @endif
            @foreach (array_slice($crons['rows'], 0, $cronScheduler ? 2 : 3) as $row)
                <span class="block truncate text-xs text-brand-ink">{{ \Illuminate\Support\Str::ucfirst($row['when']) }}@if ($row['handler']) · <span class="font-mono text-brand-moss">{{ $row['handler'] }}</span>@endif</span>
            @endforeach
            @if (count($crons['rows']) > ($cronScheduler ? 2 : 3))
                <span class="text-2xs text-brand-mist">{{ __('+ :count more', ['count' => count($crons['rows']) - ($cronScheduler ? 2 : 3)]) }}</span>
            @endif
            @if ($cronsAsleep)
                <span class="mt-0.5 block text-2xs text-brand-moss">{{ __('Wakes the app when a task is due.') }}</span>
            @endif
        </span>
        {!! $more !!}
    </button>
@elseif (is_array($crons) && ! $hasCode)
    {{-- No Add resource here (static site with middleware), so offer the task directly. --}}
    <button type="button" x-on:click="$dispatch('edge-cron-new')" class="mt-2 text-left text-xs font-semibold text-brand-sage hover:underline">{{ __('Add a scheduled task') }}</button>
@endif
