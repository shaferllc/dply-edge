@props([
    /** @var array<int, array{label: string, command: string}>|null */
    'commands' => null,
    /** @var string|null Single command — rendered as one row of the shared disclosure. */
    'command' => null,
    /** @var string|null Override the default summary/heading. */
    'summary' => null,
    /** @var 'details'|'footer'|'stub'|null `footer` is an alias for `details`. */
    'tone' => null,
    /** @var 'xs'|'10' Font-size class for snippet rows. */
    'size' => 'xs',
    /** @var string|null Optional intro paragraph above the list. */
    'intro' => null,
])

@php
    $rows = collect($commands ?? [])
        ->filter(fn ($entry): bool => is_array($entry) && isset($entry['command']) && trim((string) $entry['command']) !== '')
        ->values()
        ->all();

    if ($rows === [] && is_string($command) && trim($command) !== '') {
        $rows = [['command' => $command]];
    }

    $resolvedTone = $tone === 'stub' || $rows === [] ? 'stub' : 'details';
    $rowSizeClass = $size === '10' ? 'text-2xs' : 'text-xs';
    $detailsSummary = $summary ?? __('CLI commands');
    $stubMessage = __('CLI commands for this section are coming soon.');
@endphp

@if ($resolvedTone === 'details')
    {{-- Flush disclosure — no nested rounded card (merged chrome footers).
         wire:ignore.self keeps the browser `open` attribute across Livewire
         polls (log viewer, etc.); without it the disclosure snaps shut. --}}
    <details
        {{ $attributes->class(['text-xs text-brand-moss']) }}
        data-cli-snippet="details"
        wire:ignore.self
    >
        <summary class="inline-flex cursor-pointer select-none items-center gap-1 font-semibold text-brand-ink marker:content-none [&::-webkit-details-marker]:hidden">
            <x-heroicon-o-chevron-right class="h-3.5 w-3.5 shrink-0 text-brand-sage transition-transform [details[open]>summary_&]:rotate-90" aria-hidden="true" />
            {{ $detailsSummary }}
        </summary>
        @if ($intro)
            <p class="mt-2 text-brand-moss">{{ $intro }}</p>
        @endif
        <ul class="mt-2 space-y-1.5 font-mono {{ $rowSizeClass }}">
            @foreach ($rows as $row)
                <li x-data="{ copied: false }" class="min-w-0">
                    @if (! empty($row['label']))
                        <span class="font-sans text-brand-moss">{{ $row['label'] }}</span>
                    @endif
                    <div class="mt-0.5 flex min-w-0 items-start gap-1.5">
                        <code class="min-w-0 flex-1 select-all break-all rounded-md bg-brand-ink/95 px-2 py-1 text-brand-sand ring-1 ring-inset ring-brand-ink/20"><span class="select-none text-brand-sage">$ </span>{{ $row['command'] }}</code>
                        <button
                            type="button"
                            class="mt-0.5 inline-flex shrink-0 items-center justify-center rounded p-1 text-brand-mist hover:bg-brand-sand hover:text-brand-ink"
                            title="{{ __('Copy command') }}"
                            aria-label="{{ __('Copy command') }}"
                            @click="navigator.clipboard.writeText(@js($row['command'])); copied = true; setTimeout(() => copied = false, 1500)"
                        >
                            <x-heroicon-o-clipboard class="h-3.5 w-3.5" x-show="!copied" />
                            <x-heroicon-o-check class="h-3.5 w-3.5 text-emerald-700" x-show="copied" x-cloak />
                        </button>
                    </div>
                </li>
            @endforeach
        </ul>
    </details>
@else
    <details
        {{ $attributes->class(['text-xs text-brand-moss']) }}
        data-cli-snippet="stub"
        wire:ignore.self
    >
        <summary class="inline-flex cursor-pointer select-none items-center gap-1 font-semibold text-brand-ink marker:content-none [&::-webkit-details-marker]:hidden">
            <x-heroicon-o-chevron-right class="h-3.5 w-3.5 shrink-0 text-brand-sage transition-transform [details[open]>summary_&]:rotate-90" aria-hidden="true" />
            {{ $detailsSummary }}
        </summary>
        <p class="mt-2 text-brand-moss">{{ $summary === null ? $stubMessage : ($intro ?? $stubMessage) }}</p>
    </details>
@endif
