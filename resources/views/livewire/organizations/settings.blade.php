@php
    // Gradient + initials fallback for the icon preview (mirrors the site-logo
    // partial) so the preview matches the placeholder shown when no icon is set.
    $iconSeed = (string) ($organization->slug ?: $organization->name ?: $organization->id);
    $iconHash = hexdec(substr(sha1($iconSeed), 0, 12));
    $iconHueA = $iconHash % 360;
    $iconHueB = ($iconHueA + 60 + ((int) (($iconHash >> 4) % 120))) % 360;
    $iconFallbackStyle = "background-image: linear-gradient(135deg, hsl({$iconHueA}deg 65% 56%) 0%, hsl({$iconHueB}deg 65% 42%) 100%);";
    $canDelete = auth()->user()?->can('delete', $organization);
    $memberCount = $organization->users->count();
    $tokensCount = $organization->apiTokens->count();
@endphp

<div>
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
        <x-organization-shell
            :organization="$organization"
            section="general"
            :title="__('Organization settings')"
            :description="__('Branding, contact details, email defaults, data residency, and API tokens for this organization.')"
            icon="heroicon-o-cog-6-tooth"
            :breadcrumb="[
                ['label' => __('Dashboard'), 'href' => route('dashboard'), 'icon' => 'home'],
                ['label' => $organization->name, 'href' => route('organizations.show', $organization), 'icon' => 'building-office-2'],
                ['label' => __('Settings'), 'icon' => 'cog-6-tooth'],
            ]"
        >
            <x-slot:actions>
                <a
                    href="{{ route('organizations.show', $organization) }}"
                    wire:navigate
                    class="inline-flex h-6 items-center gap-1 rounded-md border border-brand-ink/15 bg-white px-2 text-xs font-semibold text-brand-ink shadow-sm transition-colors hover:bg-brand-sand/40"
                >
                    <x-heroicon-o-building-office-2 class="h-3.5 w-3.5 shrink-0 opacity-90" aria-hidden="true" />
                    {{ __('Overview') }}
                </a>
            </x-slot:actions>

            {{-- Hairline strip rather than three fleet-stat cards, matching the
                 invoices and notification-channels glance rows. --}}
            <x-slot:stats>
                <dl class="grid grid-cols-3 gap-px bg-brand-ink/5" aria-label="{{ __('Organization at a glance') }}">
                    <div class="bg-white px-3 py-2">
                        <dt class="text-2xs font-semibold uppercase tracking-wide text-brand-mist">{{ __('Plan') }}</dt>
                        <dd class="mt-0.5 truncate text-sm font-semibold text-brand-ink" title="{{ $organization->planTierLabel() }}">{{ $organization->planTierLabel() }}</dd>
                    </div>
                    <div class="bg-white px-3 py-2">
                        <dt class="text-2xs font-semibold uppercase tracking-wide text-brand-mist">{{ __('Members') }}</dt>
                        <dd class="mt-0.5 flex items-baseline gap-1.5">
                            <span class="font-mono text-base font-semibold tabular-nums text-brand-ink">{{ $memberCount }}</span>
                            <span class="truncate text-xs text-brand-moss">{{ trans_choice('with access|with access', $memberCount) }}</span>
                        </dd>
                    </div>
                    <div class="bg-white px-3 py-2">
                        <dt class="text-2xs font-semibold uppercase tracking-wide text-brand-mist">{{ __('Created') }}</dt>
                        <dd class="mt-0.5 flex items-baseline gap-1.5">
                            <span class="truncate text-sm font-semibold text-brand-ink">{{ $organization->created_at?->format('M j, Y') ?? '—' }}</span>
                            <span class="truncate text-xs text-brand-moss">{{ $organization->created_at?->diffForHumans() ?? '' }}</span>
                        </dd>
                    </div>
                </dl>
            </x-slot:stats>

            {{-- Success goes out as a toast (DispatchesToastNotifications), not an
                 inline banner: it pushed the whole form down on every save, and a
                 confirmation the user already expects does not deserve permanent
                 space. Errors stay inline — those need to sit next to the fields
                 they refer to. --}}
            @if ($errors->isNotEmpty())
                <div class="border-b border-brand-ink/10 px-3 py-2 sm:px-4">
                    <x-livewire-validation-errors />
                </div>
            @endif

            {{-- Icon / branding --}}
            <section class="border-b border-brand-ink/10">
                <x-workspace-panel-head
                    dense
                    icon="heroicon-o-photo"
                    :title="__('Organization icon')"
                    :note="__('Shown beside this organization across the dashboard. PNG, JPG, WEBP, GIF or ICO up to 1 MB.')"
                />

                <div class="flex flex-wrap items-center gap-3 px-3 py-2.5 sm:px-4">
                    <div class="shrink-0">
                        @if ($organization->iconUrl())
                            {{-- onerror: swap to the initials fallback if the stored
                                 icon file is missing — never the broken-image glyph. --}}
                            <img src="{{ $organization->iconUrl() }}" alt="{{ $organization->name }}"
                                onerror="this.style.display='none'; if (this.nextElementSibling) this.nextElementSibling.style.display='inline-flex';"
                                class="h-11 w-11 rounded-xl object-cover ring-1 ring-brand-ink/10 shadow-sm bg-white" />
                            <span class="h-11 w-11 shrink-0 items-center justify-center rounded-xl text-white text-sm font-semibold shadow-sm ring-1 ring-brand-ink/10" style="display: none; {{ $iconFallbackStyle }}">
                                {{ $organization->initials() }}
                            </span>
                        @else
                            <span class="inline-flex h-11 w-11 shrink-0 items-center justify-center rounded-xl text-white text-sm font-semibold shadow-sm ring-1 ring-brand-ink/10" style="{{ $iconFallbackStyle }}">
                                {{ $organization->initials() }}
                            </span>
                        @endif
                    </div>

                    <div class="flex flex-wrap items-center gap-1.5">
                        <label class="inline-flex h-7 cursor-pointer items-center gap-1 rounded-md border border-brand-ink/15 bg-white px-2 text-xs font-semibold text-brand-ink shadow-sm transition-colors hover:bg-brand-sand/40">
                            <x-heroicon-o-arrow-up-tray class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                            <span wire:loading.remove wire:target="org_icon_upload">{{ __('Upload') }}</span>
                            <span wire:loading wire:target="org_icon_upload">{{ __('Uploading…') }}</span>
                            <input type="file" wire:model="org_icon_upload" accept="image/png,image/jpeg,image/webp,image/gif,image/x-icon" class="hidden" />
                        </label>

                        @if ($organization->hasIcon())
                            <button type="button" wire:click="removeOrgIcon" class="inline-flex h-7 items-center gap-1 rounded-md border border-brand-ink/15 bg-white px-2 text-xs font-semibold text-brand-moss shadow-sm transition-colors hover:bg-brand-sand/40">
                                <x-heroicon-o-trash class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                                {{ __('Remove') }}
                            </button>
                        @endif
                    </div>

                    <x-input-error :messages="$errors->get('org_icon_upload')" class="w-full" />
                </div>
            </section>

            {{-- General details --}}
            <form wire:submit="saveGeneral" class="border-b border-brand-ink/10">
                <x-workspace-panel-head
                    dense
                    icon="heroicon-o-identification"
                    :title="__('General')"
                    :note="__('Changes apply across the dashboard for everyone with access.')"
                />

                {{-- Two-up on sm+. These five fields were stacked full-width, which
                     was the page's single biggest source of scroll for inputs that
                     are mostly short. --}}
                <div class="grid gap-3 px-3 py-3 sm:grid-cols-2 sm:px-4">
                    <div>
                        <x-input-label for="org_name" :value="__('Name')" />
                        <x-text-input id="org_name" wire:model="name" type="text" class="mt-1 block w-full" maxlength="255" />
                        <x-input-error :messages="$errors->get('name')" />
                    </div>

                    <div>
                        <x-input-label for="org_slug" :value="__('Handle')" />
                        <x-text-input id="org_slug" wire:model="slug" type="text" class="mt-1 block w-full" maxlength="255" />
                        <p class="mt-1 text-xs text-brand-moss">{{ __('Lowercase letters, numbers, dashes. URLs use the organization ID.') }}</p>
                        <x-input-error :messages="$errors->get('slug')" />
                    </div>

                    <div>
                        <x-input-label for="org_email" :value="__('Contact email')" />
                        <x-text-input id="org_email" wire:model="email" type="email" class="mt-1 block w-full" maxlength="255" />
                        <x-input-error :messages="$errors->get('email')" />
                    </div>

                    <div>
                        <x-input-label for="org_timezone" :value="__('Timezone')" />
                        <x-select id="org_timezone" wire:model="timezone" class="mt-1 block w-full">
                            <option value="">{{ __('— None —') }}</option>
                            @foreach ($timezones as $tz)
                                <option value="{{ $tz }}">{{ $tz }}</option>
                            @endforeach
                        </x-select>
                        <x-input-error :messages="$errors->get('timezone')" />
                    </div>

                    {{-- Description spans both columns: it is the one free-text field. --}}
                    <div class="sm:col-span-2">
                        <x-input-label for="org_description" :value="__('Description')" />
                        <x-textarea id="org_description" wire:model="description" rows="2" class="mt-1 block w-full" maxlength="500" />
                        <x-input-error :messages="$errors->get('description')" />
                    </div>
                </div>

                <div class="flex justify-end border-t border-brand-ink/10 bg-brand-sand/10 px-3 py-2 sm:px-4">
                    <button
                        type="submit"
                        class="inline-flex h-7 items-center gap-1 rounded-md bg-brand-ink px-2.5 text-xs font-semibold text-brand-cream shadow-sm transition-colors hover:bg-brand-forest"
                    >
                        <span wire:loading.remove wire:target="saveGeneral">{{ __('Save changes') }}</span>
                        <span wire:loading wire:target="saveGeneral">{{ __('Saving…') }}</span>
                    </button>
                </div>
            </form>

            {{-- Email defaults --}}
            <section class="border-b border-brand-ink/10" id="email-defaults">
                <x-workspace-panel-head
                    dense
                    icon="heroicon-o-bell"
                    :title="__('Email defaults')"
                    :note="__('What dply emails about for projects in this organization. Notification routing for channels lives on a separate page.')"
                >
                    <x-slot:actions>
                        @can('viewNotificationChannels', $organization)
                            <x-outline-link size="xxs" href="{{ route('organizations.notification-channels', $organization) }}" wire:navigate>
                                <x-heroicon-o-bell class="h-3.5 w-3.5 shrink-0 opacity-90" aria-hidden="true" />
                                {{ __('Notification channels') }}
                            </x-outline-link>
                        @endcan
                    </x-slot:actions>
                </x-workspace-panel-head>
                <div class="divide-y divide-brand-ink/10">
                    <label class="flex cursor-pointer items-start gap-3 px-3 py-2 transition-colors hover:bg-brand-sand/15 sm:px-4">
                        <input type="checkbox" wire:model.live="deploy_email_notifications_enabled" class="mt-0.5 h-4 w-4 rounded border-brand-ink/30 text-brand-forest focus:ring-brand-forest" />
                        <span class="min-w-0 flex-1">
                            <span class="text-sm font-medium text-brand-ink">{{ __('Deploy-finish emails') }}</span>
                            <span class="mt-1 block text-xs leading-relaxed text-brand-moss">{{ __('Notify the deployer when a site\'s deploy completes or fails.') }}</span>
                        </span>
                    </label>
                </div>
            </section>

            {{-- Edge data region --}}
            <section class="border-b border-brand-ink/10" id="data-region">
                <x-workspace-panel-head
                    dense
                    icon="heroicon-o-globe-europe-africa"
                    :title="__('Edge data region')"
                    :note="__('Preferred Cloudflare R2 region for buckets created on behalf of this organization. Existing buckets stay where they are — the setting only applies to future Edge bootstraps.')"
                />
                <div class="space-y-2 px-3 py-3 sm:px-4">
                    <select wire:model.live="edge_data_region" class="block w-full max-w-md rounded-lg border-brand-ink/15 bg-white text-sm shadow-sm focus:border-brand-forest focus:ring-brand-forest">
                        <option value="default">{{ __('Default — Cloudflare picks the region') }}</option>
                        <option value="eu">{{ __('EU — strict EU jurisdiction (R2 EU jurisdiction)') }}</option>
                        <option value="weur">{{ __('Western Europe (weur)') }}</option>
                        <option value="eeur">{{ __('Eastern Europe (eeur)') }}</option>
                        <option value="wnam">{{ __('Western North America (wnam)') }}</option>
                        <option value="enam">{{ __('Eastern North America (enam)') }}</option>
                        <option value="apac">{{ __('Asia-Pacific (apac)') }}</option>
                        <option value="oc">{{ __('Oceania (oc)') }}</option>
                    </select>
                    <p class="text-xs text-brand-mist">{{ __('Selecting "EU" creates buckets in Cloudflare\'s EU jurisdiction — data is stored in the EU and the EU jurisdiction header is set on every request.') }}</p>
                </div>
            </section>

            {{-- API tokens — inventory only.
                 Issuing used to happen on the old Automation tab, from a
                 four-preset scope dropdown. That was a weaker duplicate of the
                 API keys settings page: it skipped the paid-plan gate, skipped
                 the deployer ability cap, and wrote no api_token.created audit
                 record, so a full-access ['*'] token could be minted with no
                 trail. Creation now lives in one place (Settings\ApiKeys); this
                 section keeps the org-wide view admins need — every member's
                 tokens, not just your own — plus revoke. --}}
            <section class="border-b border-brand-ink/10" id="api-tokens">
                <x-workspace-panel-head
                    dense
                    icon="heroicon-o-key"
                    :title="__('API tokens')"
                    :count="$tokensCount ?: null"
                    :note="__('Every token issued for this organization, across all members. Issue new ones from your API keys settings.')"
                >
                    <x-slot:actions>
                        <x-outline-link size="xxs" href="{{ route('profile.api-keys') }}" wire:navigate>
                            <x-heroicon-o-plus class="h-3.5 w-3.5 shrink-0 opacity-90" aria-hidden="true" />
                            {{ __('Create token') }}
                        </x-outline-link>
                    </x-slot:actions>
                </x-workspace-panel-head>

                @if ($organization->apiTokens->isEmpty())
                    <div class="px-3 py-8 text-center sm:px-4">
                        <span class="mx-auto inline-flex h-10 w-10 items-center justify-center rounded-xl bg-brand-sand/45 text-brand-mist ring-1 ring-brand-ink/10">
                            <x-heroicon-o-key class="h-5 w-5" aria-hidden="true" />
                        </span>
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
                    <ul class="divide-y divide-brand-ink/10">
                        @foreach ($organization->apiTokens as $apiToken)
                            <li wire:key="org-api-token-{{ $apiToken->id }}" class="flex items-center justify-between gap-3 px-3 py-2 transition-colors hover:bg-brand-sand/15 sm:px-4">
                                <div class="min-w-0 flex-1">
                                    <p class="truncate text-sm font-semibold text-brand-ink">{{ $apiToken->name }}</p>
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
                                    class="shrink-0 text-xs font-medium text-red-600 hover:text-red-700 hover:underline"
                                >
                                    {{ __('Revoke') }}
                                </button>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </section>

            {{-- Danger zone --}}
            @if ($canDelete)
                <section>
                    <x-workspace-panel-head
                        dense
                        tone="danger"
                        icon="heroicon-o-exclamation-triangle"
                        :title="__('Delete organization')"
                        :note="__('Remove all servers and sites and cancel any subscription first. This cannot be undone.')"
                    />

                    {{-- Confirm field and button on one row: the modal restates the
                         consequence anyway, so this strip only needs to gate it. --}}
                    <div class="flex flex-col gap-2 bg-rose-50/40 px-3 py-2.5 sm:flex-row sm:items-end sm:px-4">
                        <div class="min-w-0 flex-1 sm:max-w-sm">
                            <x-input-label for="delete_confirm" :value="__('Type the organization name to confirm')" />
                            <x-text-input id="delete_confirm" wire:model.live="delete_confirm" type="text" class="mt-1 block w-full" placeholder="{{ $organization->name }}" autocomplete="off" />
                            <x-input-error :messages="$errors->get('delete_confirm')" />
                        </div>
                        <button
                            type="button"
                            x-on:click="$dispatch('open-modal', 'delete-organization-confirmation')"
                            wire:loading.attr="disabled"
                            wire:target="deleteOrganization"
                            @disabled($delete_confirm !== $organization->name)
                            class="inline-flex h-8 shrink-0 items-center gap-1 rounded-md border border-red-300 bg-red-600 px-2.5 text-xs font-semibold text-white shadow-sm transition-colors hover:bg-red-700 disabled:cursor-not-allowed disabled:opacity-50 sm:ms-auto"
                        >
                            <x-heroicon-o-trash class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                            {{ __('Delete organization') }}
                        </button>
                    </div>
                </section>
            @endif
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
                    <p>{{ __('This permanently deletes :name and its settings. Servers, sites, and any active subscription must already be cleared. This cannot be undone.', ['name' => $organization->name]) }}</p>
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
