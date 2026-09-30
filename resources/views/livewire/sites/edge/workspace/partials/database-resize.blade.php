{{-- A suggested resize (EdgeDatabaseResize) for $resizeOf, the app (its primary) or a DplyDatabase row;
     $resizeId is that row's id for the actions. Never automatic, since resizing restarts the database. --}}
@php
    $resize = \App\Modules\Edge\Support\EdgeDatabaseResize::suggestion($resizeOf);
    $resizeScheduled = ($resizeOf instanceof \App\Models\Site ? ($resizeOf->edgeMeta()['database'] ?? []) : \App\Modules\Edge\Services\DplyDatabases::record($resizeOf))['resize_scheduled'] ?? null;
    $sizeLabel = fn (string $key): string => isset(\App\Modules\Edge\Services\EdgeAppDatabase::POSTGRES_SIZES[$key]) ? \App\Modules\Edge\Services\EdgeAppDatabase::POSTGRES_SIZES[$key]['cpu'].' · '.\App\Modules\Edge\Services\EdgeAppDatabase::POSTGRES_SIZES[$key]['memory'] : $key;
    $canResize = auth()->user()?->can('update', $site) ?? false;
    $arg = $resizeId === null ? '' : \Illuminate\Support\Js::from($resizeId);
@endphp
@if (is_array($resizeScheduled))
    <x-sheet.note tone="warn">
        {{ __('Resizing to :size at :time. The database restarts then; open connections drop once.', ['size' => $sizeLabel((string) $resizeScheduled['size']), 'time' => \Illuminate\Support\Carbon::createFromTimestamp((int) $resizeScheduled['at'], $site->organization?->timezone ?: 'UTC')->format('D H:i T')]) }}
        @if ($canResize)
            <button type="button" wire:click="cancelDatabaseResize({{ $arg }})" class="ms-2 font-semibold underline">{{ __('Cancel') }}</button>
        @endif
    </x-sheet.note>
@elseif ($resize)
    <div class="grid gap-2 rounded-xl border border-brand-forest/40 bg-brand-forest/5 p-3.5" data-resize-suggestion>
        <p class="text-2xs font-semibold uppercase tracking-[0.16em] text-brand-mist">{{ $resize['direction'] === 'up' ? __('Suggested: a bigger size') : __('Suggested: a smaller size') }}</p>
        <p class="text-sm font-semibold text-brand-ink">{{ $sizeLabel($resize['from']) }} → {{ $sizeLabel($resize['size']) }}
            @isset($postgresSizes[$resize['size']]['month'], $postgresSizes[$resize['from']]['month'])
                <span class="font-normal text-brand-moss">· {{ __('$:from → $:to/mo', ['from' => $postgresSizes[$resize['from']]['month'], 'to' => $postgresSizes[$resize['size']]['month']]) }}</span>
            @endisset
        </p>
        <p class="text-xs text-brand-moss">{{ $resize['reason'] }}</p>
        <p class="text-2xs text-brand-mist">{{ __('Resizing restarts the database: open connections drop for a few seconds, and it starts at the new size on the next connection.') }}</p>
        @if ($canResize)
            <div class="flex flex-wrap items-center gap-2">
                <x-sheet.button variant="primary" wire:click="resizeDatabaseNow({{ $arg }})" wire:loading.attr="disabled" wire:target="resizeDatabaseNow">{{ __('Resize now') }}</x-sheet.button>
                <x-sheet.button wire:click="resizeDatabaseTonight({{ $arg }})">{{ __('Resize tonight (:time)', ['time' => \App\Modules\Edge\Support\EdgeDatabaseResize::tonight($resizeOf)->format('H:i T')]) }}</x-sheet.button>
                <button type="button" wire:click="dismissDatabaseResize({{ $arg }})" class="text-xs font-semibold text-brand-moss hover:text-brand-ink">{{ __('Dismiss for :days days', ['days' => \App\Modules\Edge\Support\EdgeDatabaseResize::DISMISS_DAYS]) }}</button>
            </div>
        @endif
    </div>
@endif
