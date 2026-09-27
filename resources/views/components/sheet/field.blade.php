@props(['label' => null, 'for' => null, 'help' => null])

{{-- Label, control, help text. Controls use .dply-input (or x-sheet.option / x-sheet.segmented). --}}
<div {{ $attributes->merge(['class' => 'grid gap-1.5']) }}>
    @if ($label)
        <label @if ($for) for="{{ $for }}" @endif class="text-xs font-semibold text-brand-ink">{{ $label }}</label>
    @endif
    {{ $slot }}
    @if ($help)
        <p class="text-2xs leading-4 text-brand-mist">{{ $help }}</p>
    @endif
</div>
