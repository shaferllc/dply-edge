{{-- Shared secrets tab: the org vault. Values are write-never. --}}
<section class="dply-card overflow-hidden p-0" aria-label="{{ __('Shared secrets') }}">
    @if ($vaultRows === [])
        <div class="px-5 py-8 text-center sm:px-6">
            <p class="text-sm font-semibold text-brand-ink">{{ __('No shared secrets yet') }}</p>
            <p class="mt-1 text-sm text-brand-moss">{{ __('Create one, then link it from an app’s Environment page.') }}</p>
        </div>
    @else
        @can('update', $organization)
            @if ($selected_secret_ids !== [])
                <div class="flex flex-wrap items-center gap-3 border-b border-brand-ink/10 bg-brand-sand/25 px-5 py-2.5 sm:px-6">
                    <span class="text-xs font-semibold text-brand-ink">
                        {{ trans_choice('{1} :count selected|[2,*] :count selected', count($selected_secret_ids), ['count' => count($selected_secret_ids)]) }}
                    </span>
                    <button type="button" wire:click="clearVaultSelection" class="text-xs text-brand-moss hover:text-brand-ink">{{ __('Clear') }}</button>
                    <button
                        type="button"
                        class="ml-auto inline-flex h-8 items-center rounded-lg bg-rose-600 px-3 text-xs font-semibold text-white transition hover:bg-rose-700"
                        wire:click="openConfirmActionModal('deleteSelectedVaultSecrets', @js([]), @js(__('Delete selected secrets')), @js(trans_choice('{1} Delete :count secret? It unlinks from every app, and those apps drop the key on the next deploy.|[2,*] Delete :count secrets? They unlink from every app, and those apps drop the keys on the next deploy.', count($selected_secret_ids), ['count' => count($selected_secret_ids)])), @js(__('Delete')), true)"
                    >
                        {{ __('Delete selected') }}
                    </button>
                </div>
            @endif
        @endcan

        <div class="overflow-x-auto">
            <table class="min-w-full text-sm">
                <thead>
                    <tr class="text-left text-2xs font-semibold uppercase tracking-[0.14em] text-brand-mist">
                        @can('update', $organization)
                            <th scope="col" class="w-10 py-3 pl-5 sm:pl-6">
                                <input
                                    type="checkbox"
                                    class="rounded border-brand-ink/25 text-brand-forest focus:ring-brand-sage"
                                    wire:click="toggleAllVaultSecrets"
                                    @checked(count($selected_secret_ids) === count($vaultRows))
                                    aria-label="{{ __('Select all') }}"
                                />
                            </th>
                        @endcan
                        <th scope="col" class="px-5 py-3 font-semibold sm:px-6">{{ __('Key') }}</th>
                        <th scope="col" class="px-3 py-3 font-semibold">{{ __('Linked to') }}</th>
                        <th scope="col" class="px-3 py-3 font-semibold">{{ __('Last rotated') }}</th>
                        <th scope="col" class="px-5 py-3 sm:px-6"><span class="sr-only">{{ __('Actions') }}</span></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-brand-ink/8 border-t border-brand-ink/10">
                    @foreach ($vaultRows as $row)
                        <tr wire:key="vault-{{ $row['id'] }}">
                            @can('update', $organization)
                                <td class="py-3.5 pl-5 align-middle sm:pl-6">
                                    <input
                                        type="checkbox"
                                        class="rounded border-brand-ink/25 text-brand-forest focus:ring-brand-sage"
                                        value="{{ $row['id'] }}"
                                        wire:model.live="selected_secret_ids"
                                        aria-label="{{ __('Select :key', ['key' => $row['key']]) }}"
                                    />
                                </td>
                            @endcan
                            <td class="px-5 py-3.5 align-middle sm:px-6">
                                <p class="font-mono text-sm font-semibold text-brand-ink">{{ $row['key'] }}</p>
                                <p class="mt-0.5 text-xs text-brand-moss">{{ $row['notes'] ?: __('No note') }}</p>
                            </td>
                            <td class="px-3 py-3.5 align-middle">
                                @if ($row['site_names'] === [])
                                    <span class="text-xs text-brand-mist">{{ __('Not linked') }}</span>
                                @else
                                    <div class="flex flex-wrap gap-1.5">
                                        @foreach ($row['site_names'] as $siteName)
                                            <span class="rounded-md bg-brand-sand/50 px-2 py-0.5 text-xs text-brand-ink">{{ $siteName }}</span>
                                        @endforeach
                                    </div>
                                @endif
                            </td>
                            <td class="px-3 py-3.5 align-middle">
                                <span @class(['text-xs', 'text-amber-700' => $row['sites_count'] === 0, 'text-brand-ink' => $row['sites_count'] > 0])>
                                    {{ $row['updated_at']?->diffForHumans() ?? '—' }}@if ($row['sites_count'] === 0) · {{ __('unused') }}@endif
                                </span>
                            </td>
                            <td class="px-5 py-3.5 align-middle sm:px-6">
                                @can('update', $organization)
                                    <div class="flex items-center justify-end gap-1.5">
                                        <button
                                            type="button"
                                            wire:click="startRotateVaultSecret('{{ $row['id'] }}')"
                                            class="inline-flex h-8 items-center rounded-lg border border-brand-ink/15 px-3 text-xs font-semibold text-brand-ink transition hover:bg-brand-sand/40"
                                        >
                                            {{ __('Rotate') }}
                                        </button>
                                        <x-overflow-menu :label="__('Secret actions')">
                                            <button
                                                type="button"
                                                class="block w-full px-3 py-2 text-left text-sm text-rose-700 hover:bg-brand-sand/40"
                                                wire:click="openConfirmActionModal('deleteVaultSecret', @js([$row['id']]), @js(__('Delete secret')), @js(__('Delete :key? It unlinks from every app. Those apps drop the key on the next deploy.', ['key' => $row['key']])), @js(__('Delete')), true)"
                                            >
                                                {{ __('Delete') }}
                                            </button>
                                        </x-overflow-menu>
                                    </div>
                                @endcan
                            </td>
                        </tr>
                        @if ($rotating_secret_id === $row['id'])
                            <tr wire:key="vault-rotate-{{ $row['id'] }}" class="bg-brand-sand/20">
                                <td colspan="5" class="px-5 py-3.5 sm:px-6">
                                    <form wire:submit="rotateVaultSecret" class="flex flex-wrap items-end gap-2">
                                        <div class="min-w-[16rem] flex-1">
                                            <x-input-label for="rotate_value_{{ $row['id'] }}" :value="__('New value for :key', ['key' => $row['key']])" />
                                            <x-text-input id="rotate_value_{{ $row['id'] }}" type="password" wire:model="rotate_value" class="mt-1 block w-full font-mono" autocomplete="new-password" />
                                            <x-input-error :messages="$errors->get('rotate_value')" class="mt-1" />
                                        </div>
                                        <x-primary-button type="submit" wire:loading.attr="disabled" wire:target="rotateVaultSecret">{{ __('Save') }}</x-primary-button>
                                        <x-secondary-button type="button" wire:click="cancelRotateVaultSecret">{{ __('Cancel') }}</x-secondary-button>
                                    </form>
                                </td>
                            </tr>
                        @endif
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
    <p class="border-t border-brand-ink/10 bg-brand-sand/20 px-5 py-3 text-xs text-brand-moss sm:px-6">
        {{ __('Values can’t be read back — only replaced. New values reach linked apps on their next deploy.') }}
    </p>
</section>
