{{--
  People — members, pending invites and teams on one page (org redesign
  2026-09-27, "Members 2"). Selection is URL state: ?filter=pending|none or
  ?team=<id>; a selected team renders the team detail ("Teams 2").
  /organizations/{org}/teams redirects here with a team selected.
--}}
@php
    $me = auth()->user();
    $isAdmin = $organization->hasAdminAccess($me);
    $isOwner = $organization->memberRole($me) === 'owner';
    $users = $organization->users;
    $invitations = $organization->invitations;
    $teams = $organization->teams;
    $onATeam = $teams->flatMap(fn ($t) => $t->users->pluck('id'))->unique();
    $noTeam = $users->reject(fn ($u) => $onATeam->contains($u->id));
    $view = $selectedTeam ? 'team' : ($filter === 'pending' ? 'pending' : ($filter === 'none' ? 'none' : 'all'));
    $listed = $view === 'none' ? $noTeam : $users;

    // Role chips: owner / admin / deployer pop, member / viewer stay neutral.
    $roleClasses = fn (?string $role): string => match (strtolower((string) $role)) {
        'owner' => 'bg-brand-sage/15 text-brand-forest',
        'admin' => 'bg-amber-500/15 text-amber-700 dark:text-amber-300',
        'deployer' => 'bg-sky-500/15 text-sky-700 dark:text-sky-300',
        default => 'bg-brand-sand/50 text-brand-moss',
    };
    $chip = 'inline-flex shrink-0 items-center rounded-md px-1.5 py-0.5 text-2xs font-semibold uppercase tracking-wide';
    $teamChip = 'shrink-0 items-center gap-1 rounded-md bg-brand-sand/50 px-1.5 py-0.5 text-2xs font-semibold text-brand-moss';
    $btn = 'inline-flex h-7 shrink-0 items-center gap-1 rounded-lg border border-brand-ink/15 bg-white px-2.5 text-xs font-semibold text-brand-ink shadow-sm transition-colors hover:bg-brand-sand/40';
    $btnDanger = 'inline-flex h-7 shrink-0 items-center gap-1 rounded-lg border border-brand-ink/15 bg-white px-2.5 text-xs font-semibold text-brand-moss shadow-sm transition hover:border-rose-300 hover:text-rose-700 dark:hover:text-rose-300';
    $label = 'text-2xs font-semibold uppercase tracking-[0.14em] text-brand-mist';

    $initialsOf = fn ($user): string => strtoupper(
        collect(preg_split('/\s+/', trim((string) $user->name)))->filter()->map(fn ($w) => mb_substr($w, 0, 1))->take(2)->implode('')
            ?: mb_substr((string) ($user->email ?? '?'), 0, 1)
    );
    $orgRoles = $users->mapWithKeys(fn ($u) => [$u->id => strtolower((string) $u->pivot->role)]);
    $roleTally = $orgRoles->countBy();

    $railItem = fn (bool $on): string => 'flex w-full items-center gap-2 rounded-lg px-3 py-2 text-left text-sm transition-colors '
        .($on ? 'bg-brand-sand/70 font-semibold text-brand-ink' : 'text-brand-moss hover:bg-brand-sand/40 hover:text-brand-ink');
@endphp

