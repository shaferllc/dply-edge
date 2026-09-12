@props([
    'organization',
])

@php
    // One app-wide billing state is left: a canceled subscription still inside
    // the period the customer paid for. The trial countdown and the soft/hard
    // "deploys are paused" bands went with the trial (2026-09-11) — nothing
    // enforced them and there are no agents to disconnect.
    $onGrace = $organization?->onSubscriptionGracePeriod() ?? false;
    $endsAt = $onGrace ? $organization->subscriptionEndsAt() : null;
@endphp

@if ($onGrace)
    <div class="border-b border-amber-300 bg-amber-50" role="status">
        <div class="mx-auto flex max-w-7xl flex-wrap items-center justify-between gap-x-6 gap-y-2 px-4 py-2.5 sm:px-6 lg:px-8">
            <div class="flex min-w-0 flex-1 items-start gap-2.5">
                <x-heroicon-o-clock class="mt-0.5 h-4 w-4 shrink-0 text-amber-700" aria-hidden="true" />
                <p class="min-w-0 text-sm leading-snug text-amber-900/80">
                    <span class="font-semibold text-amber-950">
                        {{ $endsAt
                            ? __('Your subscription ends :date.', ['date' => $endsAt->toFormattedDateString()])
                            : __('Your subscription is set to cancel.') }}
                    </span>
                    {{ __('You keep full access until then. Resume anytime to stay on — nothing changes.') }}
                </p>
            </div>
            <a
                href="{{ route('billing.show', $organization) }}"
                wire:navigate
                class="inline-flex shrink-0 items-center rounded-lg bg-brand-ink px-3 py-1.5 text-xs font-semibold whitespace-nowrap text-brand-cream hover:bg-brand-forest"
            >
                {{ __('Resume subscription') }}
            </a>
        </div>
    </div>
@endif
