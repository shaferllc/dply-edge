<x-modal name="link-organization-secret-modal" :show="$showLinkOrganizationSecretModal" maxWidth="2xl" overlayClass="bg-brand-ink/40">
    {{-- The body (vault listing, workspace options, paste preview) renders only
         while the modal is open, so the closed page skips its queries. --}}
    @if ($showLinkOrganizationSecretModal)
    <div class="border-b border-brand-ink/10 px-6 py-5">
        <h2 class="text-lg font-semibold text-brand-ink">{{ __('Secrets') }}</h2>
        <p class="mt-1 text-sm text-brand-moss">{{ __('Paste a .env snippet — comments and section headers are fine. Each key is saved write-never and injected on the next deploy.') }}</p>
    </div>

    <div class="space-y-4 border-b border-brand-ink/10 px-6 py-5">
        <p class="text-xs font-semibold uppercase tracking-[0.14em] text-brand-mist">{{ __('Bulk import') }}</p>
        <div>
            <x-input-label for="pasteSecretBlob" :value="__('Paste .env')" />
            <textarea id="pasteSecretBlob" wire:model.live.debounce.300ms="pasteSecretBlob" rows="10" class="dply-input mt-1 block w-full font-mono text-sm" placeholder="# Discord{{ "\n" }}DISCORD_CLIENT_ID=…{{ "\n" }}DISCORD_BOT_TOKEN=…{{ "\n\n" }}# Telegram{{ "\n" }}TELEGRAM_BOT_TOKEN=…"></textarea>
            @error('pasteSecretBlob')<p class="mt-1 text-xs text-brand-rust">{{ $message }}</p>@enderror
        </div>
        @php
            $preview = $this->pastedSecretPreview();
            $importable = count(array_filter($preview, static fn (array $row): bool => ! $row['already_linked']));
        @endphp
        @if ($preview !== [])
            <div>
                <p class="text-xs font-semibold text-brand-ink">{{ trans_choice('{1} :count key ready to import|[2,*] :count keys ready to import', $importable, ['count' => $importable]) }}</p>
                <ul class="mt-2 divide-y divide-brand-ink/8 rounded-xl border border-brand-ink/10">
                    @foreach ($preview as $row)
                        <li class="flex flex-wrap items-center justify-between gap-2 px-3 py-2" wire:key="paste-preview-{{ $row['key'] }}">
                            <div class="min-w-0">
                                <p class="font-mono text-sm font-semibold text-brand-ink">{{ $row['key'] }}</p>
                                <p class="text-xs text-brand-moss">{{ $row['note'] ?: __('encrypted · write-only') }}</p>
                            </div>
                            @if ($row['already_linked'])
                                <span class="text-xs font-semibold text-brand-mist">{{ __('Already linked') }}</span>
                            @else
                                <span class="text-xs font-semibold text-brand-moss">{{ __('New') }}</span>
                            @endif
                        </li>
                    @endforeach
                </ul>
            </div>
        @endif
        <div>
            <x-input-label for="pasteSecretNotes" :value="__('Note (optional)')" />
            <x-text-input id="pasteSecretNotes" wire:model="pasteSecretNotes" class="mt-1 block w-full text-sm" :placeholder="__('Applied to every new key. Section headers are used when this is blank.')" />
        </div>
        <x-primary-button type="button" wire:click="pasteOrganizationSecret" wire:loading.attr="disabled" wire:target="pasteOrganizationSecret">
            {{ $importable === 0 ? __('Import secrets') : trans_choice('{1} Import :count secret|[2,*] Import :count secrets', $importable, ['count' => $importable]) }}
        </x-primary-button>
        <details class="rounded-xl border border-brand-ink/10 bg-brand-sand/15 px-4 py-3">
            <summary class="cursor-pointer text-sm font-semibold text-brand-ink">{{ __('Or add one key') }}</summary>
            <div class="mt-3 grid gap-3 sm:grid-cols-2">
                <div>
                    <x-input-label for="pasteSecretKey" :value="__('Key')" />
                    <x-text-input id="pasteSecretKey" wire:model="pasteSecretKey" class="mt-1 block w-full font-mono text-sm" placeholder="SLACK_BOT_TOKEN" />
                    @error('pasteSecretKey')<p class="mt-1 text-xs text-brand-rust">{{ $message }}</p>@enderror
                </div>
                <div>
                    <x-input-label for="pasteSecretValue" :value="__('Value')" />
                    <x-text-input id="pasteSecretValue" type="password" wire:model="pasteSecretValue" class="mt-1 block w-full font-mono text-sm" autocomplete="new-password" />
                    @error('pasteSecretValue')<p class="mt-1 text-xs text-brand-rust">{{ $message }}</p>@enderror
                </div>
            </div>
        </details>
    </div>

    <div class="space-y-3 px-6 py-5">
        <p class="text-xs font-semibold uppercase tracking-[0.14em] text-brand-mist">{{ __('Or link an existing vault secret') }}</p>
        <div class="grid gap-3 sm:grid-cols-2">
            <div>
                <x-input-label for="linkSecretSearch" :value="__('Search')" />
                <x-text-input id="linkSecretSearch" wire:model.live.debounce.300ms="linkSecretSearch" class="mt-1 block w-full" placeholder="{{ __('Key or note') }}" />
            </div>
            <div>
                <x-input-label for="linkSecretWorkspaceId" :value="__('Project filter')" />
                <select id="linkSecretWorkspaceId" wire:model.live="linkSecretWorkspaceId" class="dply-input mt-1 w-full">
                    <option value="">{{ __('All projects') }}</option>
                    @foreach ($this->linkSecretWorkspaceOptions() as $workspace)
                        <option value="{{ $workspace->id }}">{{ $workspace->name }}</option>
                    @endforeach
                </select>
            </div>
        </div>

        @php $linkable = $this->linkableOrganizationSecretRows(); @endphp
        @if ($linkable === [])
            <p class="text-sm text-brand-moss">{{ __('No existing vault secrets match. Paste one above, or create it on Organization → Secrets.') }}</p>
        @else
            <ul class="divide-y divide-brand-ink/8 rounded-xl border border-brand-ink/10">
                @foreach ($linkable as $row)
                    <li class="flex flex-wrap items-center justify-between gap-3 px-3 py-2.5" wire:key="linkable-secret-{{ $row['id'] }}">
                        <div class="min-w-0">
                            <p class="font-mono text-sm font-semibold text-brand-ink">{{ $row['key'] }}</p>
                            <p class="text-xs text-brand-moss">{{ $row['notes'] ?: __('No note') }}</p>
                            @if ($row['binding_owned'])
                                <p class="mt-0.5 text-xs text-amber-800">{{ __('A connected resource already owns this key. The binding wins unless you override it in the site .env.') }}</p>
                            @endif
                        </div>
                        @if ($row['already_linked'])
                            <span class="text-xs font-semibold text-brand-mist">{{ __('Linked') }}</span>
                        @elseif ($row['key_taken'])
                            <span class="text-xs font-semibold text-brand-mist">{{ __('Key already linked') }}</span>
                        @else
                            <x-primary-button type="button" wire:click="linkOrganizationSecret('{{ $row['id'] }}')" wire:loading.attr="disabled" wire:target="linkOrganizationSecret">
                                {{ __('Link') }}
                            </x-primary-button>
                        @endif
                    </li>
                @endforeach
            </ul>
        @endif
    </div>

    <div class="flex justify-end border-t border-brand-ink/10 bg-brand-sand/20 px-6 py-4">
        <x-secondary-button type="button" wire:click="closeLinkOrganizationSecretModal" x-on:click="$dispatch('close')">{{ __('Close') }}</x-secondary-button>
    </div>
    @else
    <p class="px-6 py-10 text-center text-sm text-brand-moss">{{ __('Loading…') }}</p>
    @endif
</x-modal>
