@php
    $isAdmin = $organization->hasAdminAccess(auth()->user());
    $teamCount = $organization->teams->count();
    $totalMemberSlots = $organization->teams->sum(fn ($t) => $t->users->count());
    $orgMemberCount = $organization->users->count();
    $pendingTeamInvites = $organization->invitations->whereNotNull('team_id')->count();

    // Organization role per user, so a team row can show the role that actually
    // governs permissions — team membership itself grants nothing extra.
    $orgRoles = $organization->users->mapWithKeys(fn ($u) => [$u->id => $u->pivot->role]);

    // Same role vocabulary as the Members directory: owner / admin pop, the
    // rest stay neutral so a team reads as a calm list.
    $roleClasses = fn (?string $role): string => match (strtolower((string) $role)) {
        'owner' => 'border-brand-sage/35 bg-brand-sage/15 text-brand-forest',
        'admin' => 'border-amber-200 bg-amber-50 text-amber-900',
        'deployer' => 'border-sky-200 bg-sky-50 text-sky-700',
        default => 'border-brand-ink/10 bg-brand-sand/50 text-brand-moss',
    };

    $initialsOf = fn ($user): string => strtoupper(
        collect(preg_split('/\s+/', trim((string) $user->name)))->filter()->map(fn ($w) => mb_substr($w, 0, 1))->take(2)->implode('')
            ?: mb_substr((string) ($user->email ?? '?'), 0, 1)
    );
@endphp

