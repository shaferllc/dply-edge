{{-- Expects: $canManage, and optionally $bulkAssignUrl.

     Why this exists: the page previously opened straight onto a list with a
     one-line intro, and the single most common misunderstanding is that adding
     a channel is enough. It isn't — a channel with no subscriptions is inert,
     and nothing on the page said so. This states the two-step model once, at
     the top, so the "Not routed" markers further down have a meaning to refer
     back to. --}}
<div class="border-b border-brand-ink/10 bg-brand-cream/25">
    <div class="px-3 py-3 sm:px-4">
        <div class="flex flex-wrap items-start gap-x-6 gap-y-3">
            <div class="min-w-0 flex-1">
                {{-- Suppressed when a <details> summary already carries the title. --}}
                @unless ($hideTitle ?? false)
                    <p class="text-sm font-semibold text-brand-ink">{{ __('How notification channels work') }}</p>
                @endunless
                <p class="mt-0.5 max-w-2xl text-xs leading-relaxed text-brand-moss">
                    {{ __('A channel is a destination — a chat room, an inbox, a pager, an endpoint. Adding one does not send anything on its own: you also choose which events route to it.') }}
                </p>
            </div>
        </div>

        <ol class="mt-3 grid gap-2 sm:grid-cols-3">
            <li class="flex gap-2 rounded-lg border border-brand-ink/10 bg-white px-2.5 py-2">
                <span class="mt-px flex h-5 w-5 shrink-0 items-center justify-center rounded-md bg-brand-sand/60 font-mono text-2xs font-semibold text-brand-moss">1</span>
                <div class="min-w-0">
                    <p class="text-xs font-semibold text-brand-ink">{{ __('Add a destination') }}</p>
                    <p class="mt-0.5 text-xs leading-relaxed text-brand-moss">{{ __('Connect Slack or Discord once, or paste a webhook, address, or key.') }}</p>
                </div>
            </li>
            <li class="flex gap-2 rounded-lg border border-brand-ink/10 bg-white px-2.5 py-2">
                <span class="mt-px flex h-5 w-5 shrink-0 items-center justify-center rounded-md bg-brand-sand/60 font-mono text-2xs font-semibold text-brand-moss">2</span>
                <div class="min-w-0">
                    <p class="text-xs font-semibold text-brand-ink">{{ __('Send a test') }}</p>
                    <p class="mt-0.5 text-xs leading-relaxed text-brand-moss">{{ __('Proves the credential works now, rather than finding out during an incident.') }}</p>
                </div>
            </li>
            <li class="flex gap-2 rounded-lg border border-brand-ink/10 bg-white px-2.5 py-2">
                <span class="mt-px flex h-5 w-5 shrink-0 items-center justify-center rounded-md bg-brand-sand/60 font-mono text-2xs font-semibold text-brand-moss">3</span>
                <div class="min-w-0">
                    <p class="text-xs font-semibold text-brand-ink">{{ __('Subscribe it to events') }}</p>
                    <p class="mt-0.5 text-xs leading-relaxed text-brand-moss">
                        {{ __('From a server or site Notifications tab, or in bulk.') }}
                        @if ($canManage && ! empty($bulkAssignUrl))
                            <a href="{{ $bulkAssignUrl }}" wire:navigate class="font-semibold text-brand-sage hover:text-brand-ink">{{ __('Bulk assign') }} →</a>
                        @endif
                    </p>
                </div>
            </li>
        </ol>

        {{-- Scoping is the other thing people get wrong: they add a personal
             channel and wonder why a teammate's server never alerts them. --}}
        <p class="mt-2.5 text-xs leading-relaxed text-brand-mist">
            <span class="font-semibold text-brand-moss">{{ __('Scope:') }}</span>
            {{ __('Organization channels can be assigned by any admin here and survive people leaving. Personal channels belong to one account; team channels to one team.') }}
        </p>
    </div>
</div>
