@if ($connection['kind'] === 'redis' && \App\Modules\Edge\Support\EdgeValkey::isTarget($connection['target']) && $cardOnFile)
    @php
        $valkeyClass = \App\Modules\Edge\Support\EdgeValkey::spec((string) $connection['plan']);
        $valkeySleepNow = (int) ($site->edgeMeta()['valkey_sleep'][$connection['target']] ?? ($valkeyClass['sleeps'] ? \App\Modules\Edge\Support\EdgeValkey::DEFAULT_SLEEP : 0));
    @endphp
    {{-- dply Valkey opens its sheet like the Edge, App and Database boxes; Sleep and Delete live there. --}}
    <button type="button" wire:click="$set('valkeyHost', '{{ $connection['host'] }}')" wire:island="resources-valkey" x-on:click="$dispatch('open-modal', 'resources-valkey')" @class([$node, 'resource-asleep border-dashed' => $connection['asleep']])>
        <span class="flex items-center justify-between gap-2">
            <span class="flex items-center gap-1.5 {{ $eyebrow }}"><x-resource-kind-icon kind="redis" class="h-3.5 w-3.5 shrink-0" />{{ __($connectionKinds['redis']['label']) }}</span>
            {!! $connection['asleep'] ? $pill(__('Asleep'), 'sleep') : $pill(__('On'), 'ok') !!}
        </span>
        <span class="mt-1.5 block text-sm font-bold text-brand-ink">{{ __($valkeyClass['label']) }}</span>
        @if (isset($connectionEstimates[$connection['host']]))
            <span class="mt-1 flex flex-wrap gap-x-3 gap-y-0.5 font-mono text-2xs text-brand-mist">
                <span><b class="text-brand-ink">${{ \App\Modules\Edge\Support\EdgeValkey::money($connectionEstimates[$connection['host']]) }}</b> {{ __('this month') }}</span>
                <span>{{ __('cap') }} <b class="text-brand-ink">${{ number_format($valkeyClass['cap_cents'] / 100, 0) }}</b>/mo</span>
            </span>
        @endif
        @if (! $connection['asleep'])
            {!! $sleepNote($valkeyClass['sleeps'] && $valkeySleepNow > 0 ? __('Sleeps after :time idle', ['time' => __(\App\Modules\Edge\Support\EdgeValkey::SLEEPS[$valkeySleepNow] ?? '5 minutes')]) : __('Stays on')) !!}
        @endif
        {!! $more !!}
    </button>
@elseif ($connection['kind'] === 'images')
    {{-- Images opens its sheet like Valkey; removing it lives there (there is nothing to sleep). --}}
    <button type="button" wire:click="$set('imagesHost', '{{ $connection['host'] }}')" wire:island="resources-images" x-on:click="$dispatch('open-modal', 'resources-images')" class="{{ $node }}">
        <span class="flex items-center justify-between gap-2">
            <span class="flex items-center gap-1.5 {{ $eyebrow }}"><x-resource-kind-icon kind="images" class="h-3.5 w-3.5 shrink-0" />{{ __($connectionKinds['images']['label']) }}</span>
            {!! $connection['asleep'] ? $pill(__('Asleep'), 'sleep') : $pill(__('On'), 'ok') !!}
        </span>
        <span class="mt-1.5 block font-mono text-xs font-semibold text-brand-ink">{{ $isWorker ? 'env.'.$connection['name'] : $connection['host'] }}</span>
        <span class="mt-0.5 block text-2xs text-brand-moss">{{ __('Resize and convert pictures') }}</span>
        {!! $more !!}
    </button>
