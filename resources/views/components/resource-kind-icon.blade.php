@props(['kind'])

@php
    $icon = match ($kind) {
        'database' => 'heroicon-o-circle-stack',
        'sql' => 'heroicon-o-table-cells',
        'database_pool' => 'heroicon-o-server-stack',
        'key_value' => 'heroicon-o-key',
        'redis' => 'heroicon-o-bolt',
        'durable_object' => 'heroicon-o-cube',
        'object_storage' => 'heroicon-o-archive-box',
        'queue' =>'heroicon-o-queue-list',
        'ai' => 'heroicon-o-sparkles',
        'vectors' => 'heroicon-o-magnifying-glass',
        'images' => 'heroicon-o-photo',
        'workflow' => 'heroicon-o-arrow-path',
        'service' => 'heroicon-o-squares-2x2',
        'realtime' => 'heroicon-o-signal',
        'messages' => 'heroicon-o-paper-airplane',
        default => null,
    };
    $class = $attributes->get('class') ?: 'h-4 w-4 shrink-0';
@endphp

@if ($icon)
    <x-dynamic-component :component="$icon" :class="$class" aria-hidden="true" />
@endif
