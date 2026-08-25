<div>
    @if (! empty($useOrgShell) && $organization)
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
            <x-organization-shell
                :organization="$organization"
                section="providers"
                :title="__('Credentials')"
                :description="__('Every secret this organization hands to a third party: API tokens for the clouds, registrars and CDNs you use, and the buckets and remotes your backups ship to. All encrypted at rest, and validated against the provider when we can.')"
                icon="heroicon-o-key"
                :breadcrumb="[
                    ['label' => __('Dashboard'), 'href' => route('dashboard'), 'icon' => 'home'],
                    ['label' => $organization->name, 'href' => route('organizations.show', $organization), 'icon' => 'building-office-2'],
                    ['label' => __('Credentials'), 'icon' => 'key'],
                ]"
            >
                <x-slot:actions>
                    @if ($credentials->isNotEmpty())
                        <button
                            type="button"
                            x-on:click="$dispatch('open-add-provider-credential-modal')"
                            class="inline-flex h-6 items-center gap-1 rounded-lg bg-brand-ink px-2.5 text-xs font-semibold text-brand-cream shadow-sm transition hover:bg-brand-forest"
                        >
                            <x-heroicon-o-plus class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                            {{ __('Connect a provider') }}
                        </button>
                    @endif
                </x-slot:actions>

                @php
                    $connectedProviderCount = $credentials->pluck('provider')->unique()->count();
                    $verifiedCount = $credentials->filter(fn ($c) => $c->last_validated_at && blank($c->validation_error))->count();
                    $rejectedCount = $credentials->filter(fn ($c) => filled($c->validation_error))->count();
                @endphp
                <x-slot:stats>
                    <dl class="grid grid-cols-4 gap-px bg-brand-ink/5" aria-label="{{ __('Credentials at a glance') }}">
                        <div class="bg-white px-3 py-2">
                            <dt class="text-2xs font-semibold uppercase tracking-wide text-brand-mist">{{ __('Providers') }}</dt>
                            <dd class="mt-0.5 font-mono text-base font-semibold tabular-nums text-brand-ink">{{ $connectedProviderCount }}</dd>
                        </div>
                        <div class="bg-white px-3 py-2">
                            <dt class="text-2xs font-semibold uppercase tracking-wide text-brand-mist">{{ __('Credentials') }}</dt>
                            <dd class="mt-0.5 flex items-baseline gap-1.5">
                                <span class="font-mono text-base font-semibold tabular-nums text-brand-ink">{{ $credentials->count() }}</span>
                                @if ($rejectedCount > 0)
                                    <span class="text-2xs font-semibold text-rose-700">{{ __(':n can’t connect', ['n' => $rejectedCount]) }}</span>
                                @elseif ($verifiedCount > 0)
                                    <span class="text-2xs font-semibold text-brand-forest">{{ __(':n connected', ['n' => $verifiedCount]) }}</span>
                                @endif
                            </dd>
                        </div>
                        <div class="bg-white px-3 py-2">
                            <dt class="text-2xs font-semibold uppercase tracking-wide text-brand-mist">{{ __('At rest') }}</dt>
                            <dd class="mt-0.5 flex items-center gap-1 text-sm font-semibold text-brand-forest">
                                <x-heroicon-m-lock-closed class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                                {{ __('Encrypted') }}
                            </dd>
                        </div>
                    </dl>
                </x-slot:stats>

                {{-- What a credential is and what dply does with it. Collapsible
                     (remembered per org) — reassurance on first visit, out of the
                     way afterwards. --}}
                <div
                    class="border-b border-brand-ink/10 bg-brand-cream/40"
                    x-data="{
                        _k: 'dply.credentials.howItWorksCollapsed:{{ $organization->id }}',
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
                        <span class="text-xs font-semibold uppercase tracking-[0.14em] text-brand-sage">{{ __('How credentials are handled') }}</span>
                        <span class="ms-auto text-2xs text-brand-mist" x-show="collapsed">{{ __('Show') }}</span>
                    </button>

                    <div x-show="! collapsed" x-collapse>
                        <dl class="grid gap-px bg-brand-ink/5 sm:grid-cols-3">
                            <div class="bg-brand-cream/40 px-5 py-3 sm:px-4">
                                <dt class="flex items-center gap-1.5 text-xs font-semibold text-brand-ink">
                                    <x-heroicon-o-lock-closed class="h-4 w-4 shrink-0 text-brand-sage" aria-hidden="true" />
                                    {{ __('Encrypted before disk') }}
                                </dt>
                                <dd class="mt-1 text-xs leading-relaxed text-brand-moss">
                                    {{ __('Tokens are encrypted at rest and never shown again after you save them — rotate at the provider to replace one.') }}
                                </dd>
                            </div>
                            <div class="bg-brand-cream/40 px-5 py-3 sm:px-4">
                                <dt class="flex items-center gap-1.5 text-xs font-semibold text-brand-ink">
                                    <x-heroicon-o-check-badge class="h-4 w-4 shrink-0 text-brand-sage" aria-hidden="true" />
                                    {{ __('Verified where possible') }}
                                </dt>
                                <dd class="mt-1 text-xs leading-relaxed text-brand-moss">
                                    {{ __('We call the provider when a token is added and keep checking it, so a revoked key shows up here instead of halfway through a provision.') }}
                                </dd>
                            </div>
                            <div class="bg-brand-cream/40 px-5 py-3 sm:px-4">
                                <dt class="flex items-center gap-1.5 text-xs font-semibold text-brand-ink">
                                    <x-heroicon-o-cloud-arrow-up class="h-4 w-4 shrink-0 text-brand-sage" aria-hidden="true" />
                                    {{ __('Storage is separate') }}
                                </dt>
                                <dd class="mt-1 text-xs leading-relaxed text-brand-moss">
                                    {{ __('A backup destination is a named bucket, so one provider can hold several. Without one, dumps stay on the server that made them.') }}
                                </dd>
                            </div>
                        </dl>
                    </div>
                </div>

                @include('livewire.credentials.partials.index-content')

                <x-slot:footer>
                    <div class="flex flex-wrap items-center gap-x-4 gap-y-2 text-xs text-brand-moss">
                        <span class="inline-flex items-center gap-1.5">
                            <x-heroicon-o-information-circle class="h-4 w-4 shrink-0 text-brand-sage" aria-hidden="true" />
                            {{ __('Only owners and admins can add or remove credentials.') }}
                        </span>
                    </div>
                </x-slot:footer>
            </x-organization-shell>
        </div>
    @else
        @include('livewire.credentials.partials.index-content')
    @endif

    {{-- One shared "Add a credential" modal for the entire page. Each
         provider card dispatches `open-add-provider-credential-modal`
         with its provider id; the modal listens window-wide. --}}
    <livewire:credentials.add-provider-credential-modal />

    {{-- Storage destinations are a different shape from provider tokens (named,
         many per provider, no OAuth), so they get their own modal rather than
         being forced through the provider-credential one. It is the shared
         two-mode dialog: "connect existing" records keys for a bucket you
         already have, "create new bucket" provisions one on the provider using
         a connected cloud token. Same dialog the server workspace opens. --}}

    <x-slot name="modals">
        @include('livewire.partials.confirm-action-modal')
    </x-slot>
</div>