<div>
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
        <x-organization-shell
            :organization="$organization"
            :section="$selectedTeam ? 'teams' : 'members'"
            :breadcrumb="[
                ['label' => __('Dashboard'), 'href' => route('dashboard'), 'icon' => 'home'],
                ['label' => $organization->name, 'href' => route('organizations.show', $organization), 'icon' => 'building-office-2'],
                ['label' => __('People'), 'icon' => 'users'],
            ]"
        >
            <div class="space-y-6">
                <div class="flex flex-wrap items-start justify-between gap-4">
                    <div class="min-w-0">
                        <h1 class="text-2xl font-semibold tracking-tight text-brand-ink">{{ __('People') }}</h1>
                        <p class="mt-1 text-sm text-brand-moss">{{ __('Members, their roles, and the teams alerts go to.') }}</p>
                    </div>
                    @if ($isAdmin)
                        <div class="flex flex-wrap items-center gap-2">
                            <button type="button" wire:click="openCreateTeamModal" class="inline-flex h-9 items-center gap-1.5 rounded-lg border border-brand-ink/15 bg-white px-3.5 text-sm font-semibold text-brand-ink shadow-sm transition-colors hover:bg-brand-sand/40">
                                <x-heroicon-o-plus class="h-4 w-4 shrink-0" aria-hidden="true" />
                                {{ __('New team') }}
                            </button>
                            <button type="button" wire:click="openInviteModal" class="inline-flex h-9 items-center gap-1.5 rounded-lg bg-brand-ink px-3.5 text-sm font-semibold text-brand-cream transition-colors hover:bg-brand-forest">
                                <x-heroicon-o-user-plus class="h-4 w-4 shrink-0" aria-hidden="true" />
                                {{ __('Invite people') }}
                            </button>
                        </div>
                    @endif
                </div>

                {{-- Errors with no inline home (e.g. a refused role change); form and team errors render in place. --}}
                @if (collect($errors->keys())->reject(fn ($k) => in_array($k, ['renameName', 'invite_email', 'invite_role', 'team_name'], true) || str_starts_with($k, 'team_'))->isNotEmpty())
                    <x-livewire-validation-errors />
                @endif

                <div class="grid gap-6 md:grid-cols-[13rem_minmax(0,1fr)]">
                    {{-- Left rail: filters, then one entry per team. --}}
                    <nav class="dply-card h-fit p-2" aria-label="{{ __('Filter people') }}">
                        <button type="button" wire:click="select" @class([$railItem($view === 'all')]) @if ($view === 'all') aria-current="true" @endif>
                            <span class="flex-1 truncate">{{ __('Everyone') }}</span>
                            <span class="font-mono text-xs tabular-nums text-brand-mist">{{ $users->count() }}</span>
                        </button>
                        <button type="button" wire:click="select('pending')" @class([$railItem($view === 'pending')]) @if ($view === 'pending') aria-current="true" @endif>
                            <span class="flex-1 truncate">{{ __('Pending invites') }}</span>
                            <span @class(['font-mono text-xs tabular-nums', 'font-semibold text-amber-700 dark:text-amber-300' => $invitations->isNotEmpty(), 'text-brand-mist' => $invitations->isEmpty()])>{{ $invitations->count() }}</span>
                        </button>

                        <p class="px-3 pb-1 pt-4 {{ $label }}">{{ __('Teams') }}</p>
                        @forelse ($teams as $t)
                            <button type="button" wire:key="rail-team-{{ $t->id }}" wire:click="select('', @js((string) $t->id))" @class([$railItem($selectedTeam?->is($t) ?? false)]) @if ($selectedTeam?->is($t)) aria-current="true" @endif>
                                <x-heroicon-o-rectangle-group class="h-4 w-4 shrink-0 opacity-70" aria-hidden="true" />
                                <span class="flex-1 truncate">{{ $t->name }}</span>
                                <span class="font-mono text-xs tabular-nums text-brand-mist">{{ $t->users->count() }}</span>
                            </button>
                        @empty
                            <div class="px-3 py-2">
                                <p class="text-xs font-medium text-brand-ink">{{ __('No teams yet.') }}</p>
                                @if ($isAdmin)
                                    <button type="button" wire:click="openCreateTeamModal" class="mt-1 text-xs font-semibold text-brand-sage hover:text-brand-ink">{{ __('Create your first team') }} →</button>
                                @endif
                            </div>
                        @endforelse
                        <button type="button" wire:click="select('none')" @class([$railItem($view === 'none'), 'mt-1'])>
                            <span class="flex-1 truncate">{{ __('No team') }}</span>
                            <span class="font-mono text-xs tabular-nums text-brand-mist">{{ $noTeam->count() }}</span>
                        </button>

                        <p class="mt-3 border-t border-brand-ink/10 px-3 pb-1 pt-3 text-xs leading-relaxed text-brand-moss">
                            {{ __('Teams group people so alerts reach the right group. They don’t change what anyone can access — roles do.') }}
                        </p>
                    </nav>

                    {{-- Right pane, keyed by selection so rows don't morph between views. --}}
                    <section class="dply-card min-w-0 overflow-hidden p-0" wire:key="people-pane-{{ $view }}-{{ $selectedTeam?->id }}">
                        @if ($view === 'team')
                            @php
                                $t = $selectedTeam;
                                $available = $users->reject(fn ($u) => $t->users->contains('id', $u->id));
                                $teamInvites = $invitations->where('team_id', $t->id);
                            @endphp
                            <div class="flex flex-wrap items-center gap-3 border-b border-brand-ink/10 px-5 py-4 sm:px-6">
                                @if ($renamingTeamId === (string) $t->id)
                                    <form wire:submit="saveRename" class="flex min-w-0 flex-1 flex-wrap items-center gap-2">
                                        <label for="rename-team" class="sr-only">{{ __('Team name') }}</label>
                                        <input id="rename-team" type="text" wire:model="renameName" maxlength="255" x-init="$nextTick(() => $el.focus())" x-on:keydown.escape="$wire.cancelRename()"
                                            class="min-w-0 flex-1 rounded-lg border border-brand-ink/15 bg-white px-2.5 py-1.5 text-sm font-semibold text-brand-ink focus:border-brand-sage focus:outline-none focus:ring-2 focus:ring-brand-sage/30" />
                                        <button type="submit" class="inline-flex h-8 items-center rounded-lg bg-brand-ink px-3 text-xs font-semibold text-brand-cream transition-colors hover:bg-brand-forest">{{ __('Save') }}</button>
                                        <button type="button" wire:click="cancelRename" class="{{ $btn }} h-8">{{ __('Cancel') }}</button>
                                        @error('renameName')
                                            <p class="w-full text-xs text-rose-700 dark:text-rose-300">{{ $message }}</p>
                                        @enderror
                                    </form>
                                @else
                                    <h2 class="min-w-0 flex-1 truncate text-lg font-semibold tracking-tight text-brand-ink">{{ $t->name }}</h2>
                                    @if ($isAdmin)
                                        <button type="button" wire:click="startRename(@js((string) $t->id))" class="{{ $btn }}">
                                            <x-heroicon-o-pencil-square class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                                            {{ __('Rename') }}
                                        </button>
                                        <button type="button" wire:click="promptDeleteTeam(@js((string) $t->id))" class="{{ $btnDanger }}">
                                            <x-heroicon-o-trash class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                                            {{ __('Delete') }}
                                        </button>
                                    @endif
                                @endif
                            </div>

                            {{-- Members · n --}}
                            <div class="border-b border-brand-ink/10">
                                <div class="flex flex-wrap items-center gap-2 px-5 pb-2 pt-4 sm:px-6">
                                    <h3 class="{{ $label }} me-auto">{{ __('Members') }} · {{ $t->users->count() }}</h3>
                                    @if ($isAdmin)
                                        @if ($available->isNotEmpty())
                                            <div class="inline-flex items-center gap-1.5">
                                                <label for="add-member-{{ $t->id }}" class="sr-only">{{ __('Add existing member') }}</label>
                                                <select id="add-member-{{ $t->id }}" wire:model="addMemberSelected.{{ $t->id }}" class="h-7 rounded-lg border-brand-ink/15 bg-white py-0 text-xs text-brand-ink shadow-sm">
                                                    <option value="">{{ __('Add existing member…') }}</option>
                                                    @foreach ($available as $u)
                                                        <option value="{{ $u->id }}">{{ $u->name }}</option>
                                                    @endforeach
                                                </select>
                                                <button type="button" wire:click="addTeamMember(@js((string) $t->id))" class="{{ $btn }}">
                                                    <x-heroicon-o-plus class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                                                    {{ __('Add') }}
                                                </button>
                                            </div>
                                        @endif
                                        <button type="button" wire:click="openTeamInviteModal(@js((string) $t->id))" class="{{ $btn }}">
                                            <x-heroicon-o-envelope class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                                            {{ __('Invite to team') }}
                                        </button>
                                    @endif
                                </div>
                                @error('team_'.$t->id)
                                    <p class="px-5 pb-2 text-xs text-rose-700 sm:px-6 dark:text-rose-300">{{ $message }}</p>
                                @enderror

                                <ul class="divide-y divide-brand-ink/10">
                                    @forelse ($t->users as $member)
                                        <li wire:key="team-member-{{ $member->id }}" class="flex items-center gap-3 px-5 py-2.5 sm:px-6">
                                            <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-brand-sand/55 text-2xs font-semibold text-brand-forest ring-1 ring-brand-ink/10">{{ $initialsOf($member) }}</span>
                                            <div class="min-w-0 flex-1">
                                                <span class="text-sm font-semibold text-brand-ink">{{ $member->name }}</span>
                                                <span class="block truncate text-xs text-brand-moss">{{ $member->email }}</span>
                                            </div>
                                            {{-- Organization role — the thing that governs access. --}}
                                            <span class="{{ $chip }} {{ $roleClasses($orgRoles[$member->id] ?? null) }}">{{ $orgRoles[$member->id] ?? __('member') }}</span>
                                            @if ($isAdmin)
                                                <button type="button" wire:click="promptRemoveTeamMember(@js((string) $t->id), @js((string) $member->id))" class="{{ $btnDanger }}" aria-label="{{ __('Remove :name from team', ['name' => $member->name]) }}">
                                                    {{ __('Remove') }}
                                                </button>
                                            @endif
                                        </li>
                                    @empty
                                        @if ($teamInvites->isEmpty())
                                            <li class="px-5 py-6 text-center sm:px-6">
                                                <p class="text-sm font-medium text-brand-ink">{{ __('Nobody on this team yet.') }}</p>
                                                @if ($isAdmin)
                                                    <p class="mt-1 text-xs text-brand-mist">
                                                        {{ $available->isNotEmpty()
                                                            ? __('Add someone from the organization, or invite a new address by email.')
                                                            : __('Everyone in the organization is already here — invite a new address by email.') }}
                                                    </p>
                                                @endif
                                            </li>
                                        @endif
                                    @endforelse

                                    {{-- Pending team invites: people who join this team on accept. --}}
                                    @foreach ($teamInvites as $invite)
                                        <li wire:key="team-invite-{{ $invite->id }}" class="flex items-center gap-3 px-5 py-2.5 opacity-80 sm:px-6">
                                            <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-amber-500/15 text-amber-700 dark:text-amber-300">
                                                <x-heroicon-o-envelope class="h-4 w-4" aria-hidden="true" />
                                            </span>
                                            <div class="min-w-0 flex-1">
                                                <span class="text-sm font-medium text-brand-ink">{{ $invite->email }}</span>
                                                <span class="block text-xs text-brand-mist">
                                                    {{ __('Invited as :role', ['role' => $invite->role]) }}@if ($invite->expires_at) · {{ __('expires :time', ['time' => $invite->expires_at->diffForHumans()]) }}@endif
                                                </span>
                                            </div>
                                            <span class="{{ $chip }} bg-amber-500/15 text-amber-700 dark:text-amber-300">{{ __('Invited') }}</span>
                                            @if ($isAdmin)
                                                <button type="button" wire:click="promptCancelInvitation(@js((string) $invite->id))" class="{{ $btnDanger }}" aria-label="{{ __('Cancel invitation for :email', ['email' => $invite->email]) }}">{{ __('Cancel') }}</button>
                                            @endif
                                        </li>
                                    @endforeach
                                </ul>
                            </div>

                            {{-- What this team hears about --}}
                            <div class="px-5 py-4 sm:px-6">
                                <div class="flex items-center gap-2">
                                    <h3 class="{{ $label }} me-auto">{{ __('What this team hears about') }}</h3>
                                    <a href="{{ route('teams.notification-channels', [$organization, $t]) }}" wire:navigate class="text-xs font-semibold text-brand-sage hover:text-brand-ink">{{ __('Edit routing') }} →</a>
                                </div>
                                @if ($routing === [])
                                    <p class="mt-2 text-sm text-brand-moss">{{ __('No channels yet, so alerts don’t reach this team. Add Slack, email, PagerDuty or another channel to route alerts here.') }}</p>
                                @else
                                    <ul class="mt-2 divide-y divide-brand-ink/10">
                                        @foreach ($routing as $channel)
                                            <li class="flex items-start gap-3 py-2.5">
                                                <x-dynamic-component :component="$channel['icon']" class="mt-0.5 h-4 w-4 shrink-0 text-brand-sage" aria-hidden="true" />
                                                <div class="min-w-0 flex-1">
                                                    <p class="text-sm font-semibold text-brand-ink">{{ $channel['label'] }} <span class="font-normal text-brand-mist">· {{ $channel['type'] }}@if ($channel['destination'] !== '') · {{ $channel['destination'] }}@endif</span></p>
                                                    <p class="mt-0.5 text-xs text-brand-moss">
                                                        {{ $channel['events'] === [] ? __('No events routed here yet.') : implode(', ', $channel['events']) }}
                                                    </p>
                                                </div>
                                            </li>
                                        @endforeach
                                    </ul>
                                @endif
                            </div>
                        @elseif ($view === 'pending')
                            <div class="border-b border-brand-ink/10 px-5 py-3 sm:px-6">
                                <h2 class="text-sm font-semibold text-brand-ink">{{ __('Pending invites') }}</h2>
                                <p class="mt-0.5 text-xs text-brand-moss">{{ __('Invitations expire after 7 days. Owner can\'t be granted by invite; an owner uses Make owner.') }}</p>
                            </div>
                            @if ($invitations->isEmpty())
                                <p class="px-5 py-10 text-center text-sm text-brand-moss sm:px-6">{{ __('No pending invitations.') }}</p>
                            @else
                                <ul class="divide-y divide-brand-ink/10">
                                    @foreach ($invitations as $inv)
                                        <li wire:key="invite-{{ $inv->id }}" class="flex flex-wrap items-center gap-3 px-5 py-2.5 sm:flex-nowrap sm:px-6">
                                            <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-amber-500/15 text-amber-700 dark:text-amber-300">
                                                <x-heroicon-o-envelope class="h-4 w-4" aria-hidden="true" />
                                            </span>
                                            <div class="min-w-0 flex-1">
                                                <span class="block truncate text-sm font-semibold text-brand-ink">{{ $inv->email }}</span>
                                                <span class="block text-xs text-brand-mist">
                                                    {{ $inv->expires_at ? __('Expires :time', ['time' => $inv->expires_at->diffForHumans()]) : __('Awaiting acceptance') }}
                                                </span>
                                            </div>
                                            @if ($inv->team)
                                                <span class="{{ $teamChip }} inline-flex">
                                                    <x-heroicon-o-rectangle-group class="h-3 w-3 shrink-0" aria-hidden="true" />
                                                    {{ $inv->team->name }}
                                                </span>
                                            @endif
                                            <span class="{{ $chip }} {{ $roleClasses($inv->role) }}">{{ $inv->role }}</span>
                                            @if ($isAdmin)
                                                <button type="button" wire:click="promptCancelInvitation(@js((string) $inv->id))" class="{{ $btnDanger }}">{{ __('Cancel') }}</button>
                                            @endif
                                        </li>
                                    @endforeach
                                </ul>
                            @endif
                        @else
                            @if ($view === 'none')
                                <div class="border-b border-brand-ink/10 px-5 py-3 sm:px-6">
                                    <h2 class="text-sm font-semibold text-brand-ink">{{ __('No team') }}</h2>
                                    <p class="mt-0.5 text-xs text-brand-moss">{{ __('Members who aren’t on any team yet.') }}</p>
                                </div>
                            @endif
                            @if ($listed->isEmpty())
                                <div class="px-5 py-10 text-center sm:px-6">
                                    <p class="text-sm font-medium text-brand-ink">{{ $view === 'none' ? __('Everyone is on a team.') : __('No members yet.') }}</p>
                                    @if ($isAdmin && $view === 'all')
                                        <button type="button" wire:click="openInviteModal" class="mt-2 text-xs font-semibold text-brand-sage hover:text-brand-ink">{{ __('Invite the first one') }} →</button>
                                    @endif
                                </div>
                            @else
                                <ul class="divide-y divide-brand-ink/10">
                                    @foreach ($listed as $user)
                                        @php
                                            $role = $orgRoles[$user->id];
                                            $isSelf = (string) $user->id === (string) $me->id;
                                            // Admins manage everyone but owners; owners manage everyone. Your own row offers Leave.
                                            $canManageRow = $isAdmin && ! $isSelf && ($role !== 'owner' || $isOwner);
                                            $userTeams = $teams->filter(fn ($t) => $t->users->contains('id', $user->id));
                                        @endphp
                                        <li wire:key="org-member-{{ $user->id }}" class="flex flex-wrap items-center gap-3 px-5 py-2.5 sm:flex-nowrap sm:px-6">
                                            <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-brand-sand/55 text-2xs font-semibold text-brand-forest ring-1 ring-brand-ink/10">{{ $initialsOf($user) }}</span>
                                            <div class="min-w-0 flex-1">
                                                <span class="text-sm font-semibold text-brand-ink">{{ $user->name }}@if ($isSelf) <span class="font-normal text-brand-mist">({{ __('you') }})</span>@endif</span>
                                                <span class="block truncate text-xs text-brand-moss">{{ $user->email }}</span>
                                            </div>
                                            @foreach ($userTeams->take(2) as $ut)
                                                <button type="button" wire:click="select('', @js((string) $ut->id))" class="{{ $teamChip }} hidden hover:text-brand-ink sm:inline-flex">{{ $ut->name }}</button>
                                            @endforeach
                                            @if ($userTeams->count() > 2)
                                                <span class="hidden text-2xs text-brand-mist sm:inline" title="{{ $userTeams->pluck('name')->implode(', ') }}">+{{ $userTeams->count() - 2 }}</span>
                                            @endif
                                            @if ($canManageRow)
                                                {{-- Not wire:model: a downgrade asks first, so the select snaps back
                                                     to the saved role and re-keys once the change lands. --}}
                                                <select
                                                    wire:key="org-member-role-{{ $user->id }}-{{ $role }}"
                                                    x-data
                                                    x-on:change="$wire.promptChangeRole(@js((string) $user->id), $event.target.value); $event.target.value = @js($role)"
                                                    aria-label="{{ __('Role for :name', ['name' => $user->name]) }}"
                                                    class="h-7 shrink-0 rounded-lg border border-brand-ink/15 bg-white py-0 text-xs font-semibold text-brand-ink"
                                                >
                                                    @foreach ($this->assignableRoles($role === 'owner') as $value => $roleLabel)
                                                        <option value="{{ $value }}" @selected($role === $value)>{{ $roleLabel }}</option>
                                                    @endforeach
                                                </select>
                                                @if ($isOwner && $role !== 'owner')
                                                    <button type="button" wire:click="promptTransferOwnership(@js((string) $user->id))" class="{{ $btn }}">
                                                        <x-heroicon-o-key class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                                                        {{ __('Make owner') }}
                                                    </button>
                                                @endif
                                                <button type="button" wire:click="promptRemoveMember(@js((string) $user->id))" class="{{ $btnDanger }}">{{ __('Remove') }}</button>
                                            @else
                                                <span class="{{ $chip }} {{ $roleClasses($role) }}">{{ $role }}</span>
                                                @if ($isSelf)
                                                    <button type="button" wire:click="promptLeave" class="{{ $btnDanger }}">
                                                        <x-heroicon-o-arrow-left-start-on-rectangle class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                                                        {{ __('Leave') }}
                                                    </button>
                                                @endif
                                            @endif
                                        </li>
                                    @endforeach
                                </ul>
                            @endif
                        @endif
                    </section>
                </div>

                {{-- Who has the keys. Collapsible (remembered per org). --}}
                <section
                    class="dply-card overflow-hidden p-0"
                    x-data="{
                        _k: 'dply.members.howItWorksCollapsed:{{ $organization->id }}',
                        collapsed: false,
                        init() { try { this.collapsed = JSON.parse(localStorage.getItem(this._k)) || false; } catch (e) { this.collapsed = false; } },
                        toggle() { this.collapsed = ! this.collapsed; try { localStorage.setItem(this._k, JSON.stringify(this.collapsed)); } catch (e) {} },
                    }"
                >
                    <div class="flex items-center gap-2 px-5 py-3 sm:px-6">
                        <button type="button" x-on:click="toggle()" :aria-expanded="(! collapsed).toString()" class="flex flex-1 items-center gap-1.5 text-left">
                            <span x-bind:class="collapsed ? '' : 'rotate-90'" class="inline-flex text-brand-mist transition-transform">
                                <x-heroicon-o-chevron-right class="h-3.5 w-3.5" aria-hidden="true" />
                            </span>
                            <span class="{{ $label }}">{{ __('What the roles mean') }}</span>
                        </button>
                        <x-docs-link slug="roles-and-permissions" class="!h-6 !gap-1 !rounded-md !px-2 !py-0 !text-xs !font-semibold">
                            {{ __('Roles & limits') }}
                        </x-docs-link>
                    </div>
                    <div x-show="! collapsed" x-collapse>
                        <dl class="grid gap-px border-t border-brand-ink/10 bg-brand-ink/5 sm:grid-cols-2 lg:grid-cols-5">
                            @foreach ([
                                ['role' => 'owner', 'blurb' => __('Owns billing and the organization itself. Granted by an owner with Make owner, not by invite.')],
                                ['role' => 'admin', 'blurb' => __('Full control of apps, billing, and members — everything but ownership.')],
                                ['role' => 'member', 'blurb' => __('Creates, configures, and deploys apps.')],
                                ['role' => 'deployer', 'blurb' => __('Views apps and ships code: deploy, roll back, promote previews. Changes no settings.')],
                                ['role' => 'viewer', 'blurb' => __('Sees everything, changes nothing. Free: doesn\'t use a seat.')],
                            ] as $row)
                                <div class="bg-white px-5 py-3 sm:px-4">
                                    <dt class="flex items-center gap-1.5">
                                        <span class="{{ $chip }} {{ $roleClasses($row['role']) }}">{{ $row['role'] }}</span>
                                        <span class="font-mono text-2xs tabular-nums text-brand-mist">{{ $roleTally[$row['role']] ?? 0 }}</span>
                                    </dt>
                                    <dd class="mt-1 text-xs leading-relaxed text-brand-moss">{{ $row['blurb'] }}</dd>
                                </div>
                            @endforeach
                        </dl>
                    </div>
                </section>
            </div>
        </x-organization-shell>
    </div>

    @if ($isAdmin)
        <x-modal name="invite-member-modal" :show="false" maxWidth="md" overlayClass="bg-brand-ink/30" panelClass="dply-modal-panel overflow-hidden shadow-xl" focusable>
            <form wire:submit="inviteMember">
                <div class="flex items-start gap-3 border-b border-brand-ink/10 px-6 py-5">
                    <x-icon-badge>
                        <x-heroicon-o-user-plus class="h-5 w-5" aria-hidden="true" />
                    </x-icon-badge>
                    <div class="min-w-0">
                        <p class="text-xs font-semibold uppercase tracking-[0.18em] text-brand-sage">{{ __('Invite people') }}</p>
                        <h2 class="mt-1 text-lg font-semibold text-brand-ink">{{ __('Send an invitation') }}</h2>
                        <p class="mt-1 text-sm leading-6 text-brand-moss">
                            {{ __('We\'ll email them a link to join :org. Invitations expire after 7 days.', ['org' => $organization->name]) }}
                        </p>
                    </div>
                </div>

                <div class="space-y-5 px-6 py-6">
                    <div>
                        <x-input-label for="invite_email_modal" :value="__('Email address')" />
                        <x-text-input id="invite_email_modal" wire:model="invite_email" type="email" class="mt-2 block w-full" placeholder="{{ __('name@company.com') }}" required autocomplete="email" />
                        <x-input-error :messages="$errors->get('invite_email')" class="mt-2" />
                    </div>
                    <div>
                        <x-input-label for="invite_role_modal" :value="__('Role')" />
                        <x-select id="invite_role_modal" wire:model="invite_role" class="mt-2">
                            @foreach ($this->inviteableRoles() as $value => $roleLabel)
                                <option value="{{ $value }}">{{ $roleLabel }}</option>
                            @endforeach
                        </x-select>
                        <p class="mt-2 text-xs leading-relaxed text-brand-moss">{{ __('Owner can\'t be assigned here — only Admin, Member, Deployer, and Viewer.') }}</p>
                        <p class="mt-1 text-xs leading-relaxed text-brand-moss">{{ __('Each member and pending invitation takes a seat on your plan. View-only members are free and don\'t take a seat.') }}</p>
                    </div>
                </div>

                <div class="flex flex-wrap justify-end gap-3 border-t border-brand-ink/10 bg-brand-sand/25 px-6 py-4">
                    <x-secondary-button type="button" wire:click="closeInviteModal">{{ __('Cancel') }}</x-secondary-button>
                    <x-primary-button type="submit" wire:loading.attr="disabled" wire:target="inviteMember">
                        <span wire:loading.remove wire:target="inviteMember" class="inline-flex items-center gap-2">
                            <x-heroicon-o-paper-airplane class="h-4 w-4 shrink-0" aria-hidden="true" />
                            {{ __('Send invitation') }}
                        </span>
                        <span wire:loading wire:target="inviteMember" class="inline-flex items-center gap-2">
                            <x-spinner variant="cream" size="sm" />
                            {{ __('Sending…') }}
                        </span>
                    </x-primary-button>
                </div>
            </form>
        </x-modal>

        <x-modal name="create-team-modal" :show="false" maxWidth="md" overlayClass="bg-brand-ink/30" panelClass="dply-modal-panel overflow-hidden shadow-xl" focusable>
            <form wire:submit="createTeam">
                <div class="flex items-start gap-3 border-b border-brand-ink/10 px-6 py-5">
                    <x-icon-badge>
                        <x-heroicon-o-rectangle-group class="h-5 w-5" aria-hidden="true" />
                    </x-icon-badge>
                    <div class="min-w-0">
                        <p class="text-xs font-semibold uppercase tracking-[0.18em] text-brand-sage">{{ __('New team') }}</p>
                        <h2 class="mt-1 text-lg font-semibold text-brand-ink">{{ __('Name your team') }}</h2>
                        <p class="mt-1 text-sm leading-6 text-brand-moss">{{ __('A named group like “Platform” or “On-call”. Give it its own notification channels so the right people hear about the apps they own.') }}</p>
                    </div>
                </div>

                <div class="space-y-5 px-6 py-6">
                    <div>
                        <x-input-label for="team_name_modal" :value="__('Team name')" />
                        <x-text-input id="team_name_modal" wire:model="team_name" type="text" class="mt-2 block w-full" placeholder="{{ __('e.g. Platform, Customer success') }}" required maxlength="255" autocomplete="off" />
                        <x-input-error :messages="$errors->get('team_name')" class="mt-2" />
                    </div>
                </div>

                <div class="flex flex-wrap justify-end gap-3 border-t border-brand-ink/10 bg-brand-sand/25 px-6 py-4">
                    <x-secondary-button type="button" wire:click="closeCreateTeamModal">{{ __('Cancel') }}</x-secondary-button>
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
        @php $inviteTeam = $teams->firstWhere('id', $inviteTeamId); @endphp
        <x-modal name="invite-to-team-modal" :show="false" maxWidth="md" overlayClass="bg-brand-ink/30" panelClass="dply-modal-panel overflow-hidden shadow-xl" focusable>
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
                        <x-text-input id="team_invite_email_modal" wire:model="invite_email" type="email" class="mt-2 block w-full" placeholder="{{ __('name@company.com') }}" required autocomplete="email" />
                        <x-input-error :messages="$errors->get('invite_email')" class="mt-2" />
                    </div>
                    <div>
                        <x-input-label for="team_invite_role_modal" :value="__('Organization role')" />
                        <x-select id="team_invite_role_modal" wire:model="invite_role" class="mt-2">
                            @foreach ($this->inviteableRoles() as $value => $roleLabel)
                                <option value="{{ $value }}">{{ $roleLabel }}</option>
                            @endforeach
                        </x-select>
                        <p class="mt-2 text-xs leading-relaxed text-brand-moss">{{ __('The role applies to the organization. Team membership is separate.') }}</p>
                    </div>
                </div>

                <div class="flex flex-wrap justify-end gap-3 border-t border-brand-ink/10 bg-brand-sand/25 px-6 py-4">
                    <x-secondary-button type="button" wire:click="closeTeamInviteModal">{{ __('Cancel') }}</x-secondary-button>
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
