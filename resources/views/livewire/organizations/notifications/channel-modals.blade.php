{{-- Create + edit destination modals. Field sets come from the shared
     notification-channel-fields partial (same as the personal/team pages). --}}
@if ($canManage && count($types) > 0)
    <x-modal
        name="settings-create-channel-modal"
        :show="false"
        maxWidth="2xl"
        overlayClass="bg-brand-ink/30"
        panelClass="dply-modal-panel overflow-hidden shadow-xl flex max-h-[min(90vh,880px)] flex-col"
        focusable
    >
        <form wire:submit="createChannel" class="flex min-h-0 flex-1 flex-col">
            <div class="shrink-0 border-b border-brand-ink/10 px-6 py-5">
                <p class="text-2xs font-semibold uppercase tracking-[0.14em] text-brand-mist">{{ __('New destination') }}</p>
                <h2 class="mt-1 text-lg font-semibold text-brand-ink">{{ __('Add destination') }}</h2>
                <p class="mt-1 text-sm text-brand-moss">{{ __('Shared by the whole organization. Credentials are stored encrypted.') }}</p>
            </div>

            <div class="min-h-0 flex-1 space-y-5 overflow-y-auto px-6 py-6">
                <div class="grid gap-5 sm:grid-cols-2">
                    <div>
                        <x-input-label for="new_type_modal" :value="__('Type')" />
                        <x-select id="new_type_modal" wire:model.live="new_type">
                            @foreach ($types as $t)
                                <option value="{{ $t }}">{{ \App\Models\NotificationChannel::labelForType($t) }}</option>
                            @endforeach
                        </x-select>
                        @error('new_type')
                            <p class="mt-1 text-xs text-red-600">{{ $message }}</p>
                        @enderror
                    </div>
                    <div>
                        <x-input-label for="new_label_modal" :value="__('Label')" />
                        <x-text-input id="new_label_modal" type="text" wire:model="new_label" placeholder="{{ __('e.g. #alerts') }}" required />
                        @error('new_label')
                            <p class="mt-1 text-xs text-red-600">{{ $message }}</p>
                        @enderror
                    </div>
                </div>

                @include('livewire.settings.partials.notification-channel-fields', ['prefix' => 'new_', 'type' => $new_type])
            </div>

            <div class="flex shrink-0 flex-wrap justify-end gap-3 border-t border-brand-ink/10 bg-brand-sand/25 px-6 py-4">
                <x-secondary-button type="button" wire:click="closeCreateChannelModal">{{ __('Cancel') }}</x-secondary-button>
                <button
                    type="submit"
                    wire:loading.attr="disabled"
                    wire:target="createChannel"
                    class="inline-flex h-9 items-center gap-1.5 rounded-lg bg-brand-ink px-3.5 text-sm font-semibold text-brand-cream transition-colors hover:bg-brand-forest disabled:cursor-not-allowed disabled:opacity-60"
                >
                    <span wire:loading.remove wire:target="createChannel">{{ __('Add destination') }}</span>
                    <span wire:loading wire:target="createChannel" class="inline-flex items-center gap-2">
                        <x-spinner variant="cream" size="sm" />
                        {{ __('Adding…') }}
                    </span>
                </button>
            </div>
        </form>
    </x-modal>
@endif

@if ($canManage)
    @php($editingChannel = $editing_id ? $channels->firstWhere('id', $editing_id) : null)
    <x-modal
        name="org-edit-channel-modal"
        :show="false"
        maxWidth="2xl"
        overlayClass="bg-brand-ink/30"
        panelClass="dply-modal-panel overflow-hidden shadow-xl flex max-h-[min(90vh,880px)] flex-col"
        focusable
    >
        @if ($editingChannel)
            <form wire:submit="saveEdit" class="flex min-h-0 flex-1 flex-col">
                <div class="shrink-0 border-b border-brand-ink/10 px-6 py-5">
                    <p class="text-2xs font-semibold uppercase tracking-[0.14em] text-brand-mist">{{ __('Edit destination') }}</p>
                    <h2 class="mt-1 text-lg font-semibold text-brand-ink">{{ $editingChannel->label }}</h2>
                </div>

                <div class="min-h-0 flex-1 space-y-5 overflow-y-auto px-6 py-6">
                    <div class="grid gap-5 sm:grid-cols-2">
                        <div>
                            <x-input-label for="edit_type" :value="__('Type')" />
                            <x-select id="edit_type" wire:model.live="edit_type">
                                @foreach ($typesForEdit as $t)
                                    <option value="{{ $t }}">{{ \App\Models\NotificationChannel::labelForType($t) }}</option>
                                @endforeach
                            </x-select>
                            @error('edit_type')
                                <p class="mt-1 text-xs text-red-600">{{ $message }}</p>
                            @enderror
                        </div>
                        <div>
                            <x-input-label for="edit_label" :value="__('Label')" />
                            <x-text-input id="edit_label" type="text" wire:model="edit_label" />
                            @error('edit_label')
                                <p class="mt-1 text-xs text-red-600">{{ $message }}</p>
                            @enderror
                        </div>
                    </div>

                    @include('livewire.settings.partials.notification-channel-fields', ['prefix' => 'edit_', 'type' => $edit_type, 'channel' => $editingChannel])
                </div>

                <div class="flex shrink-0 flex-wrap justify-end gap-3 border-t border-brand-ink/10 bg-brand-sand/25 px-6 py-4">
                    <x-secondary-button type="button" wire:click="cancelEdit">{{ __('Cancel') }}</x-secondary-button>
                    <button type="submit" wire:loading.attr="disabled" wire:target="saveEdit" class="inline-flex h-9 items-center gap-1.5 rounded-lg bg-brand-ink px-3.5 text-sm font-semibold text-brand-cream transition-colors hover:bg-brand-forest disabled:cursor-not-allowed disabled:opacity-60">
                        {{ __('Save changes') }}
                    </button>
                </div>
            </form>
        @endif
    </x-modal>
@endif
