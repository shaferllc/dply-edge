<div
    x-on:open-add-provider-credential-modal.window="$wire.openModal($event.detail?.provider ?? null)"
>
    <x-modal
        :name="$modalName"
        :show="false"
        maxWidth="3xl"
        overlayClass="bg-brand-ink/30"
        panelClass="dply-modal-panel max-h-[min(90vh,56rem)] flex flex-col overflow-hidden"
        focusable
    >
        <div class="shrink-0 border-b border-brand-ink/10 px-6 py-5">
            <p class="text-xs font-semibold uppercase tracking-[0.18em] text-brand-sage">
                {{ $capability === 'cdn' ? __('CDN accounts') : __('Server providers') }}
            </p>
            <h2 class="mt-2 text-xl font-semibold text-brand-ink">
                {{ $providerPickerLocked ? __('Connect :provider', ['provider' => $activeProviderLabel]) : __('Connect a provider') }}
            </h2>
            <p class="mt-2 text-sm leading-6 text-brand-moss">
                @if (($capability ?? null) === 'cdn' && ($active_provider ?? '') === 'cloudflare')
                    {{ __('Paste an API token with Workers and object-storage permissions. It’s saved encrypted for this organization — you stay on this page.') }}
                @else
                    {{ __('Save an encrypted API token for this organization. We verify tokens when possible before storing them.') }}
                @endif
            </p>
        </div>

        <div class="min-h-0 flex-1 overflow-y-auto px-6 py-6 space-y-5">
            @unless ($providerPickerLocked)
                <div>
                    <x-input-label for="add_provider_credential_picker" :value="__('Provider')" />
                    <x-select id="add_provider_credential_picker" wire:model.live="active_provider" class="mt-1">
                        @foreach ($providerNav as $group)
                            <optgroup label="{{ $group['label'] }}">
                                @foreach ($group['items'] as $item)
                                    <option value="{{ $item['id'] }}">
                                        {{ $item['label'] }}@if (! empty($item['comingSoon'])) — {{ __('coming soon') }}@endif
                                    </option>
                                @endforeach
                            </optgroup>
                        @endforeach
                    </x-select>
                </div>
            @else
                <x-provider-badge :provider="$active_provider" :label="$activeProviderLabel" />
            @endunless

            @include('livewire.credentials.panel', [
                'credentials' => $credentials,
                'digitalOceanOAuthConfigured' => $digitalOceanOAuthConfigured,
            ])
        </div>

        <div class="shrink-0 flex justify-end border-t border-brand-ink/10 px-6 py-4">
            <x-secondary-button type="button" wire:click="closeModal">
                {{ __('Close') }}
            </x-secondary-button>
        </div>
    </x-modal>
</div>
