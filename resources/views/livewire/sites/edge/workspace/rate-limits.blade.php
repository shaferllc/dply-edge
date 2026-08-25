@php
    $turnstile = is_array($site->edgeMeta()['turnstile'] ?? null) ? $site->edgeMeta()['turnstile'] : [];
    $botProtectionReady = (bool) ($turnstile['enabled'] ?? false)
        && trim((string) ($turnstile['site_key'] ?? '')) !== ''
        && trim((string) ($turnstile['secret_key'] ?? '')) !== '';
@endphp

<div>
    <section class="border-b border-brand-ink/10 px-5 py-4 sm:px-6">
        @include('livewire.sites.edge.workspace.partials.feature-guide', [
            'docSlug' => 'edge-rate-limits',
            'what' => __('Rate limits count requests per visitor IP on matching paths. When someone exceeds the limit in the window, Edge stops them before your site or origin does the work — unlike Waiting room, this is about abusive volume from one client, not total concurrent humans.'),
            'steps' => [
                __('Add a rule: path pattern (e.g. /api/* or /forms/*), max requests, and window in seconds.'),
                __('Choose Block (plain HTTP 429) or Challenge (bot check page — needs Bot protection keys).'),
                __('Enable and Save. Rules apply on Edge after delivery republishes (usually under a minute).'),
            ],
            'setupLinks' => [
                [
                    'label' => __('Bot protection'),
                    'href' => route('sites.show', ['server' => $server, 'site' => $site, 'section' => 'edge-bot-protection']),
                ],
                [
                    'label' => __('Waiting room'),
                    'href' => route('sites.show', ['server' => $server, 'site' => $site, 'section' => 'edge-waiting-room']),
                ],
            ],
            'tips' => [
                __('Protect hot endpoints tightly (/api/login, form POSTs); avoid ultra-low limits on /* or you will throttle real browsers loading assets.'),
                __('Challenge without Bot protection falls back to Block (429).'),
                __('Requires Dply-hosted Edge delivery.'),
            ],
        ])

        <div class="mb-5 rounded-2xl border border-brand-ink/10 bg-white px-4 py-4 dark:bg-brand-ink/95/40 sm:px-5">
            <p class="text-xs font-semibold uppercase tracking-[0.16em] text-brand-sage">{{ __('What happens when a limit is hit') }}</p>
            <div class="mt-3 grid gap-3 sm:grid-cols-2">
                <div class="rounded-xl border border-brand-ink/10 bg-brand-sand/20 px-3 py-3">
                    <p class="text-2xs font-semibold uppercase tracking-wide text-brand-mist">{{ __('Block (429)') }}</p>
                    <p class="mt-1 text-sm font-medium text-brand-ink">{{ __('Plain “Too Many Requests”') }}</p>
                    <p class="mt-1 text-xs leading-relaxed text-brand-moss">{{ __('Edge returns HTTP 429 with Retry-After. Good for APIs, bots, and scrapers — no interactive page.') }}</p>
                    <div class="mt-3 rounded-lg border border-brand-ink/10 bg-brand-ink/95 px-3 py-2 font-mono text-xs text-brand-sand">
                        HTTP/1.1 429 Too Many Requests<br>
                        Retry-After: 60<br>
                        <span class="text-zinc-400">Too Many Requests</span>
                    </div>
                </div>
                <div class="rounded-xl border border-brand-ink/10 bg-brand-sand/20 px-3 py-3">
                    <p class="text-2xs font-semibold uppercase tracking-wide text-brand-mist">{{ __('Challenge') }}</p>
                    <p class="mt-1 text-sm font-medium text-brand-ink">{{ __('Bot check interstitial') }}</p>
                    <p class="mt-1 text-xs leading-relaxed text-brand-moss">{{ __('Edge serves a verify page on the same URL. Passing the check lets that request through. Needs Bot protection enabled with keys.') }}</p>
                    @if ($botProtectionReady)
                        <p class="mt-2 inline-flex items-center gap-1 text-xs font-semibold text-brand-forest">
                            <x-heroicon-m-check-circle class="h-3.5 w-3.5" aria-hidden="true" />
                            {{ __('Bot protection is ready') }}
                        </p>
                    @else
                        <p class="mt-2 text-xs font-medium text-amber-800 dark:text-amber-200">
                            {{ __('Bot protection keys missing — Challenge will act like Block until you configure them.') }}
                        </p>
                    @endif
                </div>
            </div>
            <p class="mt-3 text-xs leading-relaxed text-brand-moss">
                {{ __('Counting is per IP + path rule + window, enforced at the Edge. Waiting room caps total concurrent visitors instead — use that for launches, this for abuse.') }}
            </p>
        </div>

        @include('livewire.sites.edge.workspace.partials.managed-only-banner', ['managedDelivery' => $managedDelivery])

        <div class="mt-4 space-y-4">
            <div class="flex flex-wrap items-center justify-between gap-x-4 gap-y-2 rounded-xl border border-brand-ink/10 bg-white px-3 py-2.5">
                <span class="min-w-0 flex-1 basis-56">
                    <span class="block text-sm font-medium text-brand-ink">{{ __('Enable rate limits') }}</span>
                    <span class="mt-0.5 block text-xs leading-relaxed text-brand-moss">{{ __('When off, rules are ignored and every request passes through.') }}</span>
                </span>
                <x-toggle-switch
                    :enabled="(bool) $enabled"
                    wire:model.live="enabled" @disabled(! $managedDelivery)
                    :on-label="__('On')"
                    :off-label="__('Off')"
                />
            </div>

            <div>
                <p class="text-2xs font-semibold uppercase tracking-[0.16em] text-brand-mist">{{ __('Rules') }}</p>
                <p class="mt-1 text-xs text-brand-moss">{{ __('First matching path wins for that request. Example: 60 requests / 60 seconds on /api/* ≈ one request per second average per IP.') }}</p>
            </div>

            @foreach ($rules as $i => $rule)
                <div class="rounded-xl border border-brand-ink/10 p-3 sm:p-4" wire:key="rl-{{ $i }}">
                    <div class="mb-3 flex items-center justify-between gap-2">
                        <p class="text-xs font-semibold text-brand-ink">{{ __('Rule :n', ['n' => $i + 1]) }}</p>
                        @if (count($rules) > 1)
                            <button type="button" wire:click="removeRule({{ $i }})" class="text-xs font-semibold text-red-600 hover:underline" @disabled(! $managedDelivery)>
                                {{ __('Remove') }}
                            </button>
                        @endif
                    </div>
                    <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                        <div>
                            <x-input-label :value="__('Path pattern')" />
                            <x-text-input wire:model="rules.{{ $i }}.path" type="text" class="mt-1 block w-full font-mono text-sm" placeholder="/api/*" @disabled(! $managedDelivery) />
                            <p class="mt-1 text-xs text-brand-moss">{{ __('e.g. /api/* · /login · /*') }}</p>
                        </div>
                        <div>
                            <x-input-label :value="__('Max requests')" />
                            <x-text-input wire:model="rules.{{ $i }}.limit" type="number" min="1" class="mt-1 block w-full text-sm" @disabled(! $managedDelivery) />
                            <p class="mt-1 text-xs text-brand-moss">{{ __('Per IP in the window') }}</p>
                        </div>
                        <div>
                            <x-input-label :value="__('Window (seconds)')" />
                            <x-text-input wire:model="rules.{{ $i }}.window_seconds" type="number" min="1" class="mt-1 block w-full text-sm" @disabled(! $managedDelivery) />
                            <p class="mt-1 text-xs text-brand-moss">{{ __('Counter resets after this many seconds') }}</p>
                        </div>
                        <div>
                            <x-input-label :value="__('When exceeded')" />
                            <select wire:model="rules.{{ $i }}.action" class="mt-1 block w-full rounded-lg border border-brand-ink/15 bg-white px-3 py-2 text-sm dark:bg-brand-ink/95" @disabled(! $managedDelivery)>
                                <option value="block">{{ __('Block (429)') }}</option>
                                <option value="challenge">{{ __('Challenge (bot check)') }}</option>
                            </select>
                            <p class="mt-1 text-xs text-brand-moss">{{ __('Challenge needs Bot protection') }}</p>
                        </div>
                    </div>
                </div>
            @endforeach

            <div class="flex flex-wrap items-center justify-between gap-3 border-t border-brand-ink/10 pt-4">
                <button type="button" wire:click="addRule" class="text-sm font-semibold text-brand-sage hover:underline" @disabled(! $managedDelivery)>{{ __('Add rule') }}</button>
                <x-primary-button type="button" wire:click="save" wire:loading.attr="disabled" @disabled(! $managedDelivery)>
                    <span wire:loading.remove wire:target="save">{{ __('Save') }}</span>
                    <span wire:loading wire:target="save">{{ __('Saving…') }}</span>
                </x-primary-button>
            </div>
        </div>
    </section>
</div>
