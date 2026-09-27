@props(['title' => null])

{{-- A titled group inside a sheet. --}}
<section {{ $attributes->merge(['class' => 'grid gap-2']) }}>
    @if ($title)
        <h3 class="text-2xs font-semibold uppercase tracking-[0.16em] text-brand-mist">{{ $title }}</h3>
    @endif
    {{ $slot }}
</section>
