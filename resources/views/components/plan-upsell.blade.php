@props(['organization', 'title', 'message'])
{{-- Shown where a feature needs a higher plan (subscription.standard.tiers). --}}
<div class="m-4 rounded-2xl border border-brand-ink/10 bg-brand-sand/30 px-5 py-6 text-center">
    <x-heroicon-o-lock-closed class="mx-auto h-8 w-8 text-brand-moss" aria-hidden="true" />
    <p class="mt-3 text-base font-semibold text-brand-ink">{{ $title }}</p>
    <p class="mx-auto mt-1 max-w-md text-sm text-brand-moss">{{ $message }}</p>
    @if ($organization)
        <a href="{{ route('billing.show', $organization) }}#plans" class="mt-4 inline-flex items-center gap-2 rounded-xl bg-brand-ink px-4 py-2 text-sm font-semibold text-brand-cream hover:bg-brand-forest">
            {{ __('See plans') }}
        </a>
    @endif
</div>
