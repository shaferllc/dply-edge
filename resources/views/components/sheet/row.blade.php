@props(['title', 'hint' => null])

{{-- Optional <x-slot:icon>. Opens a deeper sheet (x-on:click="$dispatch('open-modal', …)") or, with href, another page. --}}
@php $tag = $attributes->has('href') ? 'a' : 'button'; @endphp
<{{ $tag }} @if ($tag === 'button') type="button" @endif {{ $attributes->merge(['class' => 'group flex w-full min-w-0 items-center justify-between gap-3 rounded-xl border border-brand-ink/10 bg-brand-sand/20 px-3.5 py-2.5 text-left transition hover:border-brand-ink/30 dark:border-brand-mist/20 dark:bg-zinc-800/40']) }}>
    @isset($icon)
        <span class="grid h-8 w-8 shrink-0 place-items-center rounded-lg bg-white text-brand-forest ring-1 ring-brand-ink/10 dark:bg-zinc-900 dark:text-brand-sage dark:ring-brand-mist/20 [&>svg]:h-4 [&>svg]:w-4" aria-hidden="true">{{ $icon }}</span>
    @endisset
    <span class="min-w-0 flex-1">
        <span class="block text-sm font-semibold text-brand-ink">{{ $title }}</span>
        @if ($hint)
            <span class="block truncate text-2xs text-brand-mist">{{ $hint }}</span>
        @endif
    </span>
    <span class="text-lg leading-none text-brand-mist transition group-hover:translate-x-0.5 group-hover:text-brand-ink" aria-hidden="true">›</span>
</{{ $tag }}>