@elseif ($connection['kind'] === 'messages')
    {{-- Messages opens its sheet: the MESSAGES_* values, sleep and detach live there. --}}
    <button type="button" wire:click="$set('messagesHost', '{{ $connection['host'] }}')" wire:island="resources-messages" x-on:click="$dispatch('open-modal', 'resources-messages')" @class([$node, 'resource-asleep border-dashed' => $connection['asleep']])>
        <span class="flex items-center justify-between gap-2">
            <span class="flex items-center gap-1.5 {{ $eyebrow }}"><x-resource-kind-icon kind="messages" class="h-3.5 w-3.5 shrink-0" />{{ __($connectionKinds['messages']['label']) }}</span>
            {!! $connection['asleep'] ? '<span class="flex items-center">'.$pill(__('Asleep'), 'sleep').$snore.'</span>' : $pill(__('On'), 'ok') !!}
        </span>
        <span class="mt-1.5 block font-mono text-xs font-semibold text-brand-ink">MESSAGES_TOKEN</span>
        <span class="mt-0.5 block text-2xs text-brand-moss">{{ __('Delayed and scheduled HTTP messages') }}</span>
        {!! $more !!}
    </button>
@elseif ($connection['kind'] === 'vectors')
    {{-- Vector search opens its sheet like the other boxes; search, sleep, detach and delete live there. --}}
    <button type="button" wire:click="openResource('{{ $connection['host'] }}')" wire:island="resources-vectors" @class([$node, 'resource-asleep border-dashed' => $connection['asleep']])>
        <span class="flex items-center justify-between gap-2">
            <span class="flex items-center gap-1.5 {{ $eyebrow }}"><x-resource-kind-icon kind="vectors" class="h-3.5 w-3.5 shrink-0" />{{ __($connectionKinds['vectors']['label']) }}</span>
            {!! $connection['asleep'] ? '<span class="flex items-center">'.$pill(__('Asleep'), 'sleep').$snore.'</span>' : $pill(__('On'), 'ok') !!}
        </span>
        <span class="mt-1.5 block truncate text-sm font-bold text-brand-ink" title="{{ $connection['target'] }}">{{ \App\Modules\Edge\Support\EdgeContainerConnections::resourceLabel($connection['host']) }}</span>
        <span class="mt-0.5 block truncate font-mono text-2xs text-brand-moss">{{ $isWorker ? 'env.'.$connection['name'] : $connection['host'] }}</span>
        @if (isset($connectionEstimates[$connection['host']]))
            <span class="mt-1 block font-mono text-2xs text-brand-mist"><b class="text-brand-ink">${{ number_format($connectionEstimates[$connection['host']] / 100, 2) }}</b> {{ __('this month') }}</span>
        @endif
        {!! $more !!}
    </button>
@elseif ($connection['kind'] === 'key_value')
    {{-- The key-value store opens its sheet like the other boxes; keys, sleep, detach and delete live there.
         "Ready" while the app sleeps: nothing runs, only stored data bills (same for object storage). --}}
    <button type="button" wire:click="openKv('{{ $connection['host'] }}')" wire:island="resources-kv" wire:loading.attr="disabled" wire:target="openKv" @class([$node, 'resource-asleep border-dashed' => $connection['asleep']])>
        <span class="flex items-center justify-between gap-2">
            <span class="flex items-center gap-1.5 {{ $eyebrow }}"><x-resource-kind-icon kind="key_value" class="h-3.5 w-3.5 shrink-0" />{{ __($connectionKinds['key_value']['label']) }}</span>
            {!! $connection['asleep'] ? '<span class="flex items-center">'.$pill(__('Asleep'), 'sleep').$snore.'</span>' : $pill(($appAsleep ?? false) ? __('Ready') : __('On'), 'ok') !!}
        </span>
        <span class="mt-1.5 block truncate text-sm font-bold text-brand-ink">{{ \App\Modules\Edge\Support\EdgeContainerConnections::resourceLabel($connection['host']) }}</span>
        <span class="mt-0.5 block truncate font-mono text-2xs text-brand-moss">{{ $isWorker ? 'env.'.$connection['name'] : $connection['host'] }}</span>
        @if (isset($connectionEstimates[$connection['host']]))
            <span class="mt-1 block font-mono text-2xs text-brand-mist"><b class="text-brand-ink">${{ number_format($connectionEstimates[$connection['host']] / 100, 2) }}</b> {{ __('this month') }}</span>
        @endif
        {!! $more !!}
    </button>
