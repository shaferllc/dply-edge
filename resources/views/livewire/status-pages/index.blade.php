@php
    $pagesTotal = (int) ($pagesTotal ?? 0);
    $hasPagesInScope = $pagesTotal > 0;
    $canCreateStatusPage = (bool) ($canCreateStatusPage ?? false);
    $showShellActions = $hasOrganization && $hasPagesInScope && $canCreateStatusPage;
    $publicCount = $hasPagesInScope ? $pages->where('is_public', true)->count() : 0;
    $shellDescription = $hasOrganization
        ? __('Public status pages and incidents for your apps, driven by their uptime checks.')
        : __('Select an organization from the header to manage public status pages and incidents.');
@endphp

<div class="contents">
    <div class="mx-auto max-w-7xl px-4 py-8 sm:px-6 lg:px-8">
        <x-breadcrumb-trail
            doc-route="docs.index"
            :items="[
                ['label' => __('Dashboard'), 'href' => route('dashboard'), 'icon' => 'home'],
                ['label' => __('Status pages'), 'icon' => 'check-circle'],
            ]"
        />

        <x-profile-shell
            :title="__('Status pages')"
            :description="$shellDescription"
            icon="heroicon-o-check-circle"
        >
            @if ($showShellActions)
                <x-slot:actions>
                    <button
                        type="button"
                        wire:click="openCreateStatusPageModal"
                        class="inline-flex items-center justify-center gap-2 rounded-xl bg-brand-ink px-4 py-2 text-sm font-semibold text-brand-cream shadow-md transition-colors hover:bg-brand-forest"
                    >
                        <x-heroicon-o-plus class="h-4 w-4 shrink-0" aria-hidden="true" />
                        {{ __('New status page') }}
                    </button>
                </x-slot:actions>
            @endif

            @if ($hasPagesInScope)
                <x-slot:stats>
                    <dl class="grid grid-cols-1 gap-2 sm:grid-cols-2">
                        <div class="rounded-xl border border-brand-ink/10 bg-white/80 px-3 py-2">
                            <dt class="flex items-center gap-1.5 text-2xs font-semibold uppercase tracking-wide text-brand-mist">
                                <x-heroicon-o-check-circle class="h-3.5 w-3.5 shrink-0 text-brand-sage" aria-hidden="true" />
                                <span class="truncate">{{ __('Pages') }}</span>
                            </dt>
                            <dd class="mt-0.5 font-mono text-lg font-semibold tabular-nums leading-none text-brand-ink">{{ $pagesTotal }}</dd>
                        </div>
                        <div class="rounded-xl border border-brand-ink/10 bg-white/80 px-3 py-2">
                            <dt class="flex items-center gap-1.5 text-2xs font-semibold uppercase tracking-wide text-brand-mist">
                                <x-heroicon-o-globe-alt class="h-3.5 w-3.5 shrink-0 text-brand-sage" aria-hidden="true" />
                                <span class="truncate">{{ __('Public') }}</span>
                            </dt>
                            <dd class="mt-0.5 font-mono text-lg font-semibold tabular-nums leading-none text-brand-ink">{{ $publicCount }}</dd>
                        </div>
                    </dl>
                </x-slot:stats>
            @endif

            @if (! $hasOrganization)
                <div class="border-b border-brand-ink/10 bg-amber-50/60 px-5 py-4 sm:px-6">
                    <div class="flex items-start gap-3">
                        <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-xl bg-amber-100 text-amber-800 ring-1 ring-amber-200/80">
                            <x-heroicon-o-shield-exclamation class="h-5 w-5" aria-hidden="true" />
                        </span>
                        <div class="min-w-0">
                            <p class="text-xs font-semibold uppercase tracking-[0.16em] text-amber-800">{{ __('Setup') }}</p>
                            <h2 class="mt-0.5 text-sm font-semibold text-brand-ink">{{ __('Organization required') }}</h2>
                            <p class="mt-1 max-w-2xl text-sm leading-relaxed text-brand-moss">
                                {{ __('Select an organization from the header to manage status pages.') }}
                            </p>
                        </div>
                    </div>
                </div>
            @elseif (! $hasPagesInScope)
                <div class="flex flex-col items-center justify-center px-5 py-16 text-center sm:px-6" aria-labelledby="status-pages-empty-heading">
                    <span class="flex h-12 w-12 items-center justify-center rounded-2xl bg-brand-sand/45 text-brand-mist ring-1 ring-brand-ink/10">
                        <x-heroicon-o-check-circle class="h-6 w-6" aria-hidden="true" />
                    </span>
                    <h2 id="status-pages-empty-heading" class="mt-4 text-sm font-semibold text-brand-ink">{{ __('No status pages yet') }}</h2>
                    <p class="mt-1 max-w-md text-sm leading-relaxed text-brand-moss">
                        {{ __('Create one, then add apps to monitor and publish incidents.') }}
                    </p>
                    @if ($canCreateStatusPage)
                        <div class="mt-5">
                            <button
                                type="button"
                                wire:click="openCreateStatusPageModal"
                                class="inline-flex items-center justify-center gap-2 rounded-xl bg-brand-ink px-4 py-2 text-sm font-semibold text-brand-cream shadow-md transition-colors hover:bg-brand-forest"
                            >
                                <x-heroicon-o-plus class="h-4 w-4 shrink-0" aria-hidden="true" />
                                {{ __('New status page') }}
                            </button>
                        </div>
                    @endif
                </div>
            @else
                <ul>
                    @foreach ($pages as $page)
                        <li
                            wire:key="status-page-{{ $page->id }}"
                            class="flex flex-wrap items-center justify-between gap-4 border-b border-brand-ink/10 px-5 py-4 transition-colors last:border-b-0 hover:bg-brand-sand/15 sm:px-6"
                        >
                            <div class="min-w-0 flex-1">
                                <a
                                    href="{{ route('status-pages.manage', $page) }}"
                                    wire:navigate
                                    class="text-sm font-semibold text-brand-ink hover:text-brand-sage"
                                >
                                    {{ $page->name }}
                                </a>
                                @if ($page->description)
                                    <p class="mt-0.5 text-sm text-brand-moss">{{ $page->description }}</p>
                                @endif
                                <p class="mt-1 text-xs text-brand-mist">
                                    {{ __('Public URL:') }}
                                    <a
                                        href="{{ route('status.public', $page) }}"
                                        target="_blank"
                                        rel="noopener noreferrer"
                                        class="text-brand-moss hover:text-brand-ink hover:underline"
                                    >
                                        {{ url('/status/'.$page->slug) }}
                                    </a>
                                    @if (! $page->is_public)
                                        <span class="text-amber-700">{{ __('(hidden)') }}</span>
                                    @endif
                                </p>
                            </div>
                            <a
                                href="{{ route('status-pages.manage', $page) }}"
                                wire:navigate
                                class="inline-flex shrink-0 items-center rounded-lg border border-brand-ink/15 bg-white px-3 py-1.5 text-xs font-semibold text-brand-ink shadow-sm transition hover:bg-brand-sand/40"
                            >
                                {{ __('Manage') }}
                            </a>
                        </li>
                    @endforeach
                </ul>
            @endif
        </x-profile-shell>

        @if ($hasOrganization && $canCreateStatusPage)
            <x-modal
                name="create-status-page-modal"
                :show="false"
                maxWidth="md"
                overlayClass="bg-brand-ink/30"
                panelClass="dply-modal-panel overflow-hidden shadow-xl"
                focusable
            >
                <form wire:submit="createPage">
                    <div class="border-b border-brand-ink/10 px-6 py-5">
                        <p class="text-xs font-semibold uppercase tracking-[0.18em] text-brand-sage">{{ __('New status page') }}</p>
                        <h2 class="mt-2 text-xl font-semibold text-brand-ink">{{ __('Create a status page') }}</h2>
                        <p class="mt-2 text-sm leading-6 text-brand-moss">
                            {{ __('Give it a name, then add monitors and publish incidents from the manage page.') }}
                        </p>
                    </div>

                    <div class="space-y-5 px-6 py-6">
                        <div>
                            <x-input-label for="sp-name-modal" :value="__('Name')" />
                            <x-text-input
                                id="sp-name-modal"
                                wire:model="name"
                                type="text"
                                class="mt-2 block w-full"
                                required
                                maxlength="120"
                                autocomplete="off"
                                placeholder="{{ __('e.g. Acme Production') }}"
                            />
                            <x-input-error :messages="$errors->get('name')" class="mt-2" />
                        </div>
                        <div>
                            <x-input-label for="sp-desc-modal" :value="__('Description (optional)')" />
                            <x-textarea id="sp-desc-modal" wire:model="description" rows="3" class="mt-2 block w-full" />
                            <x-input-error :messages="$errors->get('description')" class="mt-2" />
                        </div>
                    </div>

                    <div class="flex flex-wrap justify-end gap-3 border-t border-brand-ink/10 px-6 py-4">
                        <x-secondary-button type="button" wire:click="closeCreateStatusPageModal">
                            {{ __('Cancel') }}
                        </x-secondary-button>
                        <x-primary-button type="submit" wire:loading.attr="disabled" wire:target="createPage">
                            <span wire:loading.remove wire:target="createPage">{{ __('Create status page') }}</span>
                            <span wire:loading wire:target="createPage" class="inline-flex items-center gap-2">
                                <x-spinner variant="cream" size="sm" />
                                {{ __('Creating…') }}
                            </span>
                        </x-primary-button>
                    </div>
                </form>
            </x-modal>
        @endif
    </div>
</div>
