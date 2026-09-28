{{-- Encryption tab: who holds the org age key. The one-time identity banner
     lives in secrets.blade.php so it shows on every tab. --}}
@php
    $customerHeld = $orgKey?->identity_holder === \App\Models\OrgSecretKey::HOLDER_CUSTOMER;
    $card = fn (bool $current): string => $current
        ? 'dply-card flex flex-col gap-3 border-2 border-brand-sage p-5'
        : 'dply-card flex flex-col gap-3 p-5';
    $currentPill = 'rounded-full bg-brand-sage/15 px-2 py-0.5 text-xs font-semibold text-brand-forest';
@endphp

<div class="space-y-4">
    <p class="max-w-3xl text-sm leading-relaxed text-brand-moss">
        {{ __('Secrets you move out of an app’s plaintext environment are encrypted with your organization’s key before they’re stored. Pick who holds that key. Shared secrets aren’t affected by this choice. Changing the key doesn’t re-encrypt what’s already stored — re-move those secrets afterwards.') }}
    </p>

    <div class="grid grid-cols-1 gap-4 lg:grid-cols-2">
        {{-- dply holds the key --}}
        <article class="{{ $card(! $customerHeld) }}">
            <div class="flex items-center justify-between gap-2">
                <h2 class="text-base font-semibold text-brand-ink">{{ __('dply holds the key') }}</h2>
                @unless ($customerHeld)
                    <span class="{{ $currentPill }}">{{ __('Current') }}</span>
                @endunless
            </div>
            <p class="text-sm leading-relaxed text-brand-moss">{{ __('Nothing to manage. dply can decrypt to inject values at deploy — the same trust you give any host.') }}</p>
            <ul class="list-disc space-y-1 pl-5 text-sm text-brand-ink">
                <li>{{ __('dply can decrypt stored values') }}</li>
                <li>{{ __('No private key for you to keep safe') }}</li>
            </ul>

            @if (! $customerHeld)
                <div class="mt-auto border-t border-brand-ink/10 pt-3">
                    @if ($orgKey)
                        <p class="font-mono text-xs text-brand-moss">{{ __('Fingerprint') }} {{ $orgKey->fingerprint ?: '—' }}</p>
                        <p class="mt-1 break-all font-mono text-2xs text-brand-mist">{{ $orgKey->public_recipient }}</p>
                        @can('update', $organization)
                            <x-secondary-button class="mt-3" type="button" wire:click="confirmRotateEncryptionKey" wire:loading.attr="disabled" wire:target="confirmRotateEncryptionKey,rotateToNewCustomerHeldKey,rotateToNewDplyHeldKey">
                                {{ __('Rotate key') }}
                            </x-secondary-button>
                        @endcan
                    @else
                        <p class="text-xs text-brand-moss">{{ __('No key yet — dply creates one the first time you move a secret to the organization key.') }}</p>
                    @endif
                </div>
            @else
                @can('update', $organization)
                    <div class="mt-auto border-t border-brand-ink/10 pt-3">
                        <x-secondary-button type="button" wire:click="confirmRevertToDplyHeld" wire:loading.attr="disabled" wire:target="confirmRevertToDplyHeld,revertToDplyHeldKey">
                            {{ __('Revert to dply-managed') }}
                        </x-secondary-button>
                    </div>
                @endcan
            @endif
        </article>

        {{-- You hold the key --}}
        <article class="{{ $card($customerHeld) }}">
            <div class="flex items-center justify-between gap-2">
                <h2 class="text-base font-semibold text-brand-ink">{{ __('You hold the key') }}</h2>
                @if ($customerHeld)
                    <span class="{{ $currentPill }}">{{ __('Current') }}</span>
                @endif
            </div>
            <p class="text-sm leading-relaxed text-brand-moss">{{ __('dply stores only ciphertext and can never read values. You supply the private key when you deploy.') }}</p>
            <ul class="list-disc space-y-1 pl-5 text-sm text-brand-ink">
                <li>{{ __('Lose the key, lose the secrets') }}</li>
                <li>{{ __('Bring your own age key, or let us generate one') }}</li>
            </ul>

            <div class="mt-auto space-y-3 border-t border-brand-ink/10 pt-3">
                @if ($customerHeld)
                    <p class="font-mono text-xs text-brand-moss">{{ __('Fingerprint') }} {{ $orgKey->fingerprint ?: '—' }}</p>
                    <p class="break-all font-mono text-2xs text-brand-mist">{{ $orgKey->public_recipient }}</p>
                @endif

                @can('update', $organization)
                    @if ($customerHeld)
                        <x-secondary-button type="button" wire:click="confirmRotateEncryptionKey" wire:loading.attr="disabled" wire:target="confirmRotateEncryptionKey,rotateToNewCustomerHeldKey,rotateToNewDplyHeldKey">
                            {{ __('Rotate key') }}
                        </x-secondary-button>
                        <div>
                            <x-input-label for="recipient_input" :value="__('Replace with a different recipient')" />
                            <p class="mt-0.5 text-xs text-brand-moss">{{ __('Adopt another age1… public key you already hold. The current key is discarded; secrets stored under it stay locked to it until you re-move them.') }}</p>
                            <div class="mt-1.5 flex gap-2">
                                <x-text-input id="recipient_input" wire:model="recipient_input" class="block w-full font-mono text-sm" placeholder="age1…" />
                                <x-secondary-button type="button" wire:click="adoptRecipient" wire:loading.attr="disabled" wire:target="adoptRecipient,applyAdoptRecipient">{{ __('Adopt') }}</x-secondary-button>
                            </div>
                            <x-input-error :messages="$errors->get('recipient_input')" class="mt-1" />
                        </div>
                    @else
                        <x-primary-button type="button" wire:click="confirmPromoteToCustomerHeld" wire:loading.attr="disabled" wire:target="confirmPromoteToCustomerHeld,applyPromoteToCustomerHeld">
                            {{ __('Generate a customer-held key') }}
                        </x-primary-button>
                        <div>
                            <x-input-label for="recipient_input" :value="__('…or bring your own age recipient')" />
                            <div class="mt-1.5 flex gap-2">
                                <x-text-input id="recipient_input" wire:model="recipient_input" class="block w-full font-mono text-sm" placeholder="age1…" />
                                <x-secondary-button type="button" wire:click="adoptRecipient" wire:loading.attr="disabled" wire:target="adoptRecipient,applyAdoptRecipient">{{ __('Adopt') }}</x-secondary-button>
                            </div>
                            <x-input-error :messages="$errors->get('recipient_input')" class="mt-1" />
                        </div>
                    @endif
                @endcan
            </div>
        </article>
    </div>
</div>