@elseif ($connection['kind'] === 'object_storage')
    {{-- Object storage opens its sheet like the other boxes; files, sleep, detach and delete live there. --}}
    <button type="button" wire:click="openObject('{{ $connection['host'] }}')" wire:island="resources-object" x-on:click="$dispatch('open-modal', 'resources-object')" @class([$node, 'resource-asleep border-dashed' => $connection['asleep']])>
        <span class="flex items-center justify-between gap-2">
            <span class="flex items-center gap-1.5 {{ $eyebrow }}"><x-resource-kind-icon kind="object_storage" class="h-3.5 w-3.5 shrink-0" />{{ __($connectionKinds['object_storage']['label']) }}</span>
            {!! $connection['asleep'] ? '<span class="flex items-center">'.$pill(__('Asleep'), 'sleep').$snore.'</span>' : $pill(($appAsleep ?? false) ? __('Ready') : __('On'), 'ok') !!}
        </span>
        <span class="mt-1.5 block truncate text-sm font-bold text-brand-ink" title="{{ $connection['target'] }}">{{ \App\Modules\Edge\Support\EdgeContainerConnections::resourceLabel($connection['host']) }}</span>
        <span class="mt-0.5 block truncate font-mono text-2xs text-brand-moss">{{ $isWorker ? 'env.'.$connection['name'] : $connection['host'] }}</span>
        @if (isset($connectionEstimates[$connection['host']]))
            <span class="mt-1 block font-mono text-2xs text-brand-mist"><b class="text-brand-ink">${{ number_format($connectionEstimates[$connection['host']] / 100, 2) }}</b> {{ __('this month') }}</span>
        @endif
        {!! $more !!}
    </button>
@elseif ($connection['kind'] === 'realtime')
    {{-- Realtime opens its sheet like the other boxes; sleep and delete live there. The internal host means nothing here: show where browsers connect (no relay call in render). --}}
    @php $realtimeRow = $connection['target'] !== '' ? \App\Models\EdgeRealtimeApp::query()->whereKey($connection['target'])->where('organization_id', $site->organization_id)->first(['id', 'max_connections', 'hostname']) : null; @endphp
    <button type="button" wire:click="openResource('{{ $connection['host'] }}')" wire:island="resources-realtime" @class([$node, 'resource-asleep border-dashed' => $connection['asleep']])>
        <span class="flex items-center justify-between gap-2">
            <span class="flex items-center gap-1.5 {{ $eyebrow }}"><x-resource-kind-icon kind="realtime" class="h-3.5 w-3.5 shrink-0" />{{ __($connectionKinds['realtime']['label']) }}</span>
            {!! $connection['asleep'] ? '<span class="flex items-center">'.$pill(__('Asleep'), 'sleep').$snore.'</span>' : $pill(__('On'), 'ok') !!}
        </span>
        @if ($realtimeRow)
            <span class="mt-1.5 block truncate text-sm font-bold text-brand-ink">{{ __('Up to :count connections', ['count' => number_format($realtimeRow->max_connections)]) }}</span>
        @endif
        <span class="mt-0.5 block truncate font-mono text-2xs text-brand-moss">{{ \App\Modules\Edge\Services\Realtime\EdgeRealtimeApps::hostFor($realtimeRow) }}</span>
        @if (isset($connectionEstimates[$connection['host']]))
            <span class="mt-1 block font-mono text-2xs text-brand-mist"><b class="text-brand-ink">${{ number_format($connectionEstimates[$connection['host']] / 100, 2) }}</b> {{ __('this month') }}</span>
        @endif
        @if (($appAsleep ?? false) && ! $connection['asleep'])
            {!! $sleepNote(__('Stays up for connected browsers while the app sleeps')) !!}
        @endif
        {!! $more !!}
    </button>
