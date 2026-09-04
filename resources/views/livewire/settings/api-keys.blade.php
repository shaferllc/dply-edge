@php
    $totalTokens = $tokens->count();
    $activeTokens = $tokens->filter(fn ($t) => $t->expires_at === null || ! $t->expires_at->isPast())->count();
    $expiringSoon = $tokens->filter(fn ($t) => $t->expires_at !== null && $t->expires_at->isFuture() && $t->expires_at->diffInDays(now()) <= 14)->count();
    $hasApiTokenSearch = trim($token_list_search ?? '') !== '';
    $orgCount = $adminOrganizations->count();
    $canCreate = $adminOrganizations->isNotEmpty();
    $createDisabled = $requiresPaidPlan && $organization && ! $orgHasProPlan;
    // Header Add only when the list already has items — empty state owns the CTA.
    $showShellAdd = $canCreate && $totalTokens > 0;
@endphp

<div>
    @push('breadcrumbs')
        <x-breadcrumb-trail doc-route="docs.api" :items="[
            ['label' => __('Dashboard'), 'href' => route('dashboard'), 'icon' => 'home'],
            ['label' => __('Profile'), 'href' => route('settings.profile'), 'icon' => 'user-circle'],
            ['label' => __('API keys'), 'icon' => 'bolt'],
        ]" />
    @endpush

    <x-profile-shell
        :title="__('API keys')"
        :description="__('Personal access tokens for the dply HTTP API — scoped to an organization, with explicit permissions and an optional IP allow-list.')"
        icon="heroicon-o-bolt"
    >
        {{-- No "Back to profile": the breadcrumb already covers it. --}}
        @if ($showShellAdd)
            <x-slot:actions>
                <button
                    type="button"
                    wire:click="openCreateApiTokenModal"
                    @disabled($createDisabled)
                    class="inline-flex h-6 items-center gap-1 rounded-md bg-brand-ink px-2 text-xs font-semibold text-brand-cream shadow-sm transition-colors hover:bg-brand-forest disabled:cursor-not-allowed disabled:opacity-60"
                >
                    <x-heroicon-o-plus class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                    {{ __('Add API token') }}
                </button>
            </x-slot:actions>
        @endif

        <x-slot:stats>
            <dl class="grid grid-cols-3 gap-px bg-brand-ink/5" aria-label="{{ __('API tokens at a glance') }}">
                <div class="bg-white px-3 py-2">
                    <dt class="text-2xs font-semibold uppercase tracking-wide text-brand-mist">{{ __('Tokens') }}</dt>
                    <dd class="mt-0.5 flex items-baseline gap-1.5">
                        <span class="font-mono text-base font-semibold tabular-nums text-brand-ink">{{ $totalTokens }}</span>
                        <span class="truncate text-xs text-brand-moss">
                            {{ $totalTokens !== $activeTokens ? __(':n active', ['n' => $activeTokens]) : __('all active') }}
                        </span>
                    </dd>
                </div>
                <div @class([
                    'px-3 py-2',
                    'bg-amber-50' => $expiringSoon > 0,
                    'bg-white' => $expiringSoon === 0,
                ])>
                    <dt class="text-2xs font-semibold uppercase tracking-wide text-brand-mist">{{ __('Expiring') }}</dt>
                    <dd class="mt-0.5 flex items-baseline gap-1.5">
                        <span class="font-mono text-base font-semibold tabular-nums text-brand-ink">{{ $expiringSoon }}</span>
                        <span class="truncate text-xs text-brand-moss">{{ __('within 14 days') }}</span>
                    </dd>
                </div>
                <div class="bg-white px-3 py-2">
                    <dt class="text-2xs font-semibold uppercase tracking-wide text-brand-mist">{{ __('Scope') }}</dt>
                    <dd class="mt-0.5 flex items-baseline gap-1.5">
                        <span class="font-mono text-base font-semibold tabular-nums text-brand-ink">{{ $orgCount }}</span>
                        <span class="truncate text-xs text-brand-moss">{{ trans_choice('org you can issue against|orgs you can issue against', $orgCount) }}</span>
                    </dd>
                </div>
            </dl>
        </x-slot:stats>

        @if ($errors->isNotEmpty())
            <div class="border-b border-brand-ink/10 px-3 py-2 sm:px-4">
                <x-livewire-validation-errors />
            </div>
        @endif

        @if (! $canCreate)
            <div class="border-b border-brand-ink/10 px-3 py-2 sm:px-4" role="status">
                <div class="rounded-md border border-amber-200 bg-amber-50 px-2.5 py-1.5 text-xs text-amber-950">
                    <span class="inline-flex items-center gap-1.5 font-semibold">
                        <x-heroicon-m-exclamation-triangle class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                        {{ __('Admin access required') }}
                    </span>
                    <p class="mt-1 leading-relaxed">{{ __('You need to be an organization admin to create API tokens. Ask an owner to promote you or create an organization first.') }}</p>
                </div>
            </div>
        @else
            @if ($createDisabled)
                <div class="border-b border-brand-ink/10 px-3 py-2 sm:px-4" role="status">
                    <div class="rounded-md border border-sky-200 bg-sky-50 px-2.5 py-1.5 text-xs text-sky-950">
                        <span class="inline-flex items-center gap-1.5 font-semibold">
                            <x-heroicon-m-information-circle class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                            {{ __('Pro plan required to create tokens') }}
                        </span>
                        <p class="mt-1 leading-relaxed">{{ __('Token creation needs an active Pro subscription on the selected organization. Existing tokens can still be revoked.') }}</p>
                    </div>
                </div>
            @endif

            @if ($new_token_plaintext)
                {{-- One-shot token reveal: header line, the value, copy + dismiss.
                     The icon tile and the three stacked prose lines were chrome
                     around a string you're meant to grab and leave. --}}
                <div class="border-b border-brand-ink/10 bg-emerald-50/70 px-3 py-2 sm:px-4" role="status">
                    <div class="flex flex-wrap items-baseline gap-x-2">
                        <p class="inline-flex items-center gap-1 text-sm font-semibold text-emerald-950">
                            <x-heroicon-m-check-circle class="h-3.5 w-3.5 shrink-0 text-emerald-700" aria-hidden="true" />
                            {{ __('Copy this token now — you won\'t see it again') }}
                        </p>
                        <p class="min-w-0 truncate text-xs text-emerald-900/80">{{ $new_token_name }}</p>
                    </div>
                    <div class="mt-1.5 flex flex-wrap items-center gap-1.5">
                        <code class="min-w-0 flex-1 break-all rounded-md border border-emerald-200 bg-white px-2.5 py-1.5 font-mono text-xs text-brand-ink">{{ $new_token_plaintext }}</code>
                        <button
                            type="button"
                            x-data="{ copied: false }"
                            x-on:click="navigator.clipboard.writeText(@js($new_token_plaintext)); copied = true; setTimeout(() => copied = false, 2000)"
                            class="inline-flex h-7 shrink-0 items-center justify-center gap-1 rounded-md bg-brand-ink px-2.5 text-xs font-semibold text-brand-cream shadow-sm transition-colors hover:bg-brand-forest"
                        >
                            <span x-show="!copied" class="inline-flex items-center gap-1">
                                <x-heroicon-o-clipboard-document class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                                {{ __('Copy') }}
                            </span>
                            <span x-show="copied" x-cloak class="inline-flex items-center gap-1">
                                <x-heroicon-o-check class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                                {{ __('Copied') }}
                            </span>
                        </button>
                        <button type="button" wire:click="clearNewToken" class="shrink-0 text-xs font-semibold text-emerald-900 underline underline-offset-2 hover:no-underline">
                            {{ __('Dismiss') }}
                        </button>
                    </div>
                </div>
            @endif

            @if ($tokens->isNotEmpty() || $hasApiTokenSearch)
                <div class="flex flex-col gap-2 border-b border-brand-ink/10 px-3 py-2 sm:flex-row sm:items-center sm:justify-end sm:px-4">
                    <div class="w-full sm:max-w-xs">
                        <label for="api_token_search" class="sr-only">{{ __('Search') }}</label>
                        <div class="relative">
                            <span class="pointer-events-none absolute inset-y-0 start-0 flex items-center ps-2.5 text-brand-mist">
                                <x-heroicon-o-magnifying-glass class="h-3.5 w-3.5" aria-hidden="true" />
                            </span>
                            <input
                                id="api_token_search"
                                type="search"
                                wire:model.live.debounce.300ms="token_list_search"
                                placeholder="{{ __('Search tokens by name…') }}"
                                autocomplete="off"
                                class="h-7 w-full rounded-md border-brand-ink/15 bg-white py-0 ps-8 pe-2.5 text-xs text-brand-ink placeholder:text-brand-mist shadow-sm focus:border-brand-sage focus:ring-brand-sage"
                            />
                        </div>
                    </div>
                </div>
            @endif

            @if (! $hasApiTokenSearch && $tokens->isEmpty())
                <div class="flex flex-col items-center justify-center px-3 py-10 text-center sm:px-4">
                    <span class="flex h-9 w-9 items-center justify-center rounded-xl bg-brand-sand/45 text-brand-mist ring-1 ring-brand-ink/10">
                        <x-heroicon-o-bolt class="h-4 w-4" aria-hidden="true" />
                    </span>
                    <p class="mt-2.5 text-sm font-semibold text-brand-ink">{{ __('No API tokens yet') }}</p>
                    <p class="mt-1 max-w-md text-xs leading-relaxed text-brand-moss">
                        {{ __('Issue your first token to call the HTTP API from CI, scripts, or other automation.') }}
                    </p>
                    <button
                        type="button"
                        wire:click="openCreateApiTokenModal"
                        @disabled($createDisabled)
                        class="mt-3 inline-flex h-7 items-center gap-1.5 rounded-md bg-brand-ink px-2.5 text-xs font-semibold text-brand-cream shadow-sm transition-colors hover:bg-brand-forest disabled:cursor-not-allowed disabled:opacity-60"
                    >
                        <x-heroicon-o-plus class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                        {{ __('Add API token') }}
                    </button>
                </div>
            @elseif ($hasApiTokenSearch && $tokens->isEmpty())
                <div class="flex flex-col items-center justify-center px-3 py-10 text-center sm:px-4">
                    <span class="flex h-8 w-8 items-center justify-center rounded-lg bg-brand-sand/45 text-brand-mist ring-1 ring-brand-ink/10">
                        <x-heroicon-o-magnifying-glass class="h-4 w-4" aria-hidden="true" />
                    </span>
                    <p class="mt-2 text-sm font-medium text-brand-ink">{{ __('No tokens match this search.') }}</p>
                    <button type="button" wire:click="$set('token_list_search', '')" class="mt-2 text-xs font-semibold text-brand-sage hover:text-brand-ink">{{ __('Clear search') }}</button>
                </div>
            @else
                {{-- Aligned columns rather than one wrapping line per token. Tokens
                     are near-always named for the tool that issued them ("dply CLI"
                     five times over), so the masked value is the real identifier and
                     gets its own line instead of being buried mid-sentence. Abilities
                     collapse behind a count — a native <details>, so expanding one
                     costs no round-trip and no JS. --}}
                <div class="hidden border-b border-brand-ink/10 bg-brand-sand/20 px-4 py-1.5 sm:grid sm:grid-cols-[minmax(0,1fr)_8.5rem_8.5rem_5.5rem] sm:gap-3">
                    @foreach ([__('Token'), __('Last used'), __('Expires')] as $heading)
                        <span class="text-2xs font-semibold uppercase tracking-wide text-brand-mist">{{ $heading }}</span>
                    @endforeach
                    <span class="sr-only">{{ __('Actions') }}</span>
                </div>
                <ul role="list" class="divide-y divide-brand-ink/10">
                    @foreach ($tokens as $t)
                        @php
                            $expired = $t->expires_at !== null && $t->expires_at->isPast();
                            $expiringSoonRow = $t->expires_at !== null && $t->expires_at->isFuture() && $t->expires_at->diffInDays(now()) <= 14;
                            $abilityCount = count($t->abilities ?? []);
                        @endphp
                        <li wire:key="api-token-{{ $t->id }}" class="grid gap-1.5 px-3 py-2.5 transition-colors hover:bg-brand-sand/15 sm:grid-cols-[minmax(0,1fr)_8.5rem_8.5rem_5.5rem] sm:items-baseline sm:gap-3 sm:px-4">
                            {{-- Identity: name + state, with the masked value beneath. --}}
                            <div class="min-w-0">
                                <div class="flex flex-wrap items-baseline gap-x-2 gap-y-0.5">
                                    <span class="truncate text-sm font-semibold text-brand-ink">{{ $t->name }}</span>
                                    @if ($expired)
                                        <span class="inline-flex shrink-0 items-center gap-0.5 rounded border border-red-200 bg-red-50 px-1 py-px text-2xs font-semibold uppercase tracking-wide text-red-700">
                                            <x-heroicon-m-no-symbol class="h-3 w-3" aria-hidden="true" />
                                            {{ __('Expired') }}
                                        </span>
                                    @elseif ($expiringSoonRow)
                                        <span class="inline-flex shrink-0 items-center gap-0.5 rounded border border-amber-200 bg-amber-50 px-1 py-px text-2xs font-semibold uppercase tracking-wide text-amber-900">
                                            <x-heroicon-m-clock class="h-3 w-3" aria-hidden="true" />
                                            {{ __('Expiring') }}
                                        </span>
                                    @endif
                                </div>
                                <div class="mt-0.5 flex flex-wrap items-center gap-x-2 gap-y-0.5">
                                    <code class="truncate font-mono text-xs text-brand-mist">{{ $t->masked_display }}</code>
                                    @if ($t->allowed_ips)
                                        <span class="inline-flex shrink-0 items-center gap-1 text-2xs text-brand-moss" title="{{ __('IP allow-list') }}">
                                            <x-heroicon-m-globe-alt class="h-3 w-3 shrink-0" aria-hidden="true" />
                                            <span class="font-mono">{{ implode(', ', $t->allowed_ips) }}</span>
                                        </span>
                                    @endif
                                </div>
                                @if ($abilityCount > 0)
                                    <details class="group mt-1">
                                        <summary class="inline-flex cursor-pointer list-none items-center gap-1 text-2xs font-medium text-brand-moss transition-colors hover:text-brand-ink [&::-webkit-details-marker]:hidden">
                                            <x-heroicon-m-chevron-right class="h-3 w-3 shrink-0 transition-transform group-open:rotate-90" aria-hidden="true" />
                                            {{ trans_choice(':count permission|:count permissions', $abilityCount, ['count' => $abilityCount]) }}
                                        </summary>
                                        <div class="mt-1 flex flex-wrap gap-1">
                                            @foreach ($t->abilities as $ability)
                                                <code class="inline-flex items-center rounded bg-brand-sand/55 px-1 py-px font-mono text-2xs text-brand-moss">{{ $ability }}</code>
                                            @endforeach
                                        </div>
                                    </details>
                                @endif
                            </div>

                            <div class="text-xs text-brand-moss">
                                <span class="text-brand-mist sm:hidden">{{ __('Last used') }}: </span>
                                {{ $t->last_used_at ? $t->last_used_at->diffForHumans() : __('Never') }}
                            </div>

                            <div class="text-xs {{ $expired ? 'text-red-700' : ($expiringSoonRow ? 'text-amber-900' : 'text-brand-moss') }}">
                                <span class="text-brand-mist sm:hidden">{{ __('Expires') }}: </span>
                                {{ $t->expires_at ? $t->expires_at->format('M j, Y') : __('Never') }}
                            </div>

                            <button
                                type="button"
                                wire:click="openConfirmActionModal('revokeToken', [{{ $t->id }}], @js(__('Revoke token')), @js(__('Revoke this token? It will stop working immediately.')), @js(__('Revoke')), true)"
                                class="inline-flex h-6 shrink-0 items-center gap-1 justify-self-start rounded-md border border-transparent px-2 text-xs font-semibold text-brand-moss transition hover:border-rose-200 hover:bg-rose-50 hover:text-rose-700 sm:justify-self-end"
                            >
                                <x-heroicon-o-no-symbol class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                                {{ __('Revoke') }}
                            </button>
                        </li>
                    @endforeach
                </ul>
            @endif

            <x-modal
                name="create-api-token-modal"
                :show="false"
                maxWidth="2xl"
                overlayClass="bg-brand-ink/30"
                panelClass="dply-modal-panel overflow-hidden shadow-xl flex max-h-[min(90vh,880px)] flex-col"
                focusable
            >
                <form wire:submit="createToken" class="flex min-h-0 flex-1 flex-col">
                    <div class="flex shrink-0 items-start gap-3 border-b border-brand-ink/10 px-6 py-5">
                        <x-icon-badge>
                            <x-heroicon-o-bolt class="h-5 w-5" aria-hidden="true" />
                        </x-icon-badge>
                        <div class="min-w-0">
                            <p class="text-xs font-semibold uppercase tracking-[0.18em] text-brand-sage">{{ __('Personal access token') }}</p>
                            <h2 class="mt-1 text-lg font-semibold text-brand-ink">{{ __('Create token') }}</h2>
                            <p class="mt-1 text-sm leading-6 text-brand-moss">
                                {{ __('Use tokens to authenticate to the dply HTTP API from CI/CD or scripts.') }}
                            </p>
                        </div>
                    </div>

                    <div class="min-h-0 flex-1 space-y-5 overflow-y-auto px-6 py-6">
                        @if ($isDeployerRole)
                            <div class="rounded-xl border border-sky-200 bg-sky-50 px-4 py-3 text-sm text-sky-900">
                                <span class="inline-flex items-center gap-1.5 font-semibold">
                                    <x-heroicon-m-information-circle class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                                    {{ __('Deploy-only role') }}
                                </span>
                                <p class="mt-1 text-xs leading-relaxed">{{ __('Tokens can only include server and site read + deploy permissions, matching organization policy.') }}</p>
                            </div>
                        @endif

                        <div class="grid gap-5 sm:grid-cols-2">
                            <div>
                                <x-input-label for="api_org_modal" :value="__('Organization')" />
                                <select
                                    id="api_org_modal"
                                    wire:model.live="organization_id"
                                    class="mt-1 block w-full rounded-lg border-brand-ink/15 bg-white px-3 py-2 text-sm text-brand-ink shadow-sm focus:border-brand-sage focus:ring-brand-sage"
                                >
                                    @foreach ($adminOrganizations as $o)
                                        <option value="{{ $o->id }}">{{ $o->name }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div>
                                <x-input-label for="api_token_name_modal" :value="__('Name')" />
                                <x-text-input id="api_token_name_modal" wire:model="token_name" type="text" class="mt-1 block w-full" placeholder="{{ __('e.g. CI deploy') }}" autocomplete="off" />
                                <x-input-error :messages="$errors->get('token_name')" class="mt-2" />
                            </div>
                        </div>

                        <div>
                            <x-input-label for="api_token_exp_modal" :value="__('Expires (optional)')" />
                            <x-text-input id="api_token_exp_modal" wire:model="token_expires_at" type="date" class="mt-1 block w-full max-w-xs" min="{{ date('Y-m-d', strtotime('+1 day')) }}" />
                            <p class="mt-1 text-xs text-brand-mist">{{ __('Leave blank for no expiry. Short-lived tokens are safer for CI runners.') }}</p>
                            <x-input-error :messages="$errors->get('token_expires_at')" class="mt-2" />
                        </div>

                        <div>
                            <x-input-label for="api_token_ips_modal" :value="__('Whitelist IP addresses')" />
                            <textarea
                                id="api_token_ips_modal"
                                wire:model="token_allowed_ips_text"
                                rows="3"
                                class="mt-1 block w-full rounded-lg border-brand-ink/15 bg-white px-3 py-2 font-mono text-xs text-brand-ink shadow-sm focus:border-brand-sage focus:ring-brand-sage"
                                placeholder="{{ __('Comma-separated or one per line. Leave empty to allow any IP.') }}"
                            ></textarea>
                            <p class="mt-1 text-xs text-brand-mist">{{ __('IPv4, IPv6, or IPv4 CIDR ranges.') }}</p>
                            <x-input-error :messages="$errors->get('token_allowed_ips_text')" class="mt-2" />
                        </div>

                        <div>
                            <div class="flex items-baseline justify-between gap-3">
                                <p class="text-sm font-semibold text-brand-ink">{{ __('Permissions') }}</p>
                                <button
                                    type="button"
                                    wire:click="toggleAllPermissions"
                                    class="inline-flex items-center gap-1.5 rounded-lg border border-brand-ink/15 bg-white px-2.5 py-1 text-xs font-semibold text-brand-ink shadow-sm transition hover:bg-brand-sand/40"
                                >
                                    <x-heroicon-o-arrows-right-left class="h-4 w-4 shrink-0" aria-hidden="true" />
                                    {{ __('Toggle all') }}
                                </button>
                            </div>
                            @if (count($selected_abilities) === 0)
                                <div class="mt-2 rounded-xl border border-amber-200 bg-amber-50 px-4 py-2.5 text-xs text-amber-900">
                                    <span class="inline-flex items-center gap-1.5 font-semibold">
                                        <x-heroicon-m-exclamation-triangle class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                                        {{ __('No permissions selected') }}
                                    </span>
                                    <p class="mt-1 leading-relaxed">{{ __('Pick at least one permission so the token can do something with the API.') }}</p>
                                </div>
                            @endif
                            <x-input-error :messages="$errors->get('selected_abilities')" class="mt-2" />

                            <div class="mt-3 grid gap-3 sm:grid-cols-2">
                                @foreach ($permissionCategories as $cat)
                                    @php
                                        $catId = $cat['id'];
                                        $perms = $cat['permissions'] ?? [];
                                        $abilityList = collect($perms)->pluck('ability')->all();
                                        $selectedInCat = count(array_intersect($selected_abilities, $abilityList));
                                        $totalInCat = count($abilityList);
                                        $expanded = in_array($catId, $expanded_categories, true);
                                        $allSelected = $totalInCat > 0 && $selectedInCat === $totalInCat;
                                    @endphp
                                    <div class="overflow-hidden rounded-xl border border-brand-ink/10 bg-white">
                                        <button
                                            type="button"
                                            wire:click="toggleCategoryExpand('{{ $catId }}')"
                                            @class([
                                                'flex w-full items-center justify-between gap-2 px-3 py-2.5 text-left text-sm font-semibold transition',
                                                'bg-brand-sage/8 text-brand-forest' => $allSelected,
                                                'bg-brand-cream/40 text-brand-ink hover:bg-brand-sand/40' => ! $allSelected,
                                            ])
                                            aria-expanded="{{ $expanded ? 'true' : 'false' }}"
                                        >
                                            <span>{{ $cat['label'] }}</span>
                                            <span class="inline-flex items-center gap-1.5 text-xs font-medium text-brand-moss">
                                                <span @class([
                                                    'rounded-full px-1.5 py-0.5 tabular-nums',
                                                    'bg-brand-sage/20 text-brand-forest' => $selectedInCat > 0,
                                                    'bg-brand-sand/60 text-brand-mist' => $selectedInCat === 0,
                                                ])>{{ $selectedInCat }}/{{ $totalInCat }}</span>
                                                <x-heroicon-m-chevron-down class="h-4 w-4 transition-transform {{ $expanded ? 'rotate-180' : '' }}" aria-hidden="true" />
                                            </span>
                                        </button>
                                        @if ($expanded)
                                            <div class="space-y-1.5 border-t border-brand-ink/10 bg-white px-3 py-3">
                                                @foreach ($perms as $p)
                                                    @php $ab = $p['ability']; @endphp
                                                    <label class="flex cursor-pointer items-center gap-2 rounded-lg px-1.5 py-1 text-sm text-brand-moss hover:bg-brand-sand/30">
                                                        <input
                                                            type="checkbox"
                                                            wire:click.prevent="toggleAbility('{{ $ab }}')"
                                                            @checked(in_array($ab, $selected_abilities, true))
                                                            class="h-4 w-4 rounded border-brand-ink/30 text-brand-forest focus:ring-brand-forest"
                                                        />
                                                        <span class="text-brand-ink">{{ $p['label'] }}</span>
                                                    </label>
                                                @endforeach
                                            </div>
                                        @endif
                                    </div>
                                @endforeach
                            </div>
                        </div>
                    </div>

                    <div class="flex shrink-0 flex-wrap justify-end gap-3 border-t border-brand-ink/10 bg-brand-sand/25 px-6 py-4">
                        <x-secondary-button type="button" wire:click="closeCreateApiTokenModal">
                            {{ __('Cancel') }}
                        </x-secondary-button>
                        <button
                            type="submit"
                            wire:loading.attr="disabled"
                            wire:target="createToken"
                            @disabled($createDisabled)
                            class="inline-flex items-center justify-center gap-2 rounded-xl bg-brand-ink px-4 py-2 text-sm font-semibold text-brand-cream shadow-md transition-colors hover:bg-brand-forest disabled:cursor-not-allowed disabled:opacity-60"
                        >
                            <span wire:loading.remove wire:target="createToken" class="inline-flex items-center gap-2">
                                <x-heroicon-o-plus class="h-4 w-4 shrink-0" aria-hidden="true" />
                                {{ __('Create token') }}
                            </span>
                            <span wire:loading wire:target="createToken" class="inline-flex items-center gap-2">
                                <x-spinner variant="cream" size="sm" />
                                {{ __('Creating…') }}
                            </span>
                        </button>
                    </div>
                </form>
            </x-modal>
        @endif
    </x-profile-shell>

    <x-slot name="modals">
        @include('livewire.partials.confirm-action-modal')
    </x-slot>
</div>
