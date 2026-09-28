@php
    // Gradient + initials fallback for the icon preview (mirrors the site-logo
    // partial) so the preview matches the placeholder shown when no icon is set.
    $iconSeed = (string) ($organization->slug ?: $organization->name ?: $organization->id);
    $iconHash = hexdec(substr(sha1($iconSeed), 0, 12));
    $iconHueA = $iconHash % 360;
    $iconHueB = ($iconHueA + 60 + ((int) (($iconHash >> 4) % 120))) % 360;
    $iconFallbackStyle = "background-image: linear-gradient(135deg, hsl({$iconHueA}deg 65% 56%) 0%, hsl({$iconHueB}deg 65% 42%) 100%);";
    $canDelete = auth()->user()?->can('delete', $organization);
    $tokensCount = $organization->apiTokens->whereNull('revoked_at')->count();
@endphp

{{-- Root x-data drives the per-row "Saved" flashes: every autosave
     dispatches org-setting-saved with the field it wrote. --}}
<div
    x-data="{ saved: null, timer: null }"
    x-on:org-setting-saved.window="saved = $event.detail.field; clearTimeout(timer); timer = setTimeout(() => saved = null, 2000)"
>
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
        <x-organization-shell
            :organization="$organization"
            section="general"
            :breadcrumb="[
                ['label' => __('Dashboard'), 'href' => route('dashboard'), 'icon' => 'home'],
                ['label' => $organization->name, 'href' => route('organizations.show', $organization), 'icon' => 'building-office-2'],
                ['label' => __('General'), 'icon' => 'cog-6-tooth'],
            ]"
        >
            @php
                $card = 'dply-card overflow-hidden p-0';
                $cardTitle = 'border-b border-brand-ink/10 px-5 py-3.5 text-2xs font-semibold uppercase tracking-[0.14em] text-brand-mist sm:px-6';
                $row = 'grid gap-2 px-5 py-4 sm:grid-cols-[320px_minmax(0,1fr)] sm:items-start sm:gap-8 sm:px-6';
                $rowLabel = 'block text-sm font-semibold text-brand-ink';
                $rowHelp = 'mt-0.5 block text-xs leading-relaxed text-brand-moss';
                $savedFlash = 'inline-flex items-center gap-1 text-xs font-medium text-brand-forest';
            @endphp

            <div class="space-y-6">
                <div>
                    <h1 class="text-2xl font-semibold tracking-tight text-brand-ink">{{ __('General') }}</h1>
                    <p class="mt-1 text-sm text-brand-moss">{{ __('Each setting saves on its own as soon as you change it.') }}</p>
                </div>

                {{-- Profile --}}
                <section class="{{ $card }}" aria-labelledby="general-profile">
                    <h2 id="general-profile" class="{{ $cardTitle }}">{{ __('Profile') }}</h2>
                    <div class="divide-y divide-brand-ink/8">
                        <div class="{{ $row }}">
                            <label for="org_name">
                                <span class="{{ $rowLabel }}">{{ __('Name') }}</span>
                                <span class="{{ $rowHelp }}">{{ __('Shown in the switcher, emails and invoices.') }}</span>
                            </label>
                            <div>
                                <x-text-input id="org_name" wire:model.blur="name" type="text" class="block w-full" maxlength="255" />
                                <x-input-error :messages="$errors->get('name')" />
                                <span x-show="saved === 'name'" x-cloak class="{{ $savedFlash }} mt-1"><x-heroicon-m-check class="h-3.5 w-3.5" aria-hidden="true" />{{ __('Saved') }}</span>
                            </div>
                        </div>

                        <div class="{{ $row }}">
                            <label for="org_slug">
                                <span class="{{ $rowLabel }}">{{ __('Handle') }}</span>
                                <span class="{{ $rowHelp }}">{{ __('Lowercase letters, numbers, dashes. URLs use the organization ID.') }}</span>
                            </label>
                            <div>
                                <x-text-input id="org_slug" wire:model.blur="slug" type="text" class="block w-full" maxlength="255" />
                                <x-input-error :messages="$errors->get('slug')" />
                                <span x-show="saved === 'slug'" x-cloak class="{{ $savedFlash }} mt-1"><x-heroicon-m-check class="h-3.5 w-3.5" aria-hidden="true" />{{ __('Saved') }}</span>
                            </div>
                        </div>

                        <div class="{{ $row }}">
                            <div>
                                <span class="{{ $rowLabel }}">{{ __('Icon') }}</span>
                                <span class="{{ $rowHelp }}">{{ __('Shown beside the organization across the dashboard. PNG, JPG, WEBP, GIF or ICO up to 1 MB.') }}</span>
                            </div>
                            <div>
                                <div class="flex flex-wrap items-center gap-3">
                                    @if ($organization->iconUrl())
                                        {{-- onerror: swap to the initials fallback if the stored
                                             icon file is missing — never the broken-image glyph. --}}
                                        <img src="{{ $organization->iconUrl() }}" alt="{{ $organization->name }}"
                                            onerror="this.style.display='none'; if (this.nextElementSibling) this.nextElementSibling.style.display='inline-flex';"
                                            class="h-10 w-10 shrink-0 rounded-xl object-cover ring-1 ring-brand-ink/10 shadow-sm bg-white" />
                                        <span class="h-10 w-10 shrink-0 items-center justify-center rounded-xl text-white text-sm font-semibold shadow-sm ring-1 ring-brand-ink/10" style="display: none; {{ $iconFallbackStyle }}">
                                            {{ $organization->initials() }}
                                        </span>
                                    @else
                                        <span class="inline-flex h-10 w-10 shrink-0 items-center justify-center rounded-xl text-white text-sm font-semibold shadow-sm ring-1 ring-brand-ink/10" style="{{ $iconFallbackStyle }}">
                                            {{ $organization->initials() }}
                                        </span>
                                    @endif

                                    <label class="inline-flex h-8 cursor-pointer items-center gap-1.5 rounded-lg border border-brand-ink/15 px-3 text-sm font-medium text-brand-ink transition-colors hover:bg-brand-sand/40">
                                        <x-heroicon-o-arrow-up-tray class="h-4 w-4 shrink-0" aria-hidden="true" />
                                        <span wire:loading.remove wire:target="org_icon_upload">{{ __('Upload') }}</span>
                                        <span wire:loading wire:target="org_icon_upload">{{ __('Uploading…') }}</span>
                                        <input type="file" wire:model="org_icon_upload" accept="image/png,image/jpeg,image/webp,image/gif,image/x-icon" class="hidden" />
                                    </label>

                                    @if ($organization->hasIcon())
                                        <button type="button" wire:click="removeOrgIcon" class="inline-flex h-8 items-center gap-1.5 rounded-lg border border-brand-ink/15 px-3 text-sm font-medium text-brand-moss transition-colors hover:bg-brand-sand/40">
                                            <x-heroicon-o-trash class="h-4 w-4 shrink-0" aria-hidden="true" />
                                            {{ __('Remove') }}
                                        </button>
                                    @endif

                                    <span x-show="saved === 'icon'" x-cloak class="{{ $savedFlash }}"><x-heroicon-m-check class="h-3.5 w-3.5" aria-hidden="true" />{{ __('Saved') }}</span>
                                </div>
                                <x-input-error :messages="$errors->get('org_icon_upload')" />
                            </div>
                        </div>

                        <div class="{{ $row }}">
                            <label for="org_email">
                                <span class="{{ $rowLabel }}">{{ __('Contact email') }}</span>
                                <span class="{{ $rowHelp }}">{{ __('Where we write about the organization itself.') }}</span>
                            </label>
                            <div>
                                <x-text-input id="org_email" wire:model.blur="email" type="email" class="block w-full" maxlength="255" />
                                <x-input-error :messages="$errors->get('email')" />
                                <span x-show="saved === 'email'" x-cloak class="{{ $savedFlash }} mt-1"><x-heroicon-m-check class="h-3.5 w-3.5" aria-hidden="true" />{{ __('Saved') }}</span>
                            </div>
                        </div>

                        <div class="{{ $row }}">
                            <label for="org_timezone">
                                <span class="{{ $rowLabel }}">{{ __('Timezone') }}</span>
                                <span class="{{ $rowHelp }}">{{ __('For activity times and daily roll-ups.') }}</span>
                            </label>
                            <div>
                                <x-select id="org_timezone" wire:model.live="timezone" class="block w-full">
                                    <option value="">{{ __('— None —') }}</option>
                                    @foreach ($timezones as $tz)
                                        <option value="{{ $tz }}">{{ $tz }}</option>
                                    @endforeach
                                </x-select>
                                <x-input-error :messages="$errors->get('timezone')" />
                                <span x-show="saved === 'timezone'" x-cloak class="{{ $savedFlash }} mt-1"><x-heroicon-m-check class="h-3.5 w-3.5" aria-hidden="true" />{{ __('Saved') }}</span>
                            </div>
                        </div>

                        <div class="{{ $row }}">
                            <label for="org_description">
                                <span class="{{ $rowLabel }}">{{ __('Description') }}</span>
                                <span class="{{ $rowHelp }}">{{ __('A short note for members. Up to 500 characters.') }}</span>
                            </label>
                            <div>
                                <x-textarea id="org_description" wire:model.blur="description" rows="2" class="block w-full" maxlength="500" />
                                <x-input-error :messages="$errors->get('description')" />
                                <span x-show="saved === 'description'" x-cloak class="{{ $savedFlash }} mt-1"><x-heroicon-m-check class="h-3.5 w-3.5" aria-hidden="true" />{{ __('Saved') }}</span>
                            </div>
                        </div>
                    </div>
                </section>

                {{-- Data & email --}}
                <section class="{{ $card }}" aria-labelledby="general-data">
                    <h2 id="general-data" class="{{ $cardTitle }}">{{ __('Data & email') }}</h2>
                    <div class="divide-y divide-brand-ink/8">
                        <div class="{{ $row }}">
                            <label for="edge_data_region">
                                <span class="{{ $rowLabel }}">{{ __('Edge data region') }}</span>
                                <span class="{{ $rowHelp }}">{{ __('Where buckets created for this organization store data. Existing buckets stay where they are.') }}</span>
                            </label>
                            <div>
                                <x-select id="edge_data_region" wire:model.live="edge_data_region" class="block w-full">
                                    <option value="default">{{ __('Default — Dply Edge picks the region') }}</option>
                                    <option value="eu">{{ __('EU — strict EU jurisdiction (R2 EU jurisdiction)') }}</option>
                                    <option value="weur">{{ __('Western Europe (weur)') }}</option>
                                    <option value="eeur">{{ __('Eastern Europe (eeur)') }}</option>
                                    <option value="wnam">{{ __('Western North America (wnam)') }}</option>
                                    <option value="enam">{{ __('Eastern North America (enam)') }}</option>
                                    <option value="apac">{{ __('Asia-Pacific (apac)') }}</option>
                                    <option value="oc">{{ __('Oceania (oc)') }}</option>
                                </x-select>
                                <p class="mt-1 text-xs text-brand-mist">{{ __('Selecting "EU" creates buckets in the EU jurisdiction — data is stored in the EU and the EU jurisdiction header is set on every request.') }}</p>
                                <span x-show="saved === 'edge_data_region'" x-cloak class="{{ $savedFlash }} mt-1"><x-heroicon-m-check class="h-3.5 w-3.5" aria-hidden="true" />{{ __('Saved') }}</span>
                            </div>
                        </div>

                        <div class="{{ $row }}">
                            <div>
                                <span class="{{ $rowLabel }}">{{ __('Deploy-finish emails') }}</span>
                                <span class="{{ $rowHelp }}">{{ __('Email whoever started a deploy when it completes or fails.') }}</span>
                                @can('viewNotificationChannels', $organization)
                                    <a href="{{ route('organizations.notification-channels', $organization) }}" wire:navigate class="mt-1 inline-block text-xs font-medium text-brand-forest hover:underline">{{ __('Notification channels') }} →</a>
                                @endcan
                            </div>
                            <div class="flex items-center gap-3">
                                <x-toggle-switch wire:model.live="deploy_email_notifications_enabled" :enabled="$deploy_email_notifications_enabled" :on-label="__('On')" :off-label="__('Off')" />
                                <span x-show="saved === 'deploy_email_notifications_enabled'" x-cloak class="{{ $savedFlash }}"><x-heroicon-m-check class="h-3.5 w-3.5" aria-hidden="true" />{{ __('Saved') }}</span>
                            </div>
                        </div>
                    </div>
                </section>

                {{-- API tokens — inventory only. Creation lives in one place
                     (Settings\ApiKeys: paid-plan gate, deployer ability cap,
                     api_token.created audit). This keeps the org-wide view
                     admins need — every member's tokens — plus revoke. --}}
                <section class="{{ $card }}" id="api-tokens" aria-labelledby="general-tokens">
                    <div class="flex items-center justify-between gap-3 border-b border-brand-ink/10 px-5 py-3 sm:px-6">
                        <div class="min-w-0">
                            <h2 id="general-tokens" class="text-2xs font-semibold uppercase tracking-[0.14em] text-brand-mist">
                                {{ __('API tokens') }}@if ($tokensCount) <span class="ms-1 tabular-nums">· {{ $tokensCount }}</span>@endif
                            </h2>
                            <p class="mt-0.5 text-xs text-brand-moss">{{ __('Every token issued for this organization, across all members.') }}</p>
                        </div>
                        <a href="{{ route('profile.api-keys') }}" wire:navigate class="inline-flex h-8 shrink-0 items-center gap-1.5 rounded-lg bg-brand-ink px-3 text-sm font-semibold text-brand-cream transition-colors hover:bg-brand-forest">
                            <x-heroicon-o-plus class="h-4 w-4 shrink-0" aria-hidden="true" />
                            {{ __('Create token') }}
                        </a>
                    </div>

                    @if ($organization->apiTokens->isEmpty())
                        <div class="px-5 py-8 text-center sm:px-6">
                            <x-empty-state
                                borderless
                                compact
                                icon="heroicon-o-key"
                                :title="__('No API tokens yet')"
                                :description="__('Tokens let scripts and CI talk to the dply API on behalf of this organization.')"
                            />
                            <a href="{{ route('profile.api-keys') }}" wire:navigate class="mt-1 inline-block text-xs font-medium text-brand-forest hover:underline">
                                {{ __('Create one in API keys settings') }}
                            </a>
                        </div>
                    @else
                        <ul class="divide-y divide-brand-ink/8">
                            @foreach ($organization->apiTokens as $apiToken)
                                <li wire:key="org-api-token-{{ $apiToken->id }}" class="flex items-center justify-between gap-3 px-5 py-3 sm:px-6">
                                    <div class="min-w-0 flex-1">
                                        <p class="flex min-w-0 items-baseline gap-2">
                                            <span class="truncate text-sm font-semibold text-brand-ink">{{ $apiToken->name }}</span>
                                            {{-- Revoked when its owner left or was removed from the organization. --}}
                                            @if ($apiToken->revoked_at)
                                                <span class="inline-flex shrink-0 items-center gap-0.5 rounded-full bg-red-500/10 px-1.5 py-px text-2xs font-semibold uppercase tracking-wide text-red-700 dark:text-red-300">
                                                    <x-heroicon-m-no-symbol class="h-3 w-3" aria-hidden="true" />
                                                    {{ __('Revoked') }}
                                                </span>
                                            @endif
                                        </p>
                                        <p class="mt-0.5 flex flex-wrap items-center gap-x-2 gap-y-0.5 text-xs text-brand-moss">
                                            <span class="font-mono text-brand-mist">{{ $apiToken->token_prefix }}…</span>
                                            @if ($apiToken->last_used_at)
                                                <span class="text-brand-mist">·</span>
                                                <span>{{ __('Last used :time', ['time' => $apiToken->last_used_at->diffForHumans()]) }}</span>
                                            @endif
                                            @if ($apiToken->expires_at)
                                                <span class="text-brand-mist">·</span>
                                                <span>{{ __('Expires :date', ['date' => $apiToken->expires_at->format('M j, Y')]) }}</span>
                                            @endif
                                        </p>
                                    </div>
                                    <button
                                        type="button"
                                        wire:click='promptRevokeApiToken({{ json_encode((string) $apiToken->id) }})'
                                        class="shrink-0 text-sm font-medium text-red-600 hover:text-red-700 hover:underline dark:text-red-400"
                                    >
                                        {{ $apiToken->revoked_at ? __('Delete') : __('Revoke') }}
                                    </button>
                                </li>
                            @endforeach
                        </ul>
                    @endif
                </section>

                {{-- Danger zone — owner only. --}}
                @if ($canDelete)
                    <section class="overflow-hidden rounded-2xl border border-red-500/30" aria-labelledby="general-danger">
                        <h2 id="general-danger" class="border-b border-red-500/30 px-5 py-3.5 text-2xs font-semibold uppercase tracking-[0.14em] text-red-700 dark:text-red-300 sm:px-6">{{ __('Danger zone') }}</h2>
                        <div class="divide-y divide-red-500/20">
                            {{-- Transfer needs a member picker; the People page already has one. --}}
                            <div class="{{ $row }} sm:items-center">
                                <div>
                                    <span class="{{ $rowLabel }}">{{ __('Transfer ownership') }}</span>
                                    <span class="{{ $rowHelp }}">{{ __('Hand the organization to another member from the People page.') }}</span>
                                </div>
                                <div>
                                    <a href="{{ route('organizations.members', $organization) }}" wire:navigate class="inline-flex h-9 items-center rounded-lg border border-brand-ink/15 px-3.5 text-sm font-medium text-brand-ink transition-colors hover:bg-brand-sand/40">
                                        {{ __('Transfer…') }}
                                    </a>
                                </div>
                            </div>

                            {{-- DeleteOrganizationAction refuses while any app remains or a
                                 plan is active, so the copy says so rather than promising
                                 to delete the apps. --}}
                            <div class="{{ $row }}">
                                <label for="delete_confirm">
                                    <span class="{{ $rowLabel }}">{{ __('Delete organization') }}</span>
                                    <span class="{{ $rowHelp }}">{{ __('Delete every app and cancel the plan first. This then permanently deletes the organization with its secrets, DNS credentials, API tokens, teams and activity. It cannot be undone.') }}</span>
                                </label>
                                <div>
                                    <div class="flex flex-col gap-2 sm:flex-row sm:items-center">
                                        <x-text-input id="delete_confirm" wire:model.live="delete_confirm" type="text" class="block w-full sm:max-w-xs" placeholder="{{ __('Type :name to confirm', ['name' => $organization->name]) }}" autocomplete="off" />
                                        <button
                                            type="button"
                                            x-on:click="$dispatch('open-modal', 'delete-organization-confirmation')"
                                            wire:loading.attr="disabled"
                                            wire:target="deleteOrganization"
                                            @disabled($delete_confirm !== $organization->name)
                                            class="inline-flex h-9 shrink-0 items-center gap-1.5 rounded-lg border border-red-500/40 px-3.5 text-sm font-medium text-red-700 transition-colors hover:bg-red-500/10 disabled:cursor-not-allowed disabled:opacity-50 dark:text-red-300"
                                        >
                                            {{ __('Delete…') }}
                                        </button>
                                    </div>
                                    <x-input-error :messages="$errors->get('delete_confirm')" />
                                </div>
                            </div>
                        </div>
                    </section>
                @endif
            </div>
        </x-organization-shell>
    </div>

    {{-- The modal keeps its roomier spacing on purpose: it is the last stop
         before an irreversible delete, and crowding that is the wrong trade. --}}
    @if ($canDelete)
        <x-modal
            name="delete-organization-confirmation"
            :show="false"
            maxWidth="md"
            overlayClass="bg-brand-ink/30"
            panelClass="dply-modal-panel overflow-hidden shadow-xl"
            focusable
        >
            <div>
                <div class="flex items-start gap-3 border-b border-brand-ink/10 px-5 py-4">
                    <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-red-100 text-red-600 ring-1 ring-red-200">
                        <x-heroicon-o-trash class="h-5 w-5" aria-hidden="true" />
                    </span>
                    <div class="min-w-0">
                        <p class="text-xs font-semibold uppercase tracking-[0.18em] text-red-600">{{ __('Danger zone') }}</p>
                        <h2 class="mt-1 text-base font-semibold text-brand-ink">{{ __('Delete this organization?') }}</h2>
                    </div>
                </div>
                <div class="px-5 py-4 text-sm leading-6 text-brand-moss">
                    <p>{{ __('This permanently deletes :name with its secrets, DNS credentials, API tokens, teams and activity. Every app must already be deleted and the plan cancelled. This cannot be undone.', ['name' => $organization->name]) }}</p>
                </div>
                <div class="flex flex-wrap justify-end gap-2 border-t border-brand-ink/10 bg-brand-sand/25 px-5 py-3">
                    <x-secondary-button type="button" x-on:click="$dispatch('close-modal', 'delete-organization-confirmation')">
                        {{ __('Cancel') }}
                    </x-secondary-button>
                    <x-danger-button
                        type="button"
                        wire:click="deleteOrganization"
                        wire:loading.attr="disabled"
                        wire:target="deleteOrganization"
                        @disabled($delete_confirm !== $organization->name)
                    >
                        <span wire:loading.remove wire:target="deleteOrganization">{{ __('Delete organization') }}</span>
                        <span wire:loading wire:target="deleteOrganization" class="inline-flex items-center gap-2">
                            <x-spinner variant="cream" size="sm" />
                            {{ __('Deleting…') }}
                        </span>
                    </x-danger-button>
                </div>
            </div>
        </x-modal>
    @endif

    {{-- Confirm modal must live in the Livewire view tree (not only a layout slot) so state updates and wire: targets bind reliably. --}}
    @include('livewire.partials.confirm-action-modal')
</div>