@else
                    <div @class([
                        'rounded-2xl border p-3.5',
                        'resource-asleep border-dashed border-brand-ink/20 bg-white/70 dark:border-brand-mist/25 dark:bg-zinc-900/70' => $connection['asleep'],
                        'border-brand-ink/15 bg-white dark:border-brand-mist/20 dark:bg-zinc-900' => ! $connection['asleep'],
                    ])>
                        <div class="flex flex-col gap-2">
                            <p class="flex items-center gap-1.5 whitespace-nowrap text-2xs font-semibold uppercase tracking-[0.16em] text-brand-mist">
                                <x-resource-kind-icon :kind="$connection['kind']" class="h-3.5 w-3.5 shrink-0" />
                                {{ $connection['kind'] === 'redis' && ! \App\Modules\Edge\Support\EdgeValkey::isTarget($connection['target']) ? __('Redis') : __($connectionKinds[$connection['kind']]['label']) }}
                                @if ($connection['asleep'])
                                    <span class="font-medium text-brand-moss">{{ __('Asleep') }}</span>
                                    <span class="resource-snore" aria-hidden="true"><span>z</span><span>z</span><span>z</span></span>
                                @endif
                            </p>
                            <span class="flex flex-wrap gap-1">
                                @if ($connection['kind'] === 'service')
                                    <button type="button" wire:click="$set('explainConnectionHost', '{{ $connection['host'] }}')" wire:island="resources-service" x-on:click="$dispatch('open-modal', 'resources-service')" class="rounded-md border border-brand-ink/15 px-2 py-0.5 text-2xs font-semibold text-brand-ink hover:border-brand-ink/40 dark:border-brand-mist/25">{{ __('Open') }}</button>
                                @elseif ($connection['kind'] === 'redis' && \App\Modules\Edge\Support\EdgeValkey::isTarget($connection['target']))
                                    <button type="button" wire:click="$set('valkeyHost', '{{ $connection['host'] }}')" wire:island="resources-valkey" x-on:click="$dispatch('open-modal', 'resources-valkey')" class="rounded-md border border-brand-ink/15 px-2 py-0.5 text-2xs font-semibold text-brand-ink hover:border-brand-ink/40 dark:border-brand-mist/25">{{ __('Details') }}</button>
                                @else
                                    {{-- Every other kind: its sheet is resources-{kind}, opened by host. --}}
                                    <button type="button" wire:click="openResource('{{ $connection['host'] }}')" wire:island="resources-{{ $connection['kind'] === 'redis' ? 'redis-external' : str_replace('_', '-', $connection['kind']) }}" class="rounded-md border border-brand-ink/15 px-2 py-0.5 text-2xs font-semibold text-brand-ink hover:border-brand-ink/40 dark:border-brand-mist/25">{{ __('Open') }}</button>
                                @endif
                                @unless ($connection['kind'] === 'redis' && \App\Modules\Edge\Support\EdgeValkey::shared((string) $connection['plan']))
                                    <button type="button" wire:click="sleepConnection('{{ $connection['host'] }}', {{ $connection['asleep'] ? 'false' : 'true' }})" class="rounded-md border border-brand-ink/15 px-2 py-0.5 text-2xs font-semibold text-brand-ink hover:border-brand-ink/40 dark:border-brand-mist/25">{{ $connection['asleep'] ? __('Wake') : __('Sleep') }}</button>
                                @endunless
                                <button type="button" wire:click="askDeleteConnection('{{ $connection['host'] }}')" wire:island="resources-delete-connection" x-on:click="$dispatch('open-modal', 'resources-delete-connection')" class="rounded-md border border-brand-ink/15 px-2 py-0.5 text-2xs font-semibold text-brand-ink hover:border-brand-ink/40 dark:border-brand-mist/25">{{ __('Delete') }}</button>
                                @unless ($connection['kind'] === 'redis' && \App\Modules\Edge\Support\EdgeValkey::isTarget($connection['target']))
                                    <button type="button" wire:click="removeConnection('{{ $connection['host'] }}')" class="rounded-md border border-brand-ink/15 px-2 py-0.5 text-2xs font-semibold text-brand-ink hover:border-brand-ink/40 dark:border-brand-mist/25">{{ __('Detach') }}</button>
                                @endunless
                            </span>
                        </div>
                        @if ($connection['kind'] === 'redis' && \App\Modules\Edge\Support\EdgeValkey::isTarget($connection['target']) && ! $cardOnFile)
                            <p class="mt-1 text-xs text-brand-moss">{{ __('Add a card to keep using this Redis. It stays off the app until then.') }}</p>
                            @if ($site->organization)
                                <a href="{{ route('billing.show', $site->organization) }}" class="rounded-md border border-brand-ink/15 px-2 py-0.5 text-2xs font-semibold text-brand-ink hover:border-brand-ink/40 dark:border-brand-mist/25">{{ __('Billing') }}</a>
                            @endif
                        @elseif ($connection['kind'] === 'redis' && ! \App\Modules\Edge\Support\EdgeValkey::isTarget($connection['target']))
                            {{-- A pasted Redis: show where it points (host only; the password never reaches the page). --}}
                            @php $externalRedisHost = collect(\App\Modules\Edge\Support\EdgeContainerConnections::redisInjectionPreview($site))->firstWhere('key', 'REDIS_HOST')['value'] ?? ''; @endphp
                            @if ($externalRedisHost !== '')
                                <p class="mt-1 truncate font-mono text-xs text-brand-moss" title="{{ $externalRedisHost }}">{{ $externalRedisHost }}</p>
                            @endif
                        @elseif ($connection['kind'] !== 'redis')
                            <p class="mt-1 font-mono text-xs text-brand-moss">{{ $isWorker ? 'env.'.$connection['name'] : $connection['host'] }}</p>
                        @endif
                        @if ($isWorker && ! in_array($connection['kind'], $allowedKinds, true))
                            <p class="mt-1 text-xs font-semibold text-red-700 dark:text-red-400">{{ __('This app runs as a Worker. This resource needs a container app, so it is not attached.') }}</p>
                        @endif
                        @if ($connection['kind'] === 'redis' && \App\Modules\Edge\Support\EdgeValkey::isTarget($connection['target']))
                            @php
                                $valkeyClass = \App\Modules\Edge\Support\EdgeValkey::spec((string) $connection['plan']);
                                $valkeySleepNow = (int) ($site->edgeMeta()['valkey_sleep'][$connection['target']] ?? ($valkeyClass['sleeps'] ? \App\Modules\Edge\Support\EdgeValkey::DEFAULT_SLEEP : 0));
                            @endphp
                            {{-- Size and sleep are edited in the Valkey sheet; the map box only shows them. --}}
                            <p class="mt-1 text-xs text-brand-moss">{{ __($valkeyClass['label']) }} · {{ $valkeyClass['sleeps'] && $valkeySleepNow > 0 ? __('sleeps after :time idle', ['time' => __(\App\Modules\Edge\Support\EdgeValkey::SLEEPS[$valkeySleepNow] ?? '5 minutes')]) : __('stays on') }}</p>
                        @endif
                        @if ($connection['kind'] === 'queue' && isset($queueOwners[$connection['target']]))
                            <p class="mt-1 text-xs text-brand-moss">{{ __('Sends only. :app runs these jobs.', ['app' => $queueOwners[$connection['target']]]) }}</p>
                        @endif
                        @if (in_array($connection['name'], $overriddenByRepo, true))
                            <p class="mt-1 text-xs font-semibold text-brand-ink">{{ __('Overridden by wrangler.toml. The repo binding is used.') }}</p>
                        @endif
                        @if (isset($connectionEstimates[$connection['host']]) && $connection['kind'] === 'redis' && \App\Modules\Edge\Support\EdgeValkey::isTarget($connection['target']))
                            <p class="mt-1 text-xs font-semibold tabular-nums text-brand-ink">{{ __('$:price so far this month · up to $:cap/mo', ['price' => \App\Modules\Edge\Support\EdgeValkey::money($connectionEstimates[$connection['host']]), 'cap' => number_format($valkeyClass['cap_cents'] / 100, 0)]) }}</p>
                        @elseif (isset($connectionEstimates[$connection['host']]))
                            <p class="mt-1 text-xs font-semibold tabular-nums text-brand-ink">{{ __('Cost estimate · $:price', ['price' => number_format($connectionEstimates[$connection['host']] / 100, 2)]) }}</p>
                        @endif
                    </div>
@endif
