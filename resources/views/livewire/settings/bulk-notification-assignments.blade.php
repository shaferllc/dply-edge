<div>
    <x-livewire-validation-errors />

    @push('breadcrumbs')
        <x-breadcrumb-trail :items="[
            ['label' => __('Dashboard'), 'href' => route('dashboard'), 'icon' => 'home'],
            ['label' => __('Profile'), 'href' => route('settings.profile'), 'icon' => 'user-circle'],
            ['label' => __('Notification channels'), 'href' => route('profile.notification-channels'), 'icon' => 'bell-alert'],
            ['label' => __('Bulk assign notifications'), 'icon' => 'rectangle-stack'],
        ]" />
    @endpush

    <x-profile-shell
        :title="__('Bulk assign notifications')"
        :description="__('Link channels you can manage to events, then choose servers and sites in your current organization.')"
        icon="heroicon-o-paper-airplane"
    >

        @if (! $currentOrganization)
            <div class="border-b border-brand-ink/10 px-5 py-4 sm:px-6">
                <div class="rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-950">
                    {{ __('Select a current organization from the header to load servers and sites as assignment targets.') }}
                </div>
            </div>
        @endif

        @if ($contextServer || $contextSite)
            <div class="border-b border-brand-ink/10 px-5 py-4 sm:px-6">
                <div class="rounded-xl border border-brand-ink/10 bg-brand-sand/15 px-4 py-3 text-sm text-brand-ink">
                    @if ($contextServer)
                        <p>
                            {{ __('Assigning notifications for server:') }}
                            <span class="font-semibold">{{ $contextServer->name }}</span>
                        </p>
                    @endif
                    @if ($contextSite)
                        <p class="{{ $contextServer ? 'mt-1' : '' }}">
                            {{ __('Assigning notifications for site:') }}
                            <span class="font-semibold">{{ $contextSite->name }}</span>
                        </p>
                    @endif
                    <p class="mt-2 text-brand-moss">
                        {{ __('Choose channels and event types below. The matching target is already preselected for you.') }}
                    </p>
                </div>
            </div>
        @endif

        <div class="border-b border-brand-ink/10">
            <div class="flex items-start gap-3 bg-brand-sand/15 px-5 py-4 sm:px-6">
                <x-icon-badge>
                    <x-heroicon-o-bell-alert class="h-5 w-5" aria-hidden="true" />
                </x-icon-badge>
                <div class="min-w-0 flex-1">
                    <h3 class="text-base font-semibold text-brand-ink">{{ __('Notification channels') }}</h3>
                    <p class="mt-1 text-sm leading-relaxed text-brand-moss">{{ __('Select which channels to attach to the chosen events and targets.') }}</p>
                </div>
                @if ($assignableChannels->isNotEmpty())
                    <div class="flex shrink-0 gap-2">
                        <button type="button" wire:click="selectAllChannels" class="text-sm font-medium text-brand-sage hover:underline">{{ __('Select all') }}</button>
                        <button type="button" wire:click="deselectAllChannels" class="text-sm font-medium text-brand-moss hover:underline">{{ __('Deselect all') }}</button>
                    </div>
                @endif
            </div>
            <div class="max-h-64 space-y-2 overflow-y-auto px-5 py-4 sm:px-6">
                @forelse ($assignableChannels as $ch)
                    <label class="flex items-center gap-3 text-sm cursor-pointer">
                        <input type="checkbox" wire:model.live="selected_channel_ids" value="{{ $ch->id }}" class="rounded border-brand-ink/20 text-brand-sage focus:ring-brand-sage">
                        <span><span class="font-medium text-brand-ink">[{{ \App\Models\NotificationChannel::labelForType($ch->type) }}]</span> {{ $ch->label }}</span>
                    </label>
                @empty
                    <div class="space-y-4">
                        <div class="rounded-xl border border-dashed border-brand-ink/15 bg-brand-sand/10 px-4 py-4 text-sm text-brand-moss">
                            <p>{{ __('No channels available yet.') }}</p>
                            <p class="mt-2">{{ __('Create one here, then it will be selected automatically for assignment.') }}</p>
                        </div>

                        @if ($quickAddTypes !== [])
                            <div class="rounded-xl border border-brand-ink/10 bg-white px-4 py-4">
                                <div class="flex flex-wrap items-start justify-between gap-3">
                                    <div>
                                        <h3 class="text-sm font-semibold text-brand-ink">{{ __('Quick add channel') }}</h3>
                                        <p class="mt-1 text-sm text-brand-moss">{{ __('Create a destination without leaving this assignment flow.') }}</p>
                                    </div>
                                    <button
                                        type="button"
                                        wire:click="openQuickNotificationChannelModal"
                                        class="inline-flex items-center justify-center gap-2 rounded-lg border border-brand-ink/15 bg-white px-3 py-2 text-xs font-semibold uppercase tracking-wide text-brand-ink shadow-sm hover:bg-brand-sand/40"
                                    >
                                        <x-heroicon-o-plus class="h-4 w-4 shrink-0 opacity-90" />
                                        {{ __('Add channel') }}
                                    </button>
                                </div>
                            </div>
                        @endif
                    </div>
                @endforelse
            </div>
            @error('selected_channel_ids')
                <p class="px-5 pb-3 text-xs text-red-600 sm:px-6">{{ $message }}</p>
            @enderror
        </div>

        <div class="border-b border-brand-ink/10">
            <div class="flex items-start gap-3 bg-brand-sand/15 px-5 py-4 sm:px-6">
                <x-icon-badge>
                    <x-heroicon-o-bell class="h-5 w-5" aria-hidden="true" />
                </x-icon-badge>
                <div class="min-w-0 flex-1">
                    <h3 class="text-base font-semibold text-brand-ink">{{ __('Notification type') }}</h3>
                    <p class="mt-1 text-sm leading-relaxed text-brand-moss">{{ __('Choose which events should trigger notifications on the selected channels.') }}</p>
                </div>
                <div class="flex shrink-0 gap-2">
                    <button type="button" wire:click="selectAllEvents" class="text-sm font-medium text-brand-sage hover:underline">{{ __('Select all') }}</button>
                    <button type="button" wire:click="deselectAllEvents" class="text-sm font-medium text-brand-moss hover:underline">{{ __('Deselect all') }}</button>
                </div>
            </div>
            <div class="space-y-6 px-5 py-4 sm:px-6">
                @foreach ($eventCatalog as $catKey => $cat)
                    <div>
                        <p class="text-xs font-semibold uppercase tracking-wider text-brand-mist mb-2">{{ $cat['label'] }}</p>
                        <ul class="space-y-2">
                            @foreach ($cat['events'] as $eventKey => $eventLabel)
                                <li>
                                    <label class="flex items-center gap-3 text-sm cursor-pointer">
                                        <input type="checkbox" wire:model.live="selected_event_keys" value="{{ $eventKey }}" class="rounded border-brand-ink/20 text-brand-sage focus:ring-brand-sage">
                                        <span>{{ $eventLabel }}</span>
                                    </label>
                                </li>
                            @endforeach
                        </ul>
                    </div>
                @endforeach
            </div>
            @error('selected_event_keys')
                <p class="px-5 pb-3 text-xs text-red-600 sm:px-6">{{ $message }}</p>
            @enderror
        </div>

        <div class="border-b border-brand-ink/10">
            <div class="flex items-start gap-3 bg-brand-sand/15 px-5 py-4 sm:px-6">
                <x-icon-badge>
                    <x-heroicon-o-server-stack class="h-5 w-5" aria-hidden="true" />
                </x-icon-badge>
                <div class="min-w-0 flex-1">
                    <h3 class="text-base font-semibold text-brand-ink">{{ __('Select targets') }}</h3>
                    <p class="mt-1 text-sm leading-relaxed text-brand-moss">{{ __('Servers apply to server events; sites apply to site and backup events.') }}</p>
                </div>
                <div class="flex shrink-0 flex-wrap gap-2">
                    <button type="button" wire:click="selectAllServers" class="text-sm font-medium text-brand-sage hover:underline">{{ __('All servers') }}</button>
                    <button type="button" wire:click="deselectAllServers" class="text-sm font-medium text-brand-moss hover:underline">{{ __('No servers') }}</button>
                    <button type="button" wire:click="selectAllSites" class="text-sm font-medium text-brand-sage hover:underline">{{ __('All sites') }}</button>
                    <button type="button" wire:click="deselectAllSites" class="text-sm font-medium text-brand-moss hover:underline">{{ __('No sites') }}</button>
                </div>
            </div>
            <div class="px-5 py-4 sm:px-6">
                @if ($selected_channel_ids === [] || $selected_event_keys === [])
                    <div class="rounded-lg border border-sky-200 bg-sky-50 px-4 py-3 text-sm text-sky-950">
                        {{ __('Select at least one channel and a notification type first.') }}
                    </div>
                @else
                    <div class="grid gap-8 md:grid-cols-2">
                        <div>
                            <p class="text-sm font-medium text-brand-ink mb-2">{{ __('Servers') }}</p>
                            <div class="space-y-2 max-h-48 overflow-y-auto">
                                @forelse ($servers as $server)
                                    <label class="flex items-center gap-3 text-sm cursor-pointer">
                                        <input type="checkbox" wire:model.live="selected_server_ids" value="{{ $server->id }}" class="rounded border-brand-ink/20 text-brand-sage focus:ring-brand-sage">
                                        <span>{{ $server->name }}</span>
                                    </label>
                                @empty
                                    <x-empty-state
                                        borderless
                                        compact
                                        icon="heroicon-o-server-stack"
                                        :title="__('No servers')"
                                        :description="__('This organization has no servers to assign channels to.')"
                                    />
                                @endforelse
                            </div>
                            @error('selected_server_ids')
                                <p class="mt-2 text-xs text-red-600">{{ $message }}</p>
                            @enderror
                        </div>
                        <div>
                            <p class="text-sm font-medium text-brand-ink mb-2">{{ __('Sites') }}</p>
                            <div class="space-y-2 max-h-48 overflow-y-auto">
                                @forelse ($sites as $site)
                                    <label class="flex items-center gap-3 text-sm cursor-pointer">
                                        <input type="checkbox" wire:model.live="selected_site_ids" value="{{ $site->id }}" class="rounded border-brand-ink/20 text-brand-sage focus:ring-brand-sage">
                                        <span>{{ $site->name }}</span>
                                    </label>
                                @empty
                                    <x-empty-state
                                        borderless
                                        compact
                                        icon="heroicon-o-globe-alt"
                                        :title="__('No sites')"
                                        :description="__('This organization has no sites to assign channels to.')"
                                    />
                                @endforelse
                            </div>
                            @error('selected_site_ids')
                                <p class="mt-2 text-xs text-red-600">{{ $message }}</p>
                            @enderror
                        </div>
                    </div>
                @endif
            </div>
        </div>

        <div class="flex justify-end px-5 py-4 sm:px-6">
            <button
                type="button"
                wire:click="assign"
                wire:loading.attr="disabled"
                wire:target="assign"
                @disabled(! $this->canSubmitAssign())
                class="inline-flex min-w-[12rem] items-center justify-center gap-2 px-5 py-2.5 rounded-xl bg-brand-ink font-semibold text-sm text-brand-cream shadow-md shadow-brand-ink/15 hover:bg-brand-forest focus:outline-none focus:ring-2 focus:ring-brand-sage focus:ring-offset-2 disabled:opacity-40 disabled:cursor-not-allowed"
            >
                <span wire:loading.remove wire:target="assign">{{ __('Assign notifications') }}</span>
                <span wire:loading wire:target="assign" class="inline-flex items-center gap-2">
                    <x-spinner variant="cream" size="sm" />
                    {{ __('Assigning…') }}
                </span>
            </button>
        </div>
    </x-profile-shell>

    <x-notification-channel-quick-add-modal
        :show="$showQuickNotificationChannelModal"
        :types="$quickAddTypes"
        :current-type="$quick_new_type"
        :can-manage-organization-notification-channels="$canManageOrganizationNotificationChannels"
        :title="__('Quick add channel')"
        :description="__('Create a destination without leaving this assignment flow.')"
    />
</div>
