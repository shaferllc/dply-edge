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
        <span class="mt-1.5 block text-sm font-bold text-brand-ink">{{ trans_choice(':count process|:count processes', $w['processes'], ['count' => $w['processes']]) }} · <span class="font-mono font-normal text-brand-moss">{{ $w['queues'] }}</span></span>
        <span class="mt-1 block text-xs text-brand-moss">
            {{ $w['autoscale'] ? __('Autoscales to :count instances', ['count' => $w['max_instances']]) : trans_choice(':count instance|:count instances', $w['instances'], ['count' => $w['instances']]) }}
            · {{ $scheduler ? __('scheduler on') : __('scheduler off') }}
        </span>
    @else
        <span class="mt-1.5 block text-sm font-bold text-brand-ink">{{ __('schedule:run every minute') }}</span>
        @if ($schedulerAsleep)
            <span class="mt-1 block text-xs text-brand-moss">{{ __('Wakes the app when one of its tasks is due.') }}</span>
        @endif
    @endif
    {!! $more !!}
</button>
