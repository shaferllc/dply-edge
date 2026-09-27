<div>
    <section class="border-b border-brand-ink/10 px-5 py-4 sm:px-6">
        @include('livewire.sites.edge.workspace.partials.feature-guide', [
            'docSlug' => 'app-members',
            'what' => __('Grant site-scoped access on this Edge site without making someone an org admin. Org owners and admins already have full access.'),
            'steps' => [
                __('Pick an org user who is not already a site member.'),
                __('Choose a role, then Add.'),
                __('Remove a member when they no longer need this site — org roles stay unchanged.'),
            ],
            'tips' => [
                __('Prefer site members for contractors who only touch one Edge app.'),
                __('Deploy-oriented roles cannot change notification subscriptions or destructive settings.'),
            ],
        ])
    </section>

    @can('manageMembers', $site)
        <form wire:submit="addMember" class="grid gap-3 border-b border-brand-ink/10 px-5 py-4 sm:grid-cols-[minmax(14rem,1fr)_10rem_auto] sm:items-end sm:px-6">
            <label class="block min-w-0">
                <span class="text-2xs font-semibold uppercase tracking-[0.16em] text-brand-mist">{{ __('Member') }}</span>
                <select wire:model="member_user_id" class="mt-1.5 block w-full rounded-lg border border-brand-ink/15 bg-white px-3 py-2 text-sm shadow-sm focus:border-brand-sage focus:ring-brand-sage dark:border-brand-mist/20 dark:bg-zinc-900">
                    <option value="">{{ __('Select…') }}</option>
                    @foreach ($eligibleUsers as $user)
                        <option value="{{ $user->id }}">{{ $user->name }} ({{ $user->email }})</option>
                    @endforeach
                </select>
                <x-input-error :messages="$errors->get('member_user_id')" class="mt-1" />
            </label>
            <label class="block">
                <span class="text-2xs font-semibold uppercase tracking-[0.16em] text-brand-mist">{{ __('Role') }}</span>
                <select wire:model="member_role" class="mt-1.5 block w-full rounded-lg border border-brand-ink/15 bg-white px-3 py-2 text-sm shadow-sm focus:border-brand-sage focus:ring-brand-sage dark:border-brand-mist/20 dark:bg-zinc-900">
                    @foreach ($roleOptions as $value => $label)
                        <option value="{{ $value }}">{{ $label }}</option>
                    @endforeach
                </select>
            </label>
            <x-primary-button type="submit" wire:loading.attr="disabled" wire:target="addMember" class="shrink-0">
                <span wire:loading.remove wire:target="addMember">{{ __('Add') }}</span>
                <span wire:loading wire:target="addMember">{{ __('Adding…') }}</span>
            </x-primary-button>
        </form>
    @endcan

    @if ($members->isEmpty())
        <p class="px-5 py-8 text-center text-sm text-brand-moss sm:px-6">
            {{ __('No site-specific members yet.') }}
            <span class="mt-1 block text-xs">{{ __('Org owners and admins already have full access.') }}</span>
        </p>
    @else
        <ul class="divide-y divide-brand-ink/8 border-b border-brand-ink/10">
            @foreach ($members as $member)
                <li class="flex flex-wrap items-center justify-between gap-3 px-5 py-3 sm:px-6" wire:key="edge-member-{{ $member->id }}">
                    <div class="min-w-0">
                        <p class="text-sm font-semibold text-brand-ink">{{ $member->user?->name ?? __('Unknown user') }}</p>
                        <p class="text-xs text-brand-moss">{{ $member->user?->email }}</p>
                    </div>
                    <div class="flex flex-wrap items-center gap-2">
                        @can('manageMembers', $site)
                            <select
                                class="rounded-lg border border-brand-ink/15 bg-white px-2 py-1 text-xs font-semibold text-brand-ink dark:border-brand-mist/20 dark:bg-zinc-900"
                                wire:change="updateMemberRole('{{ $member->id }}', $event.target.value)"
                            >
                                @foreach ($roleOptions as $value => $label)
                                    <option value="{{ $value }}" @selected($member->role === $value)>{{ $label }}</option>
                                @endforeach
                            </select>
                            <button
                                type="button"
                                wire:click="openConfirmActionModal('removeMember', @js([(string) $member->id]), @js(__('Remove member')), @js(__('Remove :name from this site? They keep their org role.', ['name' => $member->user?->name ?? __('this member')])), @js(__('Remove')), true)"
                                class="text-xs font-medium text-rose-700 hover:text-rose-900 dark:text-rose-400"
                            >
                                {{ __('Remove') }}
                            </button>
                        @else
                            <span class="rounded-full bg-brand-sand/60 px-2.5 py-0.5 text-xs font-semibold uppercase tracking-wide text-brand-forest">
                                {{ $roleOptions[$member->role] ?? $member->role }}
                            </span>
                        @endcan
                    </div>
                </li>
            @endforeach
        </ul>
    @endif

    <details class="group">
        <summary class="flex cursor-pointer list-none items-center justify-between gap-3 bg-brand-sand/10 px-5 py-3.5 text-sm font-semibold text-brand-ink hover:bg-brand-sand/20 sm:px-6 [&::-webkit-details-marker]:hidden">
            <span>{{ __('About roles') }}</span>
            <x-heroicon-m-chevron-down class="h-4 w-4 text-brand-mist transition group-open:rotate-180" />
        </summary>
        <div class="space-y-2 border-t border-brand-ink/10 px-5 py-4 text-sm text-brand-moss sm:px-6">
            <p>{{ __('Site grants elevate access for this Edge site only — they never restrict org admins.') }}</p>
            <ul class="list-disc space-y-1 pl-5 text-xs">
                <li><span class="font-semibold text-brand-ink">{{ __('Viewer') }}</span> — {{ __('read-only') }}</li>
                <li><span class="font-semibold text-brand-ink">{{ __('Deployer') }}</span> — {{ __('deploy + environment') }}</li>
                <li><span class="font-semibold text-brand-ink">{{ __('Admin') }}</span> — {{ __('full site control') }}</li>
            </ul>
        </div>
    </details>

    @include('livewire.partials.confirm-action-modal')
</div>
