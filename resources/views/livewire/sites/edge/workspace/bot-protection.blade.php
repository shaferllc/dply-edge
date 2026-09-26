<div>
    <section class="border-b border-brand-ink/10 px-5 py-4 sm:px-6">
        @include('livewire.sites.edge.workspace.partials.feature-guide', [
            'docSlug' => 'edge-bot-protection',
            'what' => __('Bot protection uses a privacy-friendly challenge widget so bots can’t submit forms (or browse pages) as easily as real people.'),
            'steps' => [
                __('Generate keys for this site (recommended), or paste a site key and secret from your challenge provider.'),
                __('Pick Forms only (recommended) or All HTML pages, enable, then Save if you edited keys manually.'),
                __('Dply republishes delivery — the widget appears on matching traffic within about a minute.'),
            ],
            'setupLinks' => $canGenerateKeys ? [] : [
                [
                    'label' => __('Challenge provider console'),
                    'href' => 'https://dash.cloudflare.com/?to=/:account/turnstile',
                    'external' => true,
                ],
            ],
            'tips' => [
                __('Site key = public (safe in HTML). Secret key = server-only — never commit it to your frontend repo.'),
                __('Forms only protects POST / form surfaces; use All HTML pages only for site-wide challenges.'),
                __('Pair with Forms → “Require bot check” so Edge form endpoints reject submissions without a valid token.'),
                __('Requires Dply-hosted Edge delivery.'),
            ],
        ])

        @include('livewire.sites.edge.workspace.partials.managed-only-banner', ['managedDelivery' => $managedDelivery])

        <div class="mt-4 space-y-4" @disabled(! $managedDelivery)>
            <div class="flex flex-wrap items-center justify-between gap-x-4 gap-y-2 rounded-xl border border-brand-ink/10 bg-white px-3 py-2.5">
                <span class="min-w-0 flex-1 basis-56">
                    <span class="block text-sm font-medium text-brand-ink">{{ __('Enable bot protection') }}</span>
                    <span class="mt-0.5 block text-xs leading-relaxed text-brand-moss">{{ __('When on, Edge injects the challenge on the mode you select below.') }}</span>
                </span>
                <x-toggle-switch
                    :enabled="(bool) $enabled"
                    wire:model.live="enabled" :disabled="! $managedDelivery"
                    :on-label="__('On')"
                    :off-label="__('Off')"
                />
            </div>

            <div>
                <x-input-label for="mode" :value="__('Where to challenge')" />
                <select id="mode" wire:model="mode" class="mt-1 block w-full rounded-lg border border-brand-ink/15 bg-white px-3 py-2 text-sm dark:bg-zinc-900" @disabled(! $managedDelivery)>
                    <option value="forms">{{ __('Forms only — contact and signup POSTs') }}</option>
                    <option value="all">{{ __('All HTML pages — every document response') }}</option>
                </select>
                <p class="mt-1 text-xs text-brand-moss">{{ __('Start with Forms only unless you’re under heavy automated browsing.') }}</p>
            </div>

            @if ($canGenerateKeys)
                <div class="rounded-lg border border-brand-ink/10 bg-brand-sand/20 px-4 py-3 dark:bg-zinc-900/50">
                    <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                        <div class="min-w-0">
                            <p class="text-sm font-medium text-brand-ink">{{ __('Generate keys') }}</p>
                            <p class="mt-0.5 text-xs text-brand-moss">{{ __('Create a challenge widget for this site’s Edge hostnames and fill the keys below. Keys are saved and delivery is republished automatically.') }}</p>
                        </div>
                        <x-secondary-button
                            type="button"
                            class="shrink-0"
                            wire:click="requestGenerateKeys"
                            wire:loading.attr="disabled"
                            :disabled="! $managedDelivery"
                        >
                            <span wire:loading.remove wire:target="requestGenerateKeys,generateKeys">{{ __('Generate keys') }}</span>
                            <span wire:loading wire:target="requestGenerateKeys,generateKeys">{{ __('Generating…') }}</span>
                        </x-secondary-button>
                    </div>
                </div>
            @endif

            <div>
                <x-input-label for="site_key" :value="__('Site key (public)')" />
                <x-text-input id="site_key" wire:model="site_key" type="text" class="mt-1 block w-full font-mono text-sm" placeholder="0x4AAAA…" autocomplete="off" :disabled="! $managedDelivery" />
                <p class="mt-1 text-xs text-brand-moss">
                    @if ($canGenerateKeys)
                        {{ __('Filled by Generate keys, or paste a public site key. Safe to expose in HTML.') }}
                    @else
                        {{ __('Paste the public site key from your challenge provider. Safe to expose in HTML.') }}
                        <a href="https://dash.cloudflare.com/?to=/:account/turnstile" target="_blank" rel="noopener noreferrer" class="font-medium text-brand-sage underline-offset-2 hover:underline">{{ __('Get keys') }}</a>
                    @endif
                </p>
                <x-input-error :messages="$errors->get('site_key')" class="mt-2" />
            </div>

            <div>
                <x-input-label for="secret_key" :value="__('Secret key')" />
                <x-text-input id="secret_key" wire:model="secret_key" type="password" class="mt-1 block w-full font-mono text-sm" autocomplete="new-password" :disabled="! $managedDelivery" />
                <p class="mt-1 text-xs text-brand-moss">{{ __('Used only on Dply-hosted Edge to verify tokens — never put this in your frontend repo.') }}</p>
                <x-input-error :messages="$errors->get('secret_key')" class="mt-2" />
            </div>

            <div class="flex justify-end">
                <x-primary-button type="button" wire:click="save" wire:loading.attr="disabled" :disabled="! $managedDelivery">
                    <span wire:loading.remove wire:target="save">{{ __('Save') }}</span>
                    <span wire:loading wire:target="save">{{ __('Saving…') }}</span>
                </x-primary-button>
            </div>
        </div>
    </section>

    @include('livewire.partials.confirm-action-modal')
</div>
