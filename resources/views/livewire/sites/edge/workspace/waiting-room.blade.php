<div>
    <section class="border-b border-brand-ink/10 px-5 py-4 sm:px-6">
        @include('livewire.sites.edge.workspace.partials.feature-guide', [
            'docSlug' => 'edge-waiting-room',
            'what' => __('When too many people hit a protected path at once, Edge holds the extras in a queue so the live site stays within your capacity. No separate queue domain — people wait on the URL they opened.'),
            'steps' => [
                __('Visitor opens a matching path on your Edge hostname (e.g. /checkout).'),
                __('If there is room under max active + admits/minute, Edge sets a short session cookie and lets them through to your site.'),
                __('If the room is full, Edge serves a “You’re in line” page on that same URL (HTTP 503) and auto-refreshes until a slot opens.'),
                __('After the session minutes expire, they may re-queue on the next visit.'),
            ],
            'tips' => [
                __('They wait in the browser on your Edge URL — not email, not a third-party lobby, not a different hostname.'),
                __('Protect only the hot paths (e.g. /checkout/*). Leave marketing pages out so people can still read while others queue.'),
                __('Start with a low max active, then raise once the queue drains cleanly.'),
                __('Requires Dply-hosted Edge delivery — Save republishes the host map.'),
            ],
        ])

        <div class="mb-5 rounded-2xl border border-brand-ink/10 bg-white px-4 py-4 dark:bg-zinc-900/40 sm:px-5">
            <p class="text-xs font-semibold uppercase tracking-[0.16em] text-brand-sage">{{ __('Visitor experience') }}</p>
            <ol class="mt-3 grid gap-3 sm:grid-cols-3">
                <li class="rounded-xl border border-brand-ink/10 bg-brand-sand/20 px-3 py-3">
                    <p class="text-2xs font-semibold uppercase tracking-wide text-brand-mist">{{ __('1 · Arrive') }}</p>
                    <p class="mt-1 text-sm font-medium text-brand-ink">{{ __('Same Edge URL') }}</p>
                    <p class="mt-1 text-xs leading-relaxed text-brand-moss">{{ __('They request a path you listed (or /* site-wide). No redirect to another domain.') }}</p>
                </li>
                <li class="rounded-xl border border-brand-ink/10 bg-brand-sand/20 px-3 py-3">
                    <p class="text-2xs font-semibold uppercase tracking-wide text-brand-mist">{{ __('2 · Wait (if full)') }}</p>
                    <p class="mt-1 text-sm font-medium text-brand-ink">{{ __('“You’re in line” page') }}</p>
                    <p class="mt-1 text-xs leading-relaxed text-brand-moss">{{ __('Edge HTML interstitial on that URL. Auto-refreshes every few seconds until admitted.') }}</p>
                </li>
                <li class="rounded-xl border border-brand-ink/10 bg-brand-sand/20 px-3 py-3">
                    <p class="text-2xs font-semibold uppercase tracking-wide text-brand-mist">{{ __('3 · Enter') }}</p>
                    <p class="mt-1 text-sm font-medium text-brand-ink">{{ __('Your real page') }}</p>
                    <p class="mt-1 text-xs leading-relaxed text-brand-moss">{{ __('Admitted visitors get a cookie and see the normal site for the session length.') }}</p>
                </li>
            </ol>
            <div class="mt-4 rounded-xl border border-dashed border-brand-ink/15 bg-brand-sand/15 px-4 py-3">
                <p class="text-2xs font-semibold uppercase tracking-wide text-brand-mist">{{ __('What they see while waiting') }}</p>
                <div class="mt-2 rounded-lg border border-brand-ink/10 bg-[#f6f5ef] px-4 py-6 text-center text-brand-ink shadow-sm">
                    <p class="text-base font-semibold">{{ __('You’re in line') }}</p>
                    <p class="mt-1 text-sm text-brand-moss">{{ __('This site is at capacity. We’ll refresh automatically.') }}</p>
                </div>
                <p class="mt-2 text-xs text-brand-moss">{{ __('Served by Edge (not your build). Branding customization is not available yet.') }}</p>
            </div>
        </div>

        @include('livewire.sites.edge.workspace.partials.managed-only-banner', ['managedDelivery' => $managedDelivery])

        <div class="mt-4 space-y-4">
            <div class="flex flex-wrap items-center justify-between gap-x-4 gap-y-2 rounded-xl border border-brand-ink/10 bg-white px-3 py-2.5">
                <span class="min-w-0 flex-1 basis-56">
                    <span class="block text-sm font-medium text-brand-ink">{{ __('Enable waiting room') }}</span>
                    <span class="mt-0.5 block text-xs leading-relaxed text-brand-moss">{{ __('When off, every visitor goes straight to your site.') }}</span>
                </span>
                <x-toggle-switch
                    :enabled="(bool) $enabled"
                    wire:model.live="enabled" @disabled(! $managedDelivery)
                    :on-label="__('On')"
                    :off-label="__('Off')"
                />
            </div>

            <div class="grid gap-3 sm:grid-cols-3">
                <div>
                    <x-input-label for="total_active_users" :value="__('Max active visitors')" />
                    <x-text-input id="total_active_users" wire:model="total_active_users" type="number" min="1" class="mt-1 block w-full text-sm" @disabled(! $managedDelivery) />
                    <p class="mt-1 text-xs text-brand-moss">{{ __('How many admitted browsers can browse at once.') }}</p>
                </div>
                <div>
                    <x-input-label for="new_users_per_minute" :value="__('New admits / minute')" />
                    <x-text-input id="new_users_per_minute" wire:model="new_users_per_minute" type="number" min="1" class="mt-1 block w-full text-sm" @disabled(! $managedDelivery) />
                    <p class="mt-1 text-xs text-brand-moss">{{ __('How fast the queue drains into the site.') }}</p>
                </div>
                <div>
                    <x-input-label for="session_duration_minutes" :value="__('Session (minutes)')" />
                    <x-text-input id="session_duration_minutes" wire:model="session_duration_minutes" type="number" min="1" class="mt-1 block w-full text-sm" @disabled(! $managedDelivery) />
                    <p class="mt-1 text-xs text-brand-moss">{{ __('Cookie lifetime after admit; then they may re-queue.') }}</p>
                </div>
            </div>

            <div>
                <x-input-label for="paths" :value="__('Protected paths (one per line)')" />
                <textarea id="paths" wire:model="paths" rows="3" class="mt-1 block w-full rounded-lg border border-brand-ink/15 bg-white px-3 py-2 font-mono text-sm dark:bg-zinc-900" placeholder="/checkout/*&#10;/launch" @disabled(! $managedDelivery)></textarea>
                <p class="mt-1 text-xs text-brand-moss">{{ __('Only these paths use the room. Empty defaults to /* (entire site). Examples: / · /checkout/* · /ticket/*') }}</p>
            </div>

            <div class="flex justify-end">
                <x-primary-button type="button" wire:click="save" @disabled(! $managedDelivery)>{{ __('Save') }}</x-primary-button>
            </div>
        </div>
    </section>
</div>
