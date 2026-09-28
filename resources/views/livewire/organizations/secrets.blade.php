{{--
  Org Secrets (redesign 2026-09-27, Secrets 2): Shared secrets | Encryption |
  External stores. `tab` is URL state; $activeTab is it normalised
  (`residency`, the old name, lands on Encryption).
--}}
@php
    $tabs = [
        'secrets' => __('Shared secrets'),
        'encryption' => __('Encryption'),
        'stores' => __('External stores'),
    ];
    $canUpdate = auth()->user()?->can('update', $organization);
@endphp

<div>
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
        <x-organization-shell
            :organization="$organization"
            section="secrets"
            :breadcrumb="[
                ['label' => __('Dashboard'), 'href' => route('dashboard'), 'icon' => 'home'],
                ['label' => $organization->name, 'href' => route('organizations.show', $organization), 'icon' => 'building-office-2'],
                ['label' => __('Secrets'), 'icon' => 'lock-closed'],
            ]"
        >
            <div class="space-y-6">
                <div class="flex flex-wrap items-end justify-between gap-4">
                    <div>
                        <h1 class="text-2xl font-semibold tracking-tight text-brand-ink">{{ __('Secrets') }}</h1>
                        <p class="mt-1 text-sm text-brand-moss">
                            @switch($activeTab)
                                @case('encryption') {{ __('Who holds the key that encrypts your apps’ secrets.') }} @break
                                @case('stores') {{ __('Reference secrets that live in your own store. The value never enters dply.') }} @break
                                @default {{ __('Store a value once, link it to any app’s environment. Values can’t be read back — only replaced.') }}
                            @endswitch
                        </p>
                    </div>
                    @if ($canUpdate && $activeTab === 'secrets')
                        <button type="button" x-on:click="$dispatch('open-modal', 'new-secret')" class="inline-flex h-9 items-center gap-1.5 rounded-lg bg-brand-ink px-3.5 text-sm font-semibold text-brand-cream transition-colors hover:bg-brand-forest">
                            <x-heroicon-o-plus class="h-4 w-4 shrink-0" aria-hidden="true" />
                            {{ __('New secret') }}
                        </button>
                    @elseif ($canUpdate && $activeTab === 'stores')
                        <button type="button" x-on:click="$dispatch('open-modal', 'add-store')" class="inline-flex h-9 items-center gap-1.5 rounded-lg bg-brand-ink px-3.5 text-sm font-semibold text-brand-cream transition-colors hover:bg-brand-forest">
                            <x-heroicon-o-plus class="h-4 w-4 shrink-0" aria-hidden="true" />
                            {{ __('Add a store') }}
                        </button>
                    @endif
                </div>

                <nav class="flex gap-1 overflow-x-auto border-b border-brand-ink/10" aria-label="{{ __('Secrets sections') }}">
                    @foreach ($tabs as $key => $tabLabel)
                        <button
                            type="button"
                            wire:click="setTab('{{ $key }}')"
                            @if ($activeTab === $key) aria-current="page" @endif
                            @class([
                                '-mb-px inline-flex items-center gap-1.5 whitespace-nowrap border-b-2 px-3 pb-3 pt-2 text-sm font-medium transition-colors',
                                'border-brand-forest text-brand-ink' => $activeTab === $key,
                                'border-transparent text-brand-moss hover:text-brand-ink' => $activeTab !== $key,
                            ])
                        >
                            {{ $tabLabel }}
                            @if ($key === 'secrets' && $vaultRows !== [])
                                <span class="rounded-full bg-brand-sand/60 px-1.5 text-2xs font-semibold tabular-nums text-brand-moss">{{ count($vaultRows) }}</span>
                            @endif
                        </button>
                    @endforeach
                </nav>

                {{-- Shown on every tab: a customer-held identity exists only in
                     this response. Losing it here loses the private key. --}}
                @if ($revealed_identity)
                    <section class="rounded-2xl border border-amber-500/40 bg-amber-500/10 p-5" role="alert">
                        <div class="flex items-start gap-3">
                            <x-heroicon-o-exclamation-triangle class="h-5 w-5 shrink-0 text-amber-700" aria-hidden="true" />
                            <div class="min-w-0 flex-1">
                                <h2 class="text-sm font-semibold text-brand-ink">{{ __('Save this identity now — it is shown once') }}</h2>
                                <p class="mt-1 text-sm text-brand-moss">{{ __('dply does NOT keep a copy. You must supply this to deploy or reveal customer-held secrets. Lose it and those secrets are unrecoverable.') }}</p>
                                <pre class="mt-3 overflow-x-auto rounded-lg border border-brand-ink/10 bg-brand-sand/40 p-3 font-mono text-xs text-brand-ink">{{ $revealed_identity }}</pre>
                                <div class="mt-3">
                                    <x-secondary-button type="button" wire:click="dismissIdentity">{{ __('I have saved it') }}</x-secondary-button>
                                </div>
                            </div>
                        </div>
                    </section>
                @endif

                @if ($errors->isNotEmpty())
                    <x-livewire-validation-errors />
                @endif

                @switch($activeTab)
                    @case('encryption')
                        @include('livewire.organizations.secrets.encryption')
                        @break
                    @case('stores')
                        @include('livewire.organizations.secrets.stores')
                        @break
                    @default
                        @include('livewire.organizations.secrets.vault')
                @endswitch
            </div>
        </x-organization-shell>
    </div>

    @if ($canUpdate)
        <x-modal name="new-secret" :show="false" maxWidth="lg" overlayClass="bg-brand-ink/30" panelClass="dply-modal-panel overflow-hidden shadow-xl" focusable>
            <form wire:submit="createVaultSecret">
                <div class="border-b border-brand-ink/10 px-5 py-4">
                    <h2 class="text-base font-semibold text-brand-ink">{{ __('New secret') }}</h2>
                    <p class="mt-1 text-sm text-brand-moss">{{ __('Values can’t be read back — only replaced. Keys may repeat; add a note so you can tell them apart.') }}</p>
                </div>
                <div class="space-y-4 px-5 py-4">
                    <div>
                        <x-input-label for="vault_key" :value="__('Key')" />
                        <x-text-input id="vault_key" wire:model="vault_key" class="mt-1 block w-full font-mono uppercase" placeholder="STRIPE_SECRET" />
                        <x-input-error :messages="$errors->get('vault_key')" class="mt-1" />
                    </div>
                    <div>
                        <x-input-label for="vault_value" :value="__('Value')" />
                        <x-text-input id="vault_value" type="password" wire:model="vault_value" class="mt-1 block w-full font-mono" autocomplete="new-password" />
                        <x-input-error :messages="$errors->get('vault_value')" class="mt-1" />
                    </div>
                    <div>
                        <x-input-label for="vault_notes" :value="__('Notes')" />
                        <x-text-input id="vault_notes" wire:model="vault_notes" class="mt-1 block w-full" placeholder="{{ __('Required when this key already exists') }}" />
                        <x-input-error :messages="$errors->get('vault_notes')" class="mt-1" />
                    </div>
                </div>
                <div class="flex justify-end gap-2 border-t border-brand-ink/10 bg-brand-sand/25 px-5 py-3">
                    <x-secondary-button type="button" x-on:click="$dispatch('close-modal', 'new-secret')">{{ __('Cancel') }}</x-secondary-button>
                    <x-primary-button type="submit" wire:loading.attr="disabled" wire:target="createVaultSecret">{{ __('Save secret') }}</x-primary-button>
                </div>
            </form>
        </x-modal>

        <x-modal name="add-store" :show="false" maxWidth="lg" overlayClass="bg-brand-ink/30" panelClass="dply-modal-panel overflow-hidden shadow-xl" focusable>
            <form wire:submit="createStore">
                <div class="border-b border-brand-ink/10 px-5 py-4">
                    <h2 class="text-base font-semibold text-brand-ink">{{ __('Add a store') }}</h2>
                    <p class="mt-1 text-sm text-brand-moss">{{ __('Connect a secret store you already run. dply keeps a reference, not the values.') }}</p>
                </div>
                <div class="grid grid-cols-1 gap-4 px-5 py-4 sm:grid-cols-2">
                    <div>
                        <x-input-label for="store_driver" :value="__('Provider')" />
                        <select id="store_driver" wire:model.live="store_driver" class="dply-input mt-1 w-full">
                            <option value="vault">{{ __('HashiCorp Vault') }}</option>
                            <option value="aws_sm">{{ __('AWS Secrets Manager') }}</option>
                            <option value="doppler">{{ __('Doppler') }}</option>
                        </select>
                    </div>
                    <div>
                        <x-input-label for="store_name" :value="__('Name')" />
                        <x-text-input id="store_name" wire:model="store_name" class="mt-1 block w-full" placeholder="{{ __('e.g. corp-vault') }}" />
                        <x-input-error :messages="$errors->get('store_name')" class="mt-1" />
                    </div>

                    @if ($store_driver === 'vault')
                        <div>
                            <x-input-label for="cfg_endpoint" :value="__('Endpoint')" />
                            <x-text-input id="cfg_endpoint" wire:model="store_form.endpoint" class="mt-1 block w-full" placeholder="https://vault.example.com" />
                        </div>
                        <div>
                            <x-input-label for="cfg_token" :value="__('Token')" />
                            <x-text-input id="cfg_token" type="password" wire:model="store_form.token" class="mt-1 block w-full" />
                        </div>
                        <div>
                            <x-input-label for="cfg_namespace" :value="__('Namespace (optional)')" />
                            <x-text-input id="cfg_namespace" wire:model="store_form.namespace" class="mt-1 block w-full" />
                        </div>
                    @elseif ($store_driver === 'aws_sm')
                        <div>
                            <x-input-label for="cfg_region" :value="__('Region')" />
                            <x-text-input id="cfg_region" wire:model="store_form.region" class="mt-1 block w-full" placeholder="us-east-1" />
                        </div>
                        <div>
                            <x-input-label for="cfg_key" :value="__('Access key (optional with an IAM role)')" />
                            <x-text-input id="cfg_key" wire:model="store_form.key" class="mt-1 block w-full" />
                        </div>
                        <div>
                            <x-input-label for="cfg_secret" :value="__('Secret key (optional)')" />
                            <x-text-input id="cfg_secret" type="password" wire:model="store_form.secret" class="mt-1 block w-full" />
                        </div>
                    @else
                        <div>
                            <x-input-label for="cfg_dtoken" :value="__('Token')" />
                            <x-text-input id="cfg_dtoken" type="password" wire:model="store_form.token" class="mt-1 block w-full" />
                        </div>
                        <div>
                            <x-input-label for="cfg_project" :value="__('Project (optional)')" />
                            <x-text-input id="cfg_project" wire:model="store_form.project" class="mt-1 block w-full" />
                        </div>
                        <div>
                            <x-input-label for="cfg_config" :value="__('Config (optional)')" />
                            <x-text-input id="cfg_config" wire:model="store_form.config" class="mt-1 block w-full" />
                        </div>
                    @endif

                    <div class="sm:col-span-2">
                        <x-input-label for="store_resolution" :value="__('Resolution')" />
                        {{-- Stored values stay `dply` / `onbox`; only the labels changed. --}}
                        <select id="store_resolution" wire:model="store_resolution" class="dply-input mt-1 w-full">
                            <option value="dply">{{ __('dply fetches at deploy') }}</option>
                            <option value="onbox">{{ __('Outside dply, dply never sees values (not supported on Edge yet)') }}</option>
                        </select>
                    </div>
                </div>
                <div class="flex justify-end gap-2 border-t border-brand-ink/10 bg-brand-sand/25 px-5 py-3">
                    <x-secondary-button type="button" x-on:click="$dispatch('close-modal', 'add-store')">{{ __('Cancel') }}</x-secondary-button>
                    <x-primary-button type="submit" wire:loading.attr="disabled" wire:target="createStore">{{ __('Add store') }}</x-primary-button>
                </div>
            </form>
        </x-modal>
    @endif

    {{-- Confirm modal must live in the Livewire view tree (not only a layout slot) so state updates and wire: targets bind reliably. --}}
    @include('livewire.partials.confirm-action-modal')
</div>
