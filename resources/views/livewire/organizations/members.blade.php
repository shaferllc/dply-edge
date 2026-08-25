@php
    $isAdmin = $organization->hasAdminAccess(auth()->user());
    $memberCount = $organization->users->count();
    $invitationCount = $organization->invitations->count();
    $teamCount = $organization->teams->count();

    // Role tone tokens for the trailing chip. Owner / admin pop; member /
    // deployer stay neutral so the list reads as a calm directory.
    $roleClasses = function (string $role): string {
        return match (strtolower($role)) {
            'owner' => 'border-brand-sage/35 bg-brand-sage/15 text-brand-forest',
            'admin' => 'border-amber-200 bg-amber-50 text-amber-900',
            'deployer' => 'border-sky-200 bg-sky-50 text-sky-700',
            default => 'border-brand-ink/10 bg-brand-sand/50 text-brand-moss',
        };
    };

    // Avatar ring picks up the same tone, so seniority reads at a glance down
    // the left edge without adding another chip.
    $avatarClasses = fn (string $role): string => match (strtolower($role)) {
        'owner' => 'bg-brand-sage/20 text-brand-forest ring-brand-sage/35',
        'admin' => 'bg-amber-50 text-amber-900 ring-amber-200',
        'deployer' => 'bg-sky-50 text-sky-700 ring-sky-200',
        default => 'bg-brand-sand/55 text-brand-forest ring-brand-ink/10',
    };

    $initialsOf = fn ($user): string => strtoupper(
        collect(preg_split('/\s+/', trim((string) $user->name)))->filter()->map(fn ($w) => mb_substr($w, 0, 1))->take(2)->implode('')
            ?: mb_substr((string) ($user->email ?? '?'), 0, 1)
    );

    // Role headcount for the explainer strip — "who has the keys" is the
    // question this page exists to answer.
    $roleTally = $organization->users->groupBy(fn ($u) => strtolower($u->pivot->role))->map->count();
@endphp