<div>
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
        <x-organization-shell
            :organization="$organization"
            section="teams"
            :title="__('Teams')"
            :description="__('Group members to scope servers, sites, and notifications. Each member can belong to multiple teams.')"
            icon="heroicon-o-rectangle-group"
            :breadcrumb="[
                ['label' => __('Dashboard'), 'href' => route('dashboard'), 'icon' => 'home'],
                ['label' => $organization->name, 'href' => route('organizations.show', $organization), 'icon' => 'building-office-2'],
                ['label' => __('Teams'), 'icon' => 'rectangle-group'],
            ]"
        >
            <x-slot:actions>
                <x-docs-link slug="org-roles-and-limits" class="!h-6 !gap-1 !rounded-md !px-2 !py-0 !text-xs !font-semibold">
                    <x-heroicon-o-queue-list class="h-3.5 w-3.5 shrink-0 opacity-90" aria-hidden="true" />
                    {{ __('Roles & limits') }}
                </x-docs-link>
                <x-outline-link href="{{ route('organizations.members', $organization) }}" wire:navigate size="xxs">
                    <x-heroicon-o-user-group class="h-3.5 w-3.5 shrink-0 opacity-90" aria-hidden="true" />
                    {{ __('Members') }}
                </x-outline-link>
                @if ($isAdmin)
                    <button
                        type="button"
                        wire:click="openCreateTeamModal"
                        class="inline-flex h-6 items-center gap-1 rounded-lg bg-brand-ink px-2.5 text-xs font-semibold text-brand-cream shadow-sm transition-colors hover:bg-brand-forest"
                    >
                        <x-heroicon-o-plus class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                        {{ __('Create team') }}
                    </button>
                @endif
            </x-slot:actions>

            <x-slot:stats>
                <dl class="grid grid-cols-3 gap-px bg-brand-ink/5" aria-label="{{ __('Teams at a glance') }}">
                    <div class="bg-white px-3 py-2">
                        <dt class="text-2xs font-semibold uppercase tracking-wide text-brand-mist">{{ __('Teams') }}</dt>
                        <dd class="mt-0.5 font-mono text-base font-semibold tabular-nums text-brand-ink">{{ $teamCount }}</dd>
                    </div>
                    <div class="bg-white px-3 py-2">
                        <dt class="text-2xs font-semibold uppercase tracking-wide text-brand-mist">{{ __('Memberships') }}</dt>
                        <dd class="mt-0.5 font-mono text-base font-semibold tabular-nums text-brand-ink">{{ $totalMemberSlots }}</dd>
                    </div>
                    <div class="bg-white px-3 py-2">
                        <dt class="text-2xs font-semibold uppercase tracking-wide text-brand-mist">{{ __('Members') }}</dt>
                        <dd class="mt-0.5 flex items-baseline gap-1.5">
                            <span class="font-mono text-base font-semibold tabular-nums text-brand-ink">{{ $orgMemberCount }}</span>
                            <a href="{{ route('organizations.members', $organization) }}" wire:navigate class="text-2xs font-semibold text-brand-sage hover:text-brand-ink">{{ __('Manage') }} →</a>
                        </dd>
                    </div>
                </dl>
            </x-slot:stats>

            @if ($errors->isNotEmpty())
                <div class="border-b border-brand-ink/10 px-5 py-4 sm:px-6">
                    <x-livewire-validation-errors />
                </div>
            @endif

            {{-- How teams work. Collapsible (state remembered per org) so the
                 explanation is there the first time and out of the way after. --}}
            <div
                class="border-b border-brand-ink/10 bg-brand-cream/40"
                x-data="{
                    _k: 'dply.teams.howItWorksCollapsed:{{ $organization->id }}',
                    collapsed: false,
                    init() { try { this.collapsed = JSON.parse(localStorage.getItem(this._k)) || false; } catch (e) { this.collapsed = false; } },
                    toggle() { this.collapsed = ! this.collapsed; localStorage.setItem(this._k, JSON.stringify(this.collapsed)); },
                }"
            >
                <button
                    type="button"
                    x-on:click="toggle()"
                    :aria-expanded="(! collapsed).toString()"
                    class="flex w-full items-center gap-1.5 px-5 py-2 text-left sm:px-6"
                >
                    <span x-bind:class="collapsed ? '' : 'rotate-90'" class="inline-flex text-brand-mist transition-transform">
                        <x-heroicon-o-chevron-right class="h-3.5 w-3.5" aria-hidden="true" />
                    </span>
                    <span class="text-xs font-semibold uppercase tracking-[0.14em] text-brand-sage">{{ __('How teams work') }}</span>
                    <span class="ms-auto text-2xs text-brand-mist" x-show="collapsed">{{ __('Show') }}</span>
                </button>

                <div x-show="! collapsed" x-collapse>
                    <dl class="grid gap-px bg-brand-ink/5 sm:grid-cols-3">
                        <div class="bg-brand-cream/40 px-5 py-3 sm:px-6">
                            <dt class="flex items-center gap-1.5 text-xs font-semibold text-brand-ink">
                                <x-heroicon-o-bell class="h-4 w-4 shrink-0 text-brand-sage" aria-hidden="true" />
                                {{ __('Scoped alerts') }}
                            </dt>
                            <dd class="mt-1 text-xs leading-relaxed text-brand-moss">
                                {{ __('Every team has its own notification channels, so an alert reaches the group that owns the server instead of everyone.') }}
                            </dd>
                        </div>
                        <div class="bg-brand-cream/40 px-5 py-3 sm:px-6">
                            <dt class="flex items-center gap-1.5 text-xs font-semibold text-brand-ink">
                                <x-heroicon-o-rectangle-group class="h-4 w-4 shrink-0 text-brand-sage" aria-hidden="true" />
                                {{ __('Grouping, not permissions') }}
                            </dt>
                            <dd class="mt-1 text-xs leading-relaxed text-brand-moss">
                                {{ __('Teams group people; what they can change comes from their organization role. One person can sit on several teams.') }}
                            </dd>
                        </div>
                        <div class="bg-brand-cream/40 px-5 py-3 sm:px-6">
                            <dt class="flex items-center gap-1.5 text-xs font-semibold text-brand-ink">
                                <x-heroicon-o-envelope class="h-4 w-4 shrink-0 text-brand-sage" aria-hidden="true" />
                                {{ __('Two ways in') }}
                            </dt>
                            <dd class="mt-1 text-xs leading-relaxed text-brand-moss">
                                {{ __('Add pulls from people already in the organization. Invite emails someone new — accepting joins the organization and the team at once.') }}
                            </dd>
                        </div>
                    </dl>
                </div>
            </div>

            {{-- Directory. Flush hairline strips (one per team) rather than nested
                 cards — the dense panel head already carries the title, the
                 description, and the primary "Create team" action, so the list
                 starts straight after the stats strip with no section header. --}}
            <section class="border-b border-brand-ink/10 last:border-b-0">
                @if ($organization->teams->isEmpty())
                    <div class="px-5 py-10 text-center sm:px-6">
                        <span class="mx-auto inline-flex h-10 w-10 items-center justify-center rounded-xl bg-brand-sand/45 text-brand-mist ring-1 ring-brand-ink/10">
                            <x-heroicon-o-rectangle-group class="h-5 w-5" aria-hidden="true" />
                        </span>
                        <p class="mt-3 text-sm font-medium text-brand-ink">{{ __('No teams yet.') }}</p>
                        <p class="mx-auto mt-1 max-w-md text-xs leading-relaxed text-brand-mist">
                            {{ __('A team is a named group of people — “Platform”, “On-call”, “Customer success”. Give it its own notification channels so the right group hears about the servers they own.') }}
                        </p>
                        @if ($isAdmin)
                            <button type="button" wire:click="openCreateTeamModal" class="mt-3 inline-flex items-center gap-1.5 text-xs font-semibold text-brand-sage hover:text-brand-ink">
                                <x-heroicon-o-plus class="h-4 w-4 shrink-0" aria-hidden="true" />
                                {{ __('Create your first team') }}
                            </button>
                        @endif
                    </div>
                @else
                    <ul>
                        @foreach ($organization->teams as $team)
                            @php
                                $membersAvailableToAdd = $organization->users->diff($team->users);
                                $teamInvites = $organization->invitations->where('team_id', $team->id);
                            @endphp
                            <li class="border-b border-brand-ink/10 last:border-b-0 dark:border-brand-mist/15">
                                {{-- One strip carries the whole team: editable name, member
                                     count, add-member control, notifications, delete. --}}
                                <div class="flex flex-wrap items-center gap-x-3 gap-y-2 bg-brand-sand/25 px-5 py-2 sm:px-6 dark:bg-zinc-800/60">
                                    <input
                                        type="text"
                                        wire:model="teamNames.{{ $team->id }}"
                                        wire:blur="promptSaveTeamNameOnBlur(@js($team->id))"
                                        class="-mx-1.5 me-auto w-full min-w-0 max-w-xs rounded-md border-0 bg-transparent px-1.5 py-0.5 text-sm font-semibold text-brand-ink transition-colors hover:bg-white/60 focus:bg-white focus:ring-1 focus:ring-brand-ink/15 dark:text-brand-ink dark:hover:bg-zinc-900/60 dark:focus:bg-zinc-900"
                                        aria-label="{{ __('Team name') }}"
                                    />
                                    <span class="ms-auto shrink-0 rounded-md border border-brand-ink/10 bg-white/70 px-1.5 py-0.5 text-2xs font-semibold uppercase tracking-wide text-brand-moss dark:border-brand-mist/20 dark:bg-zinc-900/60">
                                        {{ trans_choice(':count member|:count members', $team->users->count()) }}
                                    </span>
                                    @if ($teamInvites->isNotEmpty())
                                        <span class="shrink-0 rounded-md border border-amber-200 bg-amber-50 px-1.5 py-0.5 text-2xs font-semibold uppercase tracking-wide text-amber-900">
                                            {{ trans_choice(':count invited|:count invited', $teamInvites->count()) }}
                                        </span>
                                    @endif
                                    @if ($isAdmin && $membersAvailableToAdd->isNotEmpty())
                                        <div class="inline-flex shrink-0 items-center gap-1.5">
                                            <select wire:model.live="addMemberSelected.{{ $team->id }}" class="h-6 rounded-md border-brand-ink/15 bg-white py-0 text-xs shadow-sm dark:border-brand-mist/30 dark:bg-zinc-900">
                                                <option value="">{{ __('Add member…') }}</option>
                                                @foreach ($membersAvailableToAdd as $u)
                                                    <option value="{{ $u->id }}">{{ $u->name }}</option>
                                                @endforeach
                                            </select>
                                            <button type="button" wire:click="addTeamMember(@js($team->id))" class="inline-flex h-6 items-center gap-1 rounded-md border border-brand-ink/15 bg-white px-2 text-xs font-semibold text-brand-ink shadow-sm transition-colors hover:bg-brand-sand/50 disabled:cursor-not-allowed disabled:opacity-50">
                                                <x-heroicon-o-plus class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                                                {{ __('Add') }}
                                            </button>
                                        </div>
                                    @endif
                                    @if ($isAdmin)
                                        {{-- Invite reaches people who aren't in the org yet;
                                             "Add member…" only covers existing members. --}}
                                        <button
                                            type="button"
                                            wire:click="openInviteModal(@js($team->id))"
                                            class="inline-flex h-6 shrink-0 items-center gap-1 rounded-md border border-brand-ink/15 bg-white px-2 text-xs font-semibold text-brand-ink shadow-sm transition-colors hover:bg-brand-sand/50"
                                        >
                                            <x-heroicon-o-envelope class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                                            {{ __('Invite') }}
                                        </button>
                                    @endif
                                    <a
                                        href="{{ route('teams.notification-channels', [$organization, $team]) }}"
                                        wire:navigate
                                        class="inline-flex h-6 shrink-0 items-center gap-1 rounded-md border border-brand-ink/15 bg-white px-2 text-xs font-semibold text-brand-ink shadow-sm transition-colors hover:bg-brand-sand/50"
                                        title="{{ __('Team notification channels') }}"
                                    >
                                        <x-heroicon-o-bell class="h-3.5 w-3.5 shrink-0 opacity-90" aria-hidden="true" />
                                        {{ __('Notifications') }}
                                    </a>
                                    @if ($isAdmin)
                                        <button
                                            type="button"
                                            wire:click="promptDeleteTeam(@js($team->id))"
                                            class="inline-flex h-6 shrink-0 items-center gap-1 rounded-md border border-brand-ink/15 bg-white px-2.5 text-xs font-semibold text-brand-moss shadow-sm transition hover:border-rose-200 hover:bg-rose-50 hover:text-rose-700"
                                        >
                                            <x-heroicon-o-trash class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                                            {{ __('Delete') }}
                                        </button>
                                    @endif
                                </div>
                                @error('teamNames.'.$team->id)
                                    <p class="bg-red-50/60 px-5 py-1.5 text-xs text-red-700 sm:px-6">{{ $message }}</p>
                                @enderror

                                <ul class="divide-y divide-brand-ink/10 dark:divide-brand-mist/15">
                                    @forelse ($team->users as $member)
                                        <li class="group flex items-center gap-3 px-5 py-2 transition-colors hover:bg-brand-sand/15 sm:px-6 dark:hover:bg-zinc-800/50">
                                            <span class="flex h-7 w-7 shrink-0 items-center justify-center rounded-full bg-brand-sand/55 text-2xs font-semibold text-brand-forest ring-1 ring-brand-ink/10">
                                                {{ $initialsOf($member) }}
                                            </span>
                                            <div class="min-w-0 flex-1">
                                                <span class="text-sm font-medium text-brand-ink">{{ $member->name }}</span>
                                                <span class="mt-0.5 block truncate text-xs text-brand-moss sm:mt-0 sm:ml-2 sm:inline">{{ $member->email }}</span>
                                            </div>
                                            {{-- Organization role, not a team role — the chip is the
                                                 answer to "what can this person actually do?". --}}
                                            <span class="shrink-0 rounded-md border px-1.5 py-0.5 text-2xs font-semibold uppercase tracking-wide {{ $roleClasses($orgRoles[$member->id] ?? null) }}">
                                                {{ $orgRoles[$member->id] ?? __('member') }}
                                            </span>
                                            @if ($isAdmin)
                                                <button
                                                    type="button"
                                                    wire:click="promptRemoveTeamMember(@js($team->id), @js($member->id))"
                                                    class="inline-flex h-6 shrink-0 items-center gap-1 rounded-md border border-brand-ink/15 bg-white px-2.5 text-xs font-semibold text-brand-moss shadow-sm transition hover:border-rose-200 hover:bg-rose-50 hover:text-rose-700"
                                                    aria-label="{{ __('Remove :name from team', ['name' => $member->name]) }}"
                                                >
                                                    <x-heroicon-o-x-mark class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                                                    {{ __('Remove') }}
                                                </button>
                                            @endif
                                        </li>
                                    @empty
                                        @if ($teamInvites->isEmpty())
                                            <li class="group flex flex-wrap items-center gap-x-2 gap-y-1 px-5 py-3 text-xs text-brand-mist sm:px-6">
                                                <x-heroicon-o-user-plus class="h-4 w-4 shrink-0" aria-hidden="true" />
                                                <span>{{ __('Nobody on this team yet.') }}</span>
                                                @if ($isAdmin)
                                                    <span>
                                                        {{ $membersAvailableToAdd->isNotEmpty()
                                                            ? __('Add someone from the organization, or invite a new address by email.')
                                                            : __('Everyone in the organization is already here — invite a new address by email.') }}
                                                    </span>
                                                @endif
                                            </li>
                                        @endif
                                    @endforelse

                                    {{-- Pending team invites sit in the same list, muted:
                                         they're people who will be on this team once they
                                         accept, so hiding them elsewhere just makes the
                                         count look wrong. --}}
                                    @foreach ($teamInvites as $invite)
                                        <li class="group flex items-center gap-3 bg-amber-50/30 px-5 py-2 transition-colors hover:bg-amber-50/60 sm:px-6">
                                            <span class="flex h-7 w-7 shrink-0 items-center justify-center rounded-full bg-amber-50 text-amber-700 ring-1 ring-amber-200">
                                                <x-heroicon-o-envelope class="h-3.5 w-3.5" aria-hidden="true" />
                                            </span>
                                            <div class="min-w-0 flex-1">
                                                <span class="text-sm font-medium text-brand-moss">{{ $invite->email }}</span>
                                                <span class="mt-0.5 block text-xs text-brand-mist sm:mt-0 sm:ml-2 sm:inline">
                                                    {{ __('Invited as :role', ['role' => $invite->role]) }}@if ($invite->expires_at) · {{ __('expires :time', ['time' => $invite->expires_at->diffForHumans()]) }}@endif
                                                </span>
                                            </div>
                                            <span class="shrink-0 rounded-md border border-amber-200 bg-amber-50 px-1.5 py-0.5 text-2xs font-semibold uppercase tracking-wide text-amber-900">{{ __('Pending') }}</span>
                                            @if ($isAdmin)
                                                <button
                                                    type="button"
                                                    wire:click="promptCancelInvitation(@js($invite->id))"
                                                    class="inline-flex h-6 shrink-0 items-center gap-1 rounded-md border border-brand-ink/15 bg-white px-2.5 text-xs font-semibold text-brand-moss shadow-sm transition hover:border-rose-200 hover:bg-rose-50 hover:text-rose-700"
                                                    aria-label="{{ __('Cancel invitation for :email', ['email' => $invite->email]) }}"
                                                >
                                                    <x-heroicon-o-x-mark class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                                                    {{ __('Cancel') }}
                                                </button>
                                            @endif
                                        </li>
                                    @endforeach
                                </ul>

                                @error('team_'.$team->id)
                                    <p class="bg-red-50/60 px-5 py-1.5 text-xs text-red-700 sm:px-6">{{ $message }}</p>
                                @enderror
                            </li>
                        @endforeach
                    </ul>
                @endif
            </section>

            {{-- Footer: the two questions this page reliably raises next. --}}
            <x-slot:footer>
                <div class="flex flex-wrap items-center gap-x-4 gap-y-2 text-xs text-brand-moss">
                    <span class="inline-flex items-center gap-1.5">
                        <x-heroicon-o-information-circle class="h-4 w-4 shrink-0 text-brand-sage" aria-hidden="true" />
                        {{ __('Roles are organization-wide — being on a team doesn\'t grant extra access.') }}
                    </span>
                    <x-docs-link slug="org-roles-and-limits" class="!h-6 !gap-1 !rounded-md !px-2 !py-0 !text-xs !font-semibold">
                        {{ __('Roles & limits') }}
                    </x-docs-link>
                    @if ($pendingTeamInvites > 0)
                        <a href="{{ route('organizations.members', $organization) }}" wire:navigate class="ms-auto font-semibold text-brand-sage hover:text-brand-ink">
                            {{ trans_choice(':count pending team invitation|:count pending team invitations', $pendingTeamInvites) }} →
                        </a>
                    @endif
                </div>
            </x-slot:footer>
        </x-organization-shell>
    </div>

    @if ($organization->hasAdminAccess(auth()->user()))
        <x-modal
            name="create-team-modal"
            :show="false"
            maxWidth="md"
            overlayClass="bg-brand-ink/30"
            panelClass="dply-modal-panel overflow-hidden shadow-xl"
            focusable
        >
            <form wire:submit="createTeam">
                <div class="flex items-start gap-3 border-b border-brand-ink/10 px-6 py-5">
                    <x-icon-badge>
                        <x-heroicon-o-rectangle-group class="h-5 w-5" aria-hidden="true" />
                    </x-icon-badge>
                    <div class="min-w-0">
                        <p class="text-xs font-semibold uppercase tracking-[0.18em] text-brand-sage">{{ __('Create team') }}</p>
                        <h2 class="mt-1 text-lg font-semibold text-brand-ink">{{ __('Name your team') }}</h2>
                        <p class="mt-1 text-sm leading-6 text-brand-moss">
                            {{ __('Teams help you scope notifications and access. You can add organization members after the team is created.') }}
                        </p>
                    </div>
                </div>

                <div class="space-y-5 px-6 py-6">
                    <div>
                        <x-input-label for="team_name_modal" :value="__('Team name')" />
                        <x-text-input
                            id="team_name_modal"
                            wire:model="team_name"
                            type="text"
                            class="mt-2 block w-full"
                            placeholder="{{ __('e.g. Platform, Customer success') }}"
                            required
                            maxlength="255"
                            autocomplete="off"
                        />
                        <x-input-error :messages="$errors->get('team_name')" class="mt-2" />
                    </div>
                </div>

                <div class="flex flex-wrap justify-end gap-3 border-t border-brand-ink/10 bg-brand-sand/25 px-6 py-4">
                    <x-secondary-button type="button" wire:click="closeCreateTeamModal">
                        {{ __('Cancel') }}
                    </x-secondary-button>
                    <x-primary-button type="submit" wire:loading.attr="disabled" wire:target="createTeam">
                        <span wire:loading.remove wire:target="createTeam" class="inline-flex items-center gap-2">
                            <x-heroicon-o-plus class="h-4 w-4 shrink-0" aria-hidden="true" />
                            {{ __('Create team') }}
                        </span>
                        <span wire:loading wire:target="createTeam" class="inline-flex items-center gap-2">
                            <x-spinner variant="cream" size="sm" />
                            {{ __('Creating…') }}
                        </span>
                    </x-primary-button>
                </div>
            </form>
        </x-modal>

        {{-- Invite straight onto a team: the invitee joins the organization and
             the team in one accept. An address that already belongs to the org
             is attached to the team on submit instead of being mailed. --}}
        @php $inviteTeam = $organization->teams->firstWhere('id', $inviteTeamId); @endphp
        <x-modal
            name="invite-to-team-modal"
            :show="false"
            maxWidth="md"
            overlayClass="bg-brand-ink/30"
            panelClass="dply-modal-panel overflow-hidden shadow-xl"
            focusable
        >
            <form wire:submit="inviteToTeam">
                <div class="flex items-start gap-3 border-b border-brand-ink/10 px-6 py-5">
                    <x-icon-badge>
                        <x-heroicon-o-envelope class="h-5 w-5" aria-hidden="true" />
                    </x-icon-badge>
                    <div class="min-w-0">
                        <p class="text-xs font-semibold uppercase tracking-[0.18em] text-brand-sage">{{ __('Invite to team') }}</p>
                        <h2 class="mt-1 text-lg font-semibold text-brand-ink">{{ $inviteTeam?->name ?? __('Invite someone') }}</h2>
                        <p class="mt-1 text-sm leading-6 text-brand-moss">
                            {{ __('They\'ll get an email inviting them to :org. Accepting joins the organization and this team.', ['org' => $organization->name]) }}
                        </p>
                    </div>
                </div>

                <div class="space-y-5 px-6 py-6">
                    <div>
                        <x-input-label for="team_invite_email_modal" :value="__('Email address')" />
                        <x-text-input
                            id="team_invite_email_modal"
                            wire:model="invite_email"
                            type="email"
                            class="mt-2 block w-full"
                            placeholder="{{ __('name@company.com') }}"
                            required
                            autocomplete="email"
                        />
                        <x-input-error :messages="$errors->get('invite_email')" class="mt-2" />
                    </div>
                    <div>
                        <x-input-label for="team_invite_role_modal" :value="__('Organization role')" />
                        <x-select id="team_invite_role_modal" wire:model="invite_role" class="mt-2">
                            @foreach ($this->inviteableRoles() as $value => $label)
                                <option value="{{ $value }}">{{ $label }}</option>
                            @endforeach
                        </x-select>
                        <p class="mt-2 text-xs leading-relaxed text-brand-moss">{{ __('The role applies to the organization. Team membership is separate.') }}</p>
                    </div>
                </div>

                <div class="flex flex-wrap justify-end gap-3 border-t border-brand-ink/10 bg-brand-sand/25 px-6 py-4">
                    <x-secondary-button type="button" wire:click="closeInviteModal">
                        {{ __('Cancel') }}
                    </x-secondary-button>
                    <x-primary-button type="submit" wire:loading.attr="disabled" wire:target="inviteToTeam">
                        <span wire:loading.remove wire:target="inviteToTeam" class="inline-flex items-center gap-2">
                            <x-heroicon-o-paper-airplane class="h-4 w-4 shrink-0" aria-hidden="true" />
                            {{ __('Send invitation') }}
                        </span>
                        <span wire:loading wire:target="inviteToTeam" class="inline-flex items-center gap-2">
                            <x-spinner variant="cream" size="sm" />
                            {{ __('Sending…') }}
                        </span>
                    </x-primary-button>
                </div>
            </form>
        </x-modal>
    @endif

    {{-- Confirm modal must live in the Livewire view tree (not only a layout slot) so state updates and wire: targets bind reliably. --}}
    @include('livewire.partials.confirm-action-modal')
</div>
