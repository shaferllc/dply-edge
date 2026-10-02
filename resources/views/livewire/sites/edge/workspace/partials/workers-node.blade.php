{{-- Map box for queue workers and the scheduler; the controls live in the resources-workers sheet. --}}
{{-- Scheduler only: it runs inside the app, so it sleeps with it until a task is due. Workers are their own containers. --}}
@php $schedulerAsleep = ! ($workers['enabled'] ?? false) && ($appAsleep ?? false); @endphp
<button type="button" wire:click="$refresh" wire:island="resources-workers" x-on:click="$dispatch('open-modal', 'resources-workers')" @class([$node, 'resource-asleep border-dashed' => $schedulerAsleep])>
    <span class="flex items-center justify-between gap-2">
        <span class="{{ $eyebrow }}">{{ ($workers['enabled'] ?? false) ? __('Queue workers') : __('Scheduler') }}</span>
        @if ($schedulerAsleep)
            <span class="flex items-center">{!! $pill(__('Asleep'), 'off') !!}{!! $snore !!}</span>
        @elseif (($workers['enabled'] ?? false) && $w['paused'])
            {!! $pill(__('Paused'), 'warn') !!}
        @elseif (($workers['enabled'] ?? false) && $workersUnavailable)
            {!! $pill(__('Needs setup'), 'warn') !!}
        @else
            {!! $pill(__('Running'), 'ok') !!}
        @endif
    </span>
    @if ($workers['enabled'] ?? false)
        @php
            $queueNames = array_values(array_filter(array_map('trim', explode(',', $w['queues']))));
            $instancesText = $w['autoscale'] && $w['instances'] !== $w['max_instances']
                ? __(':min–:max instances', ['min' => $w['instances'], 'max' => $w['max_instances']])
                : trans_choice(':count instance|:count instances', $w['max_instances']);
        @endphp
        <span class="mt-1.5 block text-sm font-bold text-brand-ink">
            {{ trans_choice(':count process|:count processes', $w['processes']) }}
            <span class="font-normal text-brand-moss">× {{ $instancesText }}</span>
        </span>
        {{-- Queues in priority order: workers drain the first before the next. --}}
        <span class="mt-2 flex flex-wrap gap-1" title="{{ __('Queues, highest priority first') }}">
            @foreach (array_slice($queueNames, 0, 4) as $queue)
                <span class="max-w-full truncate rounded-md border border-brand-ink/10 bg-brand-sand/50 px-1.5 py-0.5 font-mono text-2xs text-brand-ink dark:border-white/10 dark:bg-white/10">{{ $queue }}</span>
            @endforeach
            @if (count($queueNames) > 4)
                <span class="rounded-md px-1 py-0.5 text-2xs font-semibold text-brand-mist" title="{{ implode(', ', array_slice($queueNames, 4)) }}">+{{ count($queueNames) - 4 }}</span>
            @endif
        </span>
        <span class="mt-2 block text-2xs text-brand-moss">
            {{ $w['autoscale'] ? __('Autoscaling') : __('Fixed size') }}
            · {{ $scheduler ? __('scheduler on') : __('scheduler off') }}
            @if ($w['groups'] !== [])
                · {{ trans_choice('+:count group|+:count groups', count($w['groups'])) }}
            @endif
        </span>
    @else
        <span class="mt-1.5 block text-sm font-bold text-brand-ink">{{ __('schedule:run every minute') }}</span>
        @if ($schedulerAsleep)
            <span class="mt-1 block text-xs text-brand-moss">{{ __('Wakes the app when one of its tasks is due.') }}</span>
        @endif
    @endif
    {!! $more !!}
</button>