<div>
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
        <x-organization-shell
            :organization="$organization"
            section="members"
            :title="__('Members')"
            :description="__('Invite people by email, track pending invitations, and see everyone with access to this organization.')"
            icon="heroicon-o-user-group"
            :breadcrumb="[
                ['label' => __('Dashboard'), 'href' => route('dashboard'), 'icon' => 'home'],
                ['label' => $organization->name, 'href' => route('organizations.show', $organization), 'icon' => 'building-office-2'],
                ['label' => __('Members'), 'icon' => 'user-group'],
            ]"
        >
            <x-slot:actions>
                <x-docs-link slug="org-roles-and-limits" class="!h-6 !gap-1 !rounded-md !px-2 !py-0 !text-xs !font-semibold">
                    <x-heroicon-o-queue-list class="h-3.5 w-3.5 shrink-0 opacity-90" aria-hidden="true" />
                    {{ __('Roles & limits') }}
                </x-docs-link>
                <x-outline-link href="{{ route('organizations.teams', $organization) }}" wire:navigate size="xxs">
                    <x-heroicon-o-rectangle-group class="h-3.5 w-3.5 shrink-0 opacity-90" aria-hidden="true" />
                    {{ __('Teams') }}
                </x-outline-link>
                @if ($isAdmin)
                    <button
                        type="button"
                        wire:click="openInviteModal"
                        class="inline-flex h-6 items-center gap-1 rounded-lg bg-brand-ink px-2.5 text-xs font-semibold text-brand-cream shadow-sm transition-colors hover:bg-brand-forest"
                    >
                        <x-heroicon-o-user-plus class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                        {{ __('Invite member') }}
                    </button>
                @endif
            </x-slot:actions>

            <x-slot:stats>
                <dl class="grid grid-cols-3 gap-px bg-brand-ink/5" aria-label="{{ __('Members at a glance') }}">
                    <div class="bg-white px-3 py-2">
                        <dt class="text-2xs font-semibold uppercase tracking-wide text-brand-mist">{{ __('Members') }}</dt>
                        <dd class="mt-0.5 font-mono text-base font-semibold tabular-nums text-brand-ink">{{ $memberCount }}</dd>
                    </div>
                    <div @class([
                        'px-3 py-2',
                        'bg-amber-50/70' => $invitationCount > 0,
                        'bg-white' => $invitationCount === 0,
                    ])>
                        <dt class="text-2xs font-semibold uppercase tracking-wide text-brand-mist">{{ __('Invites') }}</dt>
                        <dd class="mt-0.5 flex items-baseline gap-1.5">
                            <span class="font-mono text-base font-semibold tabular-nums text-brand-ink">{{ $invitationCount }}</span>
                            @if ($invitationCount > 0)
                                <span class="text-2xs font-semibold uppercase tracking-wide text-amber-800">{{ __('pending') }}</span>
                            @endif
                        </dd>
                    </div>
                    <div class="bg-white px-3 py-2">
                        <dt class="text-2xs font-semibold uppercase tracking-wide text-brand-mist">{{ __('Teams') }}</dt>
                        <dd class="mt-0.5 flex items-baseline gap-1.5">
                            <span class="font-mono text-base font-semibold tabular-nums text-brand-ink">{{ $teamCount }}</span>
                            <a href="{{ route('organizations.teams', $organization) }}" wire:navigate class="text-2xs font-semibold text-brand-sage hover:text-brand-ink">{{ __('Manage') }} →</a>
                        </dd>
                    </div>
                </dl>
            </x-slot:stats>

            {{-- Who has the keys. Collapsible (remembered per org) so the role
                 vocabulary is one click away without living on the page. --}}
            <div
                class="border-b border-brand-ink/10 bg-brand-cream/40"
                x-data="{
                    _k: 'dply.members.howItWorksCollapsed:{{ $organization->id }}',
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
                    <span class="text-xs font-semibold uppercase tracking-[0.14em] text-brand-sage">{{ __('What the roles mean') }}</span>
                    <span class="ms-auto text-2xs text-brand-mist" x-show="collapsed">{{ __('Show') }}</span>
                </button>

                <div x-show="! collapsed" x-collapse>
                    <dl class="grid gap-px bg-brand-ink/5 sm:grid-cols-4">
                        @foreach ([
                            ['role' => 'owner', 'blurb' => __('Owns billing and the organization itself. Can\'t be assigned by invite.')],
                            ['role' => 'admin', 'blurb' => __('Full control of servers, sites, and members — everything but ownership.')],
                            ['role' => 'member', 'blurb' => __('Day-to-day access to the infrastructure they\'re given.')],
                            ['role' => 'deployer', 'blurb' => __('Reduced scope: deploy and read, no destructive changes.')],
                        ] as $row)
                            <div class="bg-brand-cream/40 px-5 py-3 sm:px-4">
                                <dt class="flex items-center gap-1.5">
                                    <span class="inline-flex items-center rounded-md border px-1.5 py-0.5 text-2xs font-semibold uppercase tracking-wide {{ $roleClasses($row['role']) }}">{{ $row['role'] }}</span>
                                    <span class="font-mono text-2xs tabular-nums text-brand-mist">{{ $roleTally[$row['role']] ?? 0 }}</span>
                                </dt>
                                <dd class="mt-1 text-xs leading-relaxed text-brand-moss">{{ $row['blurb'] }}</dd>
                            </div>
                        @endforeach
                    </dl>
                </div>
            </div>

            @if ($errors->isNotEmpty())
                <div class="border-b border-brand-ink/10 px-5 py-4 sm:px-6">
                    <x-livewire-validation-errors />
                </div>
            @endif

            {{-- Pending invitations. Surface only when there's something
                 pending — collapsing the section when empty keeps the
                 page focused on the actual member directory. --}}
            @if ($invitationCount > 0)
                <section class="border-b border-brand-ink/10">
                    <div class="flex items-center gap-2 border-b border-brand-ink/10 bg-amber-50/50 px-5 py-2 sm:px-6">
                        <x-heroicon-o-envelope class="h-4 w-4 shrink-0 text-amber-700" aria-hidden="true" />
                        <h3 class="text-sm font-semibold text-brand-ink">{{ __('Pending invitations') }}</h3>
                        <span class="text-xs text-brand-moss">{{ __('sent, not yet accepted') }}</span>
                        <span class="ms-auto shrink-0 rounded-md border border-amber-200 bg-white px-1.5 py-0.5 font-mono text-2xs font-semibold tabular-nums text-amber-900">{{ $invitationCount }}</span>
                    </div>
                    <ul class="divide-y divide-brand-ink/10">
                        @foreach ($organization->invitations as $inv)
                            <li class="group flex items-center gap-3 bg-amber-50/20 px-5 py-2 transition-colors hover:bg-amber-50/50 sm:px-6">
                                <span class="flex h-7 w-7 shrink-0 items-center justify-center rounded-full bg-amber-50 text-amber-700 ring-1 ring-amber-200">
                                    <x-heroicon-o-envelope class="h-3.5 w-3.5" aria-hidden="true" />
                                </span>
                                <div class="min-w-0 flex-1">
                                    <div class="flex flex-wrap items-baseline gap-x-2 gap-y-0.5">
                                        <span class="truncate text-sm font-semibold text-brand-ink">{{ $inv->email }}</span>
                                        <span class="inline-flex items-center rounded-md border px-1.5 py-0.5 text-2xs font-semibold uppercase tracking-wide {{ $roleClasses($inv->role) }}">{{ $inv->role }}</span>
                                        {{-- Invites sent from the Teams page also join a team on accept. --}}
                                        @if ($inv->team)
                                            <span class="inline-flex items-center gap-1 rounded-md border border-brand-ink/10 bg-brand-sand/50 px-1.5 py-0.5 text-2xs font-semibold text-brand-moss">
                                                <x-heroicon-o-rectangle-group class="h-3 w-3 shrink-0" aria-hidden="true" />
                                                {{ $inv->team->name }}
                                            </span>
                                        @endif
                                    </div>
                                    <p class="mt-0.5 text-xs text-brand-mist">
                                        @if ($inv->expires_at)
                                            {{ __('Expires :time', ['time' => $inv->expires_at->diffForHumans()]) }}
                                        @else
                                            {{ __('Awaiting acceptance') }}
                                        @endif
                                    </p>
                                </div>
                                @if ($isAdmin)
                                    <button
                                        type="button"
                                        wire:click="promptCancelInvitation('{{ $inv->id }}')"
                                        class="inline-flex h-6 shrink-0 items-center gap-1 rounded-md border border-brand-ink/15 bg-white px-2.5 text-xs font-semibold text-brand-moss shadow-sm transition hover:border-rose-200 hover:bg-rose-50 hover:text-rose-700"
                                    >
                                        <x-heroicon-o-x-mark class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                                        {{ __('Cancel') }}
                                    </button>
                                @endif
                            </li>
                        @endforeach
                    </ul>
                </section>
            @endif

            {{-- Member directory. No section header: the dense panel head above
                 already says Members and carries the primary Invite action. --}}
            <section class="border-b border-brand-ink/10 last:border-b-0">

                @if ($organization->users->isEmpty())
                    <div class="px-5 py-10 text-center sm:px-6">
                        <span class="mx-auto inline-flex h-10 w-10 items-center justify-center rounded-xl bg-brand-sand/45 text-brand-mist ring-1 ring-brand-ink/10">
                            <x-heroicon-o-user-group class="h-5 w-5" aria-hidden="true" />
                        </span>
                        <p class="mt-3 text-sm font-medium text-brand-ink">{{ __('No members yet.') }}</p>
                        @if ($isAdmin)
                            <button type="button" wire:click="openInviteModal" class="mt-2 text-xs font-semibold text-brand-sage hover:text-brand-ink">{{ __('Invite the first one') }} →</button>
                        @endif
                    </div>
                @else
                    <ul class="divide-y divide-brand-ink/10">
                        @foreach ($organization->users as $user)
                            @php
                                $role = strtolower((string) $user->pivot->role);
                                $teamNames = $organization->teams->filter(fn ($t) => $t->users->contains('id', $user->id))->pluck('name');
                            @endphp
                            <li class="group flex items-center gap-3 px-5 py-2 transition-colors hover:bg-brand-sand/15 sm:px-6">
                                <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full text-2xs font-semibold ring-1 {{ $avatarClasses($role) }}">
                                    {{ $initialsOf($user) }}
                                </span>
                                <div class="min-w-0 flex-1">
                                    <span class="text-sm font-semibold text-brand-ink">{{ $user->name }}</span>
                                    <span class="mt-0.5 block truncate text-xs text-brand-moss sm:mt-0 sm:ml-2 sm:inline">{{ $user->email }}</span>
                                </div>
                                {{-- Team membership, so the directory answers "who's on
                                     what" without a trip to the Teams page. --}}
                                @if ($teamNames->isNotEmpty())
                                    <span class="hidden shrink-0 items-center gap-1 text-2xs text-brand-mist sm:inline-flex" title="{{ $teamNames->implode(', ') }}">
                                        <x-heroicon-o-rectangle-group class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                                        {{ $teamNames->take(2)->implode(', ') }}{{ $teamNames->count() > 2 ? ' +'.($teamNames->count() - 2) : '' }}
                                    </span>
                                @endif
                                <span class="shrink-0 rounded-md border px-1.5 py-0.5 text-2xs font-semibold uppercase tracking-wide {{ $roleClasses($user->pivot->role) }}">{{ $user->pivot->role }}</span>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </section>

            {{-- Footer: the questions this page raises next. --}}
            <x-slot:footer>
                <div class="flex flex-wrap items-center gap-x-4 gap-y-2 text-xs text-brand-moss">
                    <span class="inline-flex items-center gap-1.5">
                        <x-heroicon-o-information-circle class="h-4 w-4 shrink-0 text-brand-sage" aria-hidden="true" />
                        {{ __('Invitations expire after 7 days. Owner can\'t be granted by invite.') }}
                    </span>
                    <a href="{{ route('organizations.teams', $organization) }}" wire:navigate class="ms-auto font-semibold text-brand-sage hover:text-brand-ink">
                        {{ __('Group people into teams') }} →
                    </a>
                </div>
            </x-slot:footer>
        </x-organization-shell>
    </div>

    @if ($isAdmin)
        <x-modal
            name="invite-member-modal"
            :show="false"
            maxWidth="md"
            overlayClass="bg-brand-ink/30"
            panelClass="dply-modal-panel overflow-hidden shadow-xl"
            focusable
        >
            <form wire:submit="inviteMember">
                <div class="flex items-start gap-3 border-b border-brand-ink/10 px-6 py-5">
                    <x-icon-badge>
                        <x-heroicon-o-user-plus class="h-5 w-5" aria-hidden="true" />
                    </x-icon-badge>
                    <div class="min-w-0">
                        <p class="text-xs font-semibold uppercase tracking-[0.18em] text-brand-sage">{{ __('Invite member') }}</p>
                        <h2 class="mt-1 text-lg font-semibold text-brand-ink">{{ __('Send an invitation') }}</h2>
                        <p class="mt-1 text-sm leading-6 text-brand-moss">
                            {{ __('We\'ll email them a link to join :org.', ['org' => $organization->name]) }}
                        </p>
                    </div>
                </div>

                <div class="space-y-5 px-6 py-6">
                    <div>
                        <x-input-label for="invite_email_modal" :value="__('Email address')" />
                        <x-text-input
                            id="invite_email_modal"
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
                        <x-input-label for="invite_role_modal" :value="__('Role')" />
                        <x-select id="invite_role_modal" wire:model="invite_role" class="mt-2">
                            @foreach ($this->inviteableRoles() as $value => $label)
                                <option value="{{ $value }}">{{ $label }}</option>
                            @endforeach
                        </x-select>
                        <p class="mt-2 text-xs leading-relaxed text-brand-moss">{{ __('Owner can\'t be assigned here — only Admin, Member, and Deployer.') }}</p>
                    </div>
                </div>

                <div class="flex flex-wrap justify-end gap-3 border-t border-brand-ink/10 bg-brand-sand/25 px-6 py-4">
                    <x-secondary-button type="button" wire:click="closeInviteModal">
                        {{ __('Cancel') }}
                    </x-secondary-button>
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
    @endif

    {{-- Confirm modal must live in the Livewire view tree (not only a layout slot) so state updates and wire: targets bind reliably. --}}
    @include('livewire.partials.confirm-action-modal')
</div>
