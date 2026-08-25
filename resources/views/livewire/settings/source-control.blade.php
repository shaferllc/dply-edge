@php
    $providers = $this->providers;
    $totalOAuth = collect($providers)->sum(fn ($p) => $p['accounts']->count());
    $totalPats = collect($providers)->sum(fn ($p) => $p['pats']->count());
    $providersWithAny = collect($providers)
        ->filter(fn ($p) => $p['accounts']->isNotEmpty() || $p['pats']->isNotEmpty())
        ->count();
@endphp

<div>
    <x-livewire-validation-errors />

    @push('breadcrumbs')
        <x-breadcrumb-trail doc-route="docs.markdown" doc-slug="source-control" :items="[
            ['label' => __('Dashboard'), 'href' => route('dashboard'), 'icon' => 'home'],
            ['label' => __('Profile'), 'href' => route('settings.profile'), 'icon' => 'user-circle'],
            ['label' => __('Source control'), 'icon' => 'code-bracket-square'],
        ]" />
    @endpush

    <x-profile-shell
        :title="__('Source control')"
        :description="__('Link GitHub, GitLab, or Bitbucket via OAuth, or paste a personal access token for self-hosted hosts and machine users.')"
        icon="heroicon-o-code-bracket"
    >
        <x-slot:actions>
            {{-- No "Back to profile": the breadcrumb already covers it. --}}
            @if (auth()->user()->currentOrganization())
                <a
                    href="{{ route('credentials.index') }}"
                    wire:navigate
                    class="inline-flex h-6 items-center gap-1 rounded-md border border-brand-ink/15 bg-white px-2 text-xs font-semibold text-brand-ink shadow-sm transition-colors hover:bg-brand-sand/40"
                >
                    <x-heroicon-o-key class="h-3.5 w-3.5 shrink-0 opacity-90" aria-hidden="true" />
                    {{ __('Credentials') }}
                </a>
            @endif
        </x-slot:actions>

        <x-slot:stats>
            <dl class="grid grid-cols-3 gap-px bg-brand-ink/5" aria-label="{{ __('Source control at a glance') }}">
                <div class="bg-white px-3 py-2">
                    <dt class="text-2xs font-semibold uppercase tracking-wide text-brand-mist">{{ __('Hosts') }}</dt>
                    <dd class="mt-0.5 flex items-baseline gap-1.5">
                        <span class="font-mono text-base font-semibold tabular-nums text-brand-ink">{{ $providersWithAny }}</span>
                        <span class="truncate text-xs text-brand-moss">/ {{ count($providers) }} {{ __('linked') }}</span>
                    </dd>
                </div>
                <div @class([
                    'px-3 py-2',
                    'bg-brand-sage/10' => $totalOAuth > 0,
                    'bg-white' => $totalOAuth === 0,
                ])>
                    <dt class="text-2xs font-semibold uppercase tracking-wide text-brand-mist">{{ __('OAuth') }}</dt>
                    <dd class="mt-0.5 flex items-baseline gap-1.5">
                        <span class="font-mono text-base font-semibold tabular-nums text-brand-ink">{{ $totalOAuth }}</span>
                        <span class="truncate text-xs text-brand-moss">{{ trans_choice('account|accounts', $totalOAuth) }}</span>
                    </dd>
                </div>
                <div @class([
                    'px-3 py-2',
                    'bg-violet-50' => $totalPats > 0,
                    'bg-white' => $totalPats === 0,
                ])>
                    <dt class="text-2xs font-semibold uppercase tracking-wide text-brand-mist">{{ __('Tokens') }}</dt>
                    <dd class="mt-0.5 flex items-baseline gap-1.5">
                        <span class="font-mono text-base font-semibold tabular-nums text-brand-ink">{{ $totalPats }}</span>
                        <span class="truncate text-xs text-brand-moss">{{ trans_choice('PAT|PATs', $totalPats) }}</span>
                    </dd>
                </div>
            </dl>
        </x-slot:stats>

        {{-- Prefer the terminal? Point at the CLI install + sign-in steps on
             /profile/cli instead of duplicating them here. --}}
        <div class="flex flex-wrap items-center justify-between gap-2 border-b border-brand-ink/10 bg-brand-sand/15 px-3 py-2 sm:px-4">
            <p class="flex min-w-0 items-center gap-1.5 text-xs text-brand-moss">
                <x-heroicon-o-command-line class="h-3.5 w-3.5 shrink-0 text-brand-mist" aria-hidden="true" />
                {{ __('Prefer the command line? Link repos and deploy from your terminal.') }}
            </p>
            <a href="{{ route('profile.cli') }}" wire:navigate class="inline-flex h-6 shrink-0 items-center gap-1 whitespace-nowrap rounded-md border border-brand-ink/15 bg-white px-2 text-xs font-semibold text-brand-ink shadow-sm transition-colors hover:bg-brand-sand/40">
                <x-heroicon-o-arrow-down-tray class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                {{ __('Install the CLI') }}
            </a>
        </div>

        @error('unlink')
            <div class="border-b border-brand-ink/10 px-3 py-2 sm:px-4">
                <div class="rounded-md border border-red-200 bg-red-50 px-2.5 py-1.5 text-xs text-red-800" role="alert">
                    <span class="inline-flex items-center gap-1.5 font-semibold">
                        <x-heroicon-m-exclamation-triangle class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                        {{ $message }}
                    </span>
                </div>
            </div>
        @enderror

        @forelse ($providers as $provider)
            @php
                $count = $this->repositoryCount($provider['host']);
                $hasAny = $provider['accounts']->isNotEmpty() || $provider['pats']->isNotEmpty();
                $providerLinkedCount = $provider['accounts']->count() + $provider['pats']->count();
            @endphp

            <div class="border-b border-brand-ink/10 last:border-b-0" aria-labelledby="sc-heading-{{ $provider['id'] }}">
                {{-- Provider header strip: identity, linked count, and both
                     actions (OAuth link + add PAT) on one line. The per-provider
                     blurb lives in the shell description now. --}}
                <div class="flex flex-wrap items-center gap-2 bg-brand-sand/15 px-3 py-2 sm:px-4">
                    <x-oauth-provider-icon :provider="$provider['id']" size="h-4 w-4 shrink-0" />
                    <h3 id="sc-heading-{{ $provider['id'] }}" class="min-w-0 flex-1 truncate text-sm font-semibold text-brand-ink">{{ $provider['name'] }}</h3>
                    @if ($hasAny)
                        <span class="shrink-0 inline-flex items-center gap-1 rounded-full bg-brand-sage/15 px-1.5 py-px text-2xs font-semibold tabular-nums text-brand-forest ring-1 ring-brand-sage/20">
                            <x-heroicon-m-check-circle class="h-3 w-3" aria-hidden="true" />
                            {{ $providerLinkedCount }}
                        </span>
                    @endif
                    @if ($provider['oauth_enabled'])
                        <a
                            href="{{ route('oauth.redirect', ['provider' => $provider['id']]) }}"
                            class="inline-flex h-6 shrink-0 items-center gap-1 rounded-md bg-brand-ink px-2 text-xs font-semibold text-brand-cream shadow-sm transition-colors hover:bg-brand-forest"
                        >
                            <x-heroicon-o-arrow-top-right-on-square class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                            {{ __('Link :name', ['name' => $provider['name']]) }}
                        </a>
                    @endif
                    <button
                        type="button"
                        wire:click="startAddPat('{{ $provider['id'] }}')"
                        class="inline-flex h-6 shrink-0 items-center gap-1 rounded-md border border-brand-ink/15 bg-white px-2 text-xs font-semibold text-brand-ink shadow-sm transition-colors hover:bg-brand-sand/40"
                    >
                        <x-heroicon-o-plus class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                        {{ __('Add token') }}
                    </button>
                </div>

                {{-- PAT inline editor. --}}
                @if ($addingPatProvider === $provider['id'])
                    <div class="space-y-2.5 border-t border-brand-ink/10 bg-brand-sage/5 px-3 py-2.5 sm:px-4">
                        <div>
                            <p class="text-sm font-semibold text-brand-ink">{{ __('Add a :name personal access token', ['name' => $provider['name']]) }}</p>
                            <p class="mt-0.5 text-xs leading-relaxed text-brand-moss">
                                @if ($provider['id'] === 'github')
                                    {{ __('Classic PATs need repo and admin:repo_hook scopes. Fine-grained tokens need Contents (Read), Metadata (Read), and Webhooks (Read & Write) for the target repositories.') }}
                                @elseif ($provider['id'] === 'gitlab')
                                    {{ __('Token needs the api scope. Group-scoped tokens cover every project under that group.') }}
                                @else
                                    {{ __('App password or workspace access token with repository:read and webhook permissions.') }}
                                @endif
                            </p>
                        </div>

                        <div class="grid gap-2.5 sm:grid-cols-2">
                            <div>
                                <x-input-label for="pat-label-{{ $provider['id'] }}" :value="__('Label (optional)')" />
                                <x-text-input id="pat-label-{{ $provider['id'] }}" wire:model="patLabel" class="mt-1 block w-full" placeholder="{{ __('e.g. machine user, work account') }}" />
                                @error('patLabel') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                            </div>
                            <div>
                                <x-input-label for="pat-token-{{ $provider['id'] }}" :value="__('Token')" />
                                <x-text-input id="pat-token-{{ $provider['id'] }}" type="password" wire:model="patToken" class="mt-1 block w-full font-mono" autocomplete="off" />
                                @error('patToken') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                            </div>
                        </div>

                        @if ($provider['id'] !== 'bitbucket')
                            <div>
                                <x-input-label for="pat-base-{{ $provider['id'] }}" :value="$provider['id'] === 'github' ? __('API base URL (optional, for GitHub Enterprise)') : __('API base URL (optional, for self-hosted GitLab)')" />
                                <x-text-input
                                    id="pat-base-{{ $provider['id'] }}"
                                    wire:model="patApiBaseUrl"
                                    class="mt-1 block w-full font-mono"
                                    placeholder="{{ $provider['id'] === 'github' ? 'https://github.example.com/api/v3' : 'https://gitlab.example.com' }}"
                                />
                                @error('patApiBaseUrl') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                                <p class="mt-1 text-xs text-brand-mist">{{ __('Leave blank for the public :host host.', ['host' => $provider['host']]) }}</p>
                            </div>
                        @endif

                        <div class="flex flex-wrap items-center justify-end gap-2 border-t border-brand-ink/10 pt-2">
                            <button type="button" wire:click="cancelAddPat" class="text-xs font-medium text-brand-moss hover:text-brand-ink">{{ __('Cancel') }}</button>
                            <button type="button" wire:click="savePat" class="inline-flex h-6 items-center gap-1 rounded-md bg-brand-ink px-2 text-xs font-semibold text-brand-cream shadow-sm hover:bg-brand-forest">
                                <x-heroicon-o-check class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                                {{ __('Validate and save') }}
                            </button>
                        </div>
                    </div>
                @endif

                {{-- Linked accounts + tokens list. --}}
                @if (! $hasAny)
                    <div class="px-3 py-3 text-center sm:px-4">
                        <x-empty-state
                            borderless
                            compact
                            icon="heroicon-o-link"
                            :title="__('No linked accounts or tokens yet')"
                            :description="__('Connect a Git provider above so dply can read your repositories and deploy on push.')"
                        />
                    </div>
                @else
                    <ul class="divide-y divide-brand-ink/10 border-t border-brand-ink/10">
                        @foreach ($provider['accounts'] as $account)
                            <li wire:key="sc-oauth-{{ $account->id }}" class="flex flex-col gap-1.5 px-3 py-2 transition-colors hover:bg-brand-sand/15 sm:flex-row sm:items-center sm:justify-between sm:gap-3 sm:px-4">
                                <div class="min-w-0 flex-1">
                                    <div class="flex flex-wrap items-baseline gap-x-2 gap-y-1">
                                        <span class="inline-flex items-center rounded-md border border-brand-sage/30 bg-brand-sage/15 px-1.5 py-0.5 text-2xs font-semibold uppercase tracking-wide text-brand-forest">{{ __('OAuth') }}</span>
                                        <span class="truncate text-sm font-semibold text-brand-ink">{{ $account->nickname ?? '—' }}</span>
                                        @if ($editingId === (string) $account->id)
                                            <x-text-input wire:model="editLabel" class="block w-full max-w-xs text-xs" placeholder="{{ __('Label (optional)') }}" />
                                        @elseif ($account->label)
                                            <span class="inline-flex items-center rounded-md bg-brand-sand/60 px-1.5 py-0.5 text-2xs font-medium text-brand-moss">{{ $account->label }}</span>
                                        @endif
                                    </div>
                                    <p class="mt-0.5 flex flex-wrap items-center gap-x-2 gap-y-0.5 text-xs text-brand-mist">
                                        <span>{{ __('Added :time', ['time' => $account->created_at?->diffForHumans() ?? '—']) }}</span>
                                        {{-- Health stamped by the daily credential check — OAuth
                                             tokens die exactly like PATs; the fix is a reconnect. --}}
                                        @if ($account->validation_error)
                                            <span class="text-brand-mist">·</span>
                                            <span class="font-medium text-rose-600">{{ __('Rejected by the provider (:error) — reconnect this account', ['error' => $account->validation_error]) }}</span>
                                        @elseif ($account->expires_at && $account->expires_at->lte(now()->addDays(7)))
                                            <span class="text-brand-mist">·</span>
                                            <span class="font-medium {{ $account->expires_at->isPast() ? 'text-rose-600' : 'text-amber-700' }}">
                                                {{ $account->expires_at->isPast()
                                                    ? __('Expired :time — reconnect this account', ['time' => $account->expires_at->diffForHumans()])
                                                    : __('Expires :time', ['time' => $account->expires_at->diffForHumans()]) }}
                                            </span>
                                        @endif
                                        @if ($count > 0)
                                            <span class="text-brand-mist">·</span>
                                            <a href="{{ route('edge.index') }}" wire:navigate class="font-semibold text-brand-sage hover:text-brand-ink">{{ trans_choice(':n site|:n sites', $count, ['n' => $count]) }}</a>
                                        @endif
                                    </p>
                                </div>
                                <div class="flex shrink-0 flex-wrap items-center justify-end gap-1">
                                    @if ($editingId === (string) $account->id)
                                        <button type="button" wire:click="saveEdit" class="inline-flex h-6 items-center gap-1 rounded-md border border-brand-ink/15 bg-white px-2 text-xs font-semibold text-brand-ink shadow-sm hover:bg-brand-sand/40">
                                            <x-heroicon-o-check class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                                            {{ __('Save') }}
                                        </button>
                                        <button type="button" wire:click="cancelEdit" class="text-xs font-medium text-brand-moss hover:text-brand-ink">{{ __('Cancel') }}</button>
                                    @else
                                        <button type="button" wire:click="startEdit('{{ $account->id }}')" class="inline-flex h-6 items-center justify-center gap-1 rounded-md border border-brand-ink/15 bg-white px-2 text-xs font-semibold text-brand-ink shadow-sm transition-colors hover:bg-brand-sand/50 disabled:cursor-not-allowed disabled:opacity-50">
                                            <x-heroicon-o-pencil-square class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                                            {{ __('Edit') }}
                                        </button>
                                        <button type="button" wire:click="openConfirmActionModal('unlinkAccount', ['{{ $account->id }}'], @js(__('Unlink account')), @js(__('Unlink this account? Deploy keys and webhooks for sites using this identity are unchanged.')), @js(__('Unlink')), true)" class="inline-flex h-6 items-center gap-1 rounded-md border border-brand-ink/15 bg-white px-2.5 text-xs font-semibold text-brand-moss shadow-sm transition hover:border-rose-200 hover:bg-rose-50 hover:text-rose-700">
                                            <x-heroicon-o-link-slash class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                                            {{ __('Unlink') }}
                                        </button>
                                    @endif
                                </div>
                            </li>
                        @endforeach

                        @foreach ($provider['pats'] as $pat)
                            <li wire:key="sc-pat-{{ $pat->id }}" class="flex flex-col gap-1.5 px-3 py-2 transition-colors hover:bg-brand-sand/15 sm:flex-row sm:items-center sm:justify-between sm:gap-3 sm:px-4">
                                <div class="min-w-0 flex-1">
                                    <div class="flex flex-wrap items-baseline gap-x-2 gap-y-1">
                                        <span class="inline-flex items-center rounded-md border border-violet-200 bg-violet-50 px-1.5 py-0.5 text-2xs font-semibold uppercase tracking-wide text-violet-700">{{ __('PAT') }}</span>
                                        <span class="truncate text-sm font-semibold text-brand-ink">{{ $pat->nickname ?? '—' }}</span>
                                        @if ($editingPatId === (string) $pat->id)
                                            <x-text-input wire:model="editPatLabel" class="block w-full max-w-xs text-xs" placeholder="{{ __('Label (optional)') }}" />
                                        @elseif ($pat->label)
                                            <span class="inline-flex items-center rounded-md bg-brand-sand/60 px-1.5 py-0.5 text-2xs font-medium text-brand-moss">{{ $pat->label }}</span>
                                        @endif
                                    </div>
                                    <p class="mt-0.5 flex flex-wrap items-center gap-x-2 gap-y-0.5 text-xs text-brand-mist">
                                        <span>{{ __('Added :time', ['time' => $pat->created_at?->diffForHumans() ?? '—']) }}</span>
                                        @if ($pat->validation_error)
                                            <span class="text-brand-mist">·</span>
                                            <span class="font-medium text-rose-600">
                                                {{ __('Rejected by the provider (:error) — replace it below', ['error' => $pat->validation_error]) }}
                                            </span>
                                        @elseif ($pat->expires_at)
                                            <span class="text-brand-mist">·</span>
                                            {{-- Real expiry captured from the provider (GitHub reports it
                                                 in a response header) by the daily token health check. --}}
                                            <span @class([
                                                'font-medium text-rose-600' => $pat->expires_at->isPast(),
                                                'font-medium text-amber-700' => ! $pat->expires_at->isPast() && $pat->expires_at->lte(now()->addDays(7)),
                                            ])>
                                                @if ($pat->expires_at->isPast())
                                                    {{ __('Expired :time — replace it below', ['time' => $pat->expires_at->diffForHumans()]) }}
                                                @else
                                                    {{ __('Expires :time', ['time' => $pat->expires_at->diffForHumans()]) }}
                                                @endif
                                            </span>
                                        @elseif ($pat->last_validated_at)
                                            <span class="text-brand-mist">·</span>
                                            {{-- No known expiry — fall back to a staleness nudge: fine-grained
                                                 GitHub PATs expire after 30 days by default. --}}
                                            <span @class(['text-amber-700 font-medium' => $pat->last_validated_at->lt(now()->subDays(25))])>
                                                {{ __('Validated :time', ['time' => $pat->last_validated_at->diffForHumans()]) }}
                                                @if ($pat->last_validated_at->lt(now()->subDays(25)))
                                                    — {{ __('may be expiring; replace it below if deploys fail to authenticate') }}
                                                @endif
                                            </span>
                                        @endif
                                        @if ($pat->api_base_url)
                                            <span class="text-brand-mist">·</span>
                                            <span class="truncate font-mono">{{ $pat->api_base_url }}</span>
                                        @endif
                                    </p>
                                    @if ($editingPatId === (string) $pat->id)
                                        <div class="mt-2 max-w-md space-y-1">
                                            <x-text-input wire:model="editPatToken" type="password" autocomplete="off" class="block w-full text-xs font-mono" placeholder="{{ __('Paste a new token to replace the stored one (optional)') }}" />
                                            <p class="text-xs text-brand-mist">{{ __('Replacing here keeps every site that uses this token working — no re-linking needed.') }}</p>
                                            @error('editPatToken')
                                                <p class="text-xs font-medium text-rose-600">{{ $message }}</p>
                                            @enderror
                                        </div>
                                    @endif
                                </div>
                                <div class="flex shrink-0 flex-wrap items-center justify-end gap-1">
                                    @if ($editingPatId === (string) $pat->id)
                                        <button type="button" wire:click="saveEditPat" class="inline-flex h-6 items-center gap-1 rounded-md border border-brand-ink/15 bg-white px-2 text-xs font-semibold text-brand-ink shadow-sm hover:bg-brand-sand/40">
                                            <x-heroicon-o-check class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                                            {{ __('Save') }}
                                        </button>
                                        <button type="button" wire:click="cancelEditPat" class="text-xs font-medium text-brand-moss hover:text-brand-ink">{{ __('Cancel') }}</button>
                                    @else
                                        {{-- Re-runs the daily token health probe on demand, restamping
                                             last_validated_at / expires_at / validation_error. --}}
                                        <button type="button"
                                            wire:click="validatePat('{{ $pat->id }}')"
                                            wire:loading.attr="disabled"
                                            wire:target="validatePat('{{ $pat->id }}')"
                                            class="inline-flex h-6 items-center justify-center gap-1 rounded-md border border-brand-ink/15 bg-white px-2 text-xs font-semibold text-brand-ink shadow-sm transition-colors hover:bg-brand-sand/50 disabled:cursor-progress disabled:opacity-60">
                                            <x-heroicon-o-shield-check class="h-3.5 w-3.5 shrink-0" wire:loading.remove wire:target="validatePat('{{ $pat->id }}')" aria-hidden="true" />
                                            <span wire:loading wire:target="validatePat('{{ $pat->id }}')" class="inline-flex h-3.5 w-3.5 shrink-0 items-center justify-center"><x-spinner size="sm" /></span>
                                            {{ __('Validate') }}
                                        </button>
                                        <button type="button" wire:click="startEditPat('{{ $pat->id }}')" class="inline-flex h-6 items-center justify-center gap-1 rounded-md border border-brand-ink/15 bg-white px-2 text-xs font-semibold text-brand-ink shadow-sm transition-colors hover:bg-brand-sand/50 disabled:cursor-not-allowed disabled:opacity-50">
                                            <x-heroicon-o-pencil-square class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                                            {{ __('Edit') }}
                                        </button>
                                        <button type="button" wire:click="openConfirmActionModal('unlinkPat', ['{{ $pat->id }}'], @js(__('Remove token')), @js(__('Remove this personal access token? Sites using this token will lose access until re-pointed.')), @js(__('Remove')), true)" class="inline-flex h-6 items-center gap-1 rounded-md border border-brand-ink/15 bg-white px-2.5 text-xs font-semibold text-brand-moss shadow-sm transition hover:border-rose-200 hover:bg-rose-50 hover:text-rose-700">
                                            <x-heroicon-o-trash class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                                            {{ __('Remove') }}
                                        </button>
                                    @endif
                                </div>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </div>
        @empty
            <div class="flex flex-col items-center justify-center px-3 py-10 text-center sm:px-4">
                <span class="mx-auto inline-flex h-9 w-9 items-center justify-center rounded-xl bg-brand-sand/45 text-brand-mist ring-1 ring-brand-ink/10">
                    <x-heroicon-o-code-bracket-square class="h-4 w-4" aria-hidden="true" />
                </span>
                <p class="mt-2.5 text-sm font-semibold text-brand-ink">{{ __('No Git providers available') }}</p>
                <p class="mt-1 max-w-md text-xs leading-relaxed text-brand-moss">
                    {{ __('Ask an administrator to configure GitHub, GitLab, or Bitbucket OAuth, or add a personal access token for any provider.') }}
                </p>
            </div>
        @endforelse
    </x-profile-shell>

    {{-- Inside the component root (NOT a layout <x-slot> — layout slots render
         once at page load and never re-render on Livewire updates, so the modal
         could never appear). The partial @teleports itself to <body>. --}}
    @include('livewire.partials.confirm-action-modal')
</div>
