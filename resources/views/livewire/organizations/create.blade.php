<div>
    <div class="mx-auto max-w-7xl px-4 py-8 sm:px-6 lg:px-8">
        <x-breadcrumb-trail
            doc-route="docs.markdown"
            doc-slug="roles-and-permissions"
            :items="[
                ['label' => __('Dashboard'), 'href' => route('dashboard'), 'icon' => 'home'],
                ['label' => __('Organizations'), 'href' => route('organizations.index'), 'icon' => 'building-office-2'],
                ['label' => __('New organization'), 'icon' => 'plus'],
            ]"
        />

        <x-livewire-validation-errors />

        <form wire:submit="store">
            <x-profile-shell
                :title="__('New organization')"
                :description="__('Create a workspace to group servers, teams, billing, and members under one organization.')"
                icon="heroicon-o-building-office-2"
            >
                <x-slot:actions>
                    <x-outline-link href="{{ route('organizations.index') }}" wire:navigate>
                        <x-heroicon-o-arrow-left class="h-4 w-4 shrink-0 opacity-90" aria-hidden="true" />
                        {{ __('Back to organizations') }}
                    </x-outline-link>
                </x-slot:actions>

                <div class="border-b border-brand-ink/10">
                    <div class="flex items-start gap-3 bg-brand-sand/15 px-5 py-4 sm:px-6">
                        <x-icon-badge>
                            <x-heroicon-o-building-office-2 class="h-5 w-5" aria-hidden="true" />
                        </x-icon-badge>
                        <div class="min-w-0">
                            <h3 class="text-base font-semibold text-brand-ink">{{ __('Organization details') }}</h3>
                            <p class="mt-1 text-sm leading-relaxed text-brand-moss">{{ __('Choose a clear name — you can rename it later from organization settings.') }}</p>
                        </div>
                    </div>
                    <div class="px-5 py-5 sm:px-6">
                        <div class="max-w-xl">
                            <x-input-label for="name" :value="__('Organization name')" />
                            <x-text-input
                                id="name"
                                wire:model="name"
                                type="text"
                                class="mt-1 block w-full"
                                required
                                autofocus
                                autocomplete="organization"
                                placeholder="{{ __('e.g. Acme Production') }}"
                            />
                            <x-input-error :messages="$errors->get('name')" class="mt-2" />
                        </div>
                    </div>
                </div>

                <x-slot:footer>
                    <div class="flex flex-wrap items-center justify-end gap-3">
                        <a
                            href="{{ route('organizations.index') }}"
                            wire:navigate
                            class="inline-flex items-center rounded-xl border border-brand-ink/15 bg-white px-4 py-2.5 text-sm font-semibold text-brand-ink shadow-sm transition hover:bg-brand-sand/40"
                        >
                            {{ __('Cancel') }}
                        </a>
                        <x-primary-button type="submit" wire:loading.attr="disabled" wire:target="store">
                            <span wire:loading.remove wire:target="store" class="inline-flex items-center gap-2">
                                <x-heroicon-o-plus class="h-4 w-4 shrink-0" aria-hidden="true" />
                                {{ __('Create organization') }}
                            </span>
                            <span wire:loading wire:target="store" class="inline-flex items-center justify-center gap-2">
                                <x-spinner variant="cream" />
                                {{ __('Creating…') }}
                            </span>
                        </x-primary-button>
                    </div>
                </x-slot:footer>
            </x-profile-shell>
        </form>
    </div>
</div>
