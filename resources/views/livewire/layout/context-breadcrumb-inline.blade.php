@php
    $canManageTeams = $currentOrg && $currentOrg->hasAdminAccess(auth()->user());
@endphp

<div
    class="min-w-0 max-w-full"
    x-data="{
        orgOpen: false,
        teamOpen: false,
        closeAll() { this.orgOpen = false; this.teamOpen = false; },
        toggleOrg() { this.teamOpen = false; this.orgOpen = !this.orgOpen; },
        toggleTeam() { this.orgOpen = false; this.teamOpen = !this.teamOpen; },
    }"
    @keydown.escape.window="closeAll()"
>
    <div class="min-w-0">
        {{-- overflow visible so org/team menus are not clipped below the header row --}}
        <nav class="flex flex-nowrap items-center gap-x-1 gap-y-1 overflow-visible" aria-label="{{ __('Workspace context') }}">
            <div class="relative flex flex-nowrap items-center gap-x-1.5 min-w-0">
                {{-- Organization switcher --}}
                <div class="relative min-w-0 shrink-0" @click.outside="orgOpen = false">
                    <button
                        type="button"
                        class="group flex max-w-[min(42vw,11rem)] sm:max-w-[13rem] lg:max-w-[15rem] items-center gap-2 rounded-md border border-transparent px-2 py-1.5 text-left transition-colors hover:border-brand-ink/15 hover:bg-brand-ink/5"
                        @click="toggleOrg()"
                        :aria-expanded="orgOpen.toString()"
                        aria-haspopup="listbox"
                    >
                        <span class="flex h-5 w-5 shrink-0 items-center justify-center rounded bg-edge-lime text-[9px] font-bold text-edge-void" aria-hidden="true">
                            {{ \App\Livewire\Layout\ContextBreadcrumb::initials($currentOrg->name ?? __('Org')) }}
                        </span>
                        <span class="min-w-0 flex-1">
                            <span class="block truncate text-[13px] font-semibold text-brand-ink">{{ $currentOrg->name ?? __('None') }}</span>
                        </span>
                        <x-heroicon-m-chevron-down class="h-3.5 w-3.5 shrink-0 text-brand-moss group-hover:text-brand-ink" aria-hidden="true" />
                    </button>

                    <div
                        x-cloak
                        x-show="orgOpen"
                        x-transition:enter="transition ease-out duration-150"
                        x-transition:enter-start="opacity-0 translate-y-1"
                        x-transition:enter-end="opacity-100 translate-y-0"
                        x-transition:leave="transition ease-in duration-100"
                        x-transition:leave-start="opacity-100"
                        x-transition:leave-end="opacity-0"
                        class="absolute left-0 z-50 mt-2 w-[min(100vw-2rem,20rem)] dply-dropdown-panel py-2"
                        role="listbox"
                    >
                        <p class="px-4 pb-2 text-xs font-semibold uppercase tracking-wider text-brand-moss">{{ __('Switch organization') }}</p>
                        <ul class="max-h-64 overflow-y-auto px-2">
                            @foreach ($organizations as $org)
                                <li>
                                    <button
                                        type="button"
                                        wire:click="switchOrganization('{{ $org->id }}')"
                                        wire:loading.attr="disabled"
                                        class="flex w-full items-center gap-3 rounded-xl px-2 py-2.5 text-left text-sm transition hover:bg-brand-sand/50"
                                        role="option"
                                        @click="orgOpen = false"
                                    >
                                        <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-brand-sage text-xs font-bold text-white">
                                            {{ \App\Livewire\Layout\ContextBreadcrumb::initials($org->name) }}
                                        </span>
                                        <span class="min-w-0 flex-1">
                                            <span class="block truncate font-medium text-brand-ink">{{ $org->name }}</span>
                                            <span class="block text-xs text-brand-moss">{{ __('Organization') }}</span>
                                        </span>
                                        @if ($currentOrg && $currentOrg->is($org))
                                            <x-heroicon-o-check class="h-5 w-5 shrink-0 text-brand-sage" aria-hidden="true" />
                                        @endif
                                    </button>
                                </li>
                            @endforeach
                        </ul>
                        <div class="my-2 border-t border-brand-ink/10"></div>
                        <div class="space-y-0.5 px-2">
                            @if ($currentOrg)
                                <a
                                    href="{{ route('organizations.show', $currentOrg) }}"
                                    wire:navigate
                                    class="flex items-center gap-2.5 rounded-xl px-2 py-2 text-sm text-brand-ink hover:bg-brand-sand/50"
                                    @click="orgOpen = false"
                                >
                                    <x-heroicon-o-cog-6-tooth class="h-4 w-4 shrink-0 text-brand-moss" aria-hidden="true" />
                                    {{ __('Organization settings') }}
                                </a>
                            @endif
                            <a
                                href="{{ route('organizations.index') }}"
                                wire:navigate
                                class="flex items-center gap-2.5 rounded-xl px-2 py-2 text-sm text-brand-ink hover:bg-brand-sand/50"
                                @click="orgOpen = false"
                            >
                                <x-heroicon-o-globe-alt class="h-4 w-4 shrink-0 text-brand-moss" aria-hidden="true" />
                                {{ __('All organizations') }}
                            </a>
                            <a
                                href="{{ route('organizations.create') }}"
                                wire:navigate
                                class="flex items-center gap-2.5 rounded-xl px-2 py-2 text-sm text-brand-ink hover:bg-brand-sand/50"
                                @click="orgOpen = false"
                            >
                                <x-heroicon-o-plus class="h-4 w-4 shrink-0 text-brand-moss" aria-hidden="true" />
                                {{ __('Create organization') }}
                            </a>
                        </div>
                    </div>
                </div>

                @if ($currentOrg)
                    <span class="text-brand-mist/80 select-none text-xs" aria-hidden="true">/</span>

                    {{-- Team switcher --}}
                    <div class="relative min-w-0 shrink-0" @click.outside="teamOpen = false">
                        @if ($teams->isEmpty())
                            <div class="flex max-w-[min(42vw,11rem)] items-center gap-2 rounded-lg border border-dashed border-brand-ink/15 bg-white/50 px-2 py-1.5 text-xs text-brand-moss">
                                <span class="flex h-5 w-5 shrink-0 items-center justify-center rounded bg-brand-ink/10 text-[9px] font-bold text-brand-mist">—</span>
                                <span class="min-w-0">
                                    <a href="{{ route('organizations.show', $currentOrg) }}" wire:navigate class="block truncate font-medium text-brand-sage hover:text-brand-ink">{{ __('No teams yet — set up on the org page') }}</a>
                                </span>
                            </div>
                        @else
                            <button
                                type="button"
                                class="group flex max-w-[min(42vw,11rem)] sm:max-w-[13rem] lg:max-w-[15rem] items-center gap-2 rounded-md border border-transparent px-2 py-1.5 text-left transition-colors hover:border-brand-ink/15 hover:bg-brand-ink/5"
                                @click="toggleTeam()"
                                :aria-expanded="teamOpen.toString()"
                                aria-haspopup="listbox"
                            >
                                <span class="flex h-5 w-5 shrink-0 items-center justify-center rounded bg-brand-sage/30 text-[9px] font-bold text-brand-ink" aria-hidden="true">
                                    @if ($currentTeam)
                                        {{ \App\Livewire\Layout\ContextBreadcrumb::initials($currentTeam->name) }}
                                    @else
                                        {{ \App\Livewire\Layout\ContextBreadcrumb::initials($currentOrg->name) }}
                                    @endif
                                </span>
                                <span class="min-w-0 flex-1">
                                    <span class="block truncate text-[13px] font-semibold text-brand-ink">
                                        {{ $currentTeam?->name ?? __('All teams') }}
                                    </span>
                                </span>
                                <x-heroicon-m-chevron-down class="h-3.5 w-3.5 shrink-0 text-brand-moss group-hover:text-brand-ink" aria-hidden="true" />
                            </button>

                            <div
                                x-cloak
                                x-show="teamOpen"
                                x-transition:enter="transition ease-out duration-150"
                                x-transition:enter-start="opacity-0 translate-y-1"
                                x-transition:enter-end="opacity-100 translate-y-0"
                                x-transition:leave="transition ease-in duration-100"
                                x-transition:leave-start="opacity-100"
                                x-transition:leave-end="opacity-0"
                                class="absolute left-0 z-50 mt-2 w-[min(100vw-2rem,20rem)] dply-dropdown-panel py-2 sm:left-auto sm:right-0"
                                role="listbox"
                            >
                                <p class="px-4 pb-2 text-xs font-semibold uppercase tracking-wider text-brand-moss">{{ __('Switch team') }}</p>
                                <ul class="max-h-64 overflow-y-auto px-2">
                                    <li>
                                        <button
                                            type="button"
                                            wire:click="switchTeam"
                                            wire:loading.attr="disabled"
                                            class="flex w-full items-center gap-3 rounded-xl px-2 py-2.5 text-left text-sm transition hover:bg-brand-sand/50"
                                            role="option"
                                            @click="teamOpen = false"
                                        >
                                            <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-brand-sand/80 text-xs font-bold text-brand-moss">
                                                {{ \App\Livewire\Layout\ContextBreadcrumb::initials($currentOrg->name) }}
                                            </span>
                                            <span class="min-w-0 flex-1">
                                                <span class="block truncate font-medium text-brand-ink">{{ __('All teams') }}</span>
                                                <span class="block text-xs text-brand-moss">{{ __('Whole organization') }}</span>
                                            </span>
                                            @if (! $currentTeam)
                                                <x-heroicon-o-check class="h-5 w-5 shrink-0 text-brand-sage" aria-hidden="true" />
                                            @endif
                                        </button>
                                    </li>
                                    @foreach ($teams as $team)
                                        <li>
                                            <button
                                                type="button"
                                                wire:click="switchTeam('{{ $team->id }}')"
                                                wire:loading.attr="disabled"
                                                class="flex w-full items-center gap-3 rounded-xl px-2 py-2.5 text-left text-sm transition hover:bg-brand-sand/50"
                                                role="option"
                                                @click="teamOpen = false"
                                            >
                                                <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-[#3b6fb6] text-xs font-bold text-white">
                                                    {{ \App\Livewire\Layout\ContextBreadcrumb::initials($team->name) }}
                                                </span>
                                                <span class="min-w-0 flex-1">
                                                    <span class="block truncate font-medium text-brand-ink">{{ $team->name }}</span>
                                                    <span class="block text-xs text-brand-moss">{{ __('Team') }}</span>
                                                </span>
                                                @if ($currentTeam && $currentTeam->is($team))
                                                    <x-heroicon-o-check class="h-5 w-5 shrink-0 text-brand-sage" aria-hidden="true" />
                                                @endif
                                            </button>
                                        </li>
                                    @endforeach
                                </ul>
                                <div class="my-2 border-t border-brand-ink/10"></div>
                                <div class="space-y-0.5 px-2">
                                    <a
                                        href="{{ route('organizations.show', $currentOrg) }}"
                                        wire:navigate
                                        class="flex items-center gap-2.5 rounded-xl px-2 py-2 text-sm text-brand-ink hover:bg-brand-sand/50"
                                        @click="teamOpen = false"
                                    >
                                        <x-heroicon-o-globe-alt class="h-4 w-4 shrink-0 text-brand-moss" aria-hidden="true" />
                                        {{ __('Teams on org page') }}
                                    </a>
                                    @if ($canManageTeams)
                                        <a
                                            href="{{ route('organizations.show', $currentOrg) }}"
                                            wire:navigate
                                            class="flex items-center gap-2.5 rounded-xl px-2 py-2 text-sm text-brand-ink hover:bg-brand-sand/50"
                                            @click="teamOpen = false"
                                        >
                                            <x-heroicon-o-plus class="h-4 w-4 shrink-0 text-brand-moss" aria-hidden="true" />
                                            {{ __('Create team') }}
                                        </a>
                                    @endif
                                </div>
                            </div>
                        @endif
                    </div>
                @endif
            </div>
        </nav>
    </div>
</div>
