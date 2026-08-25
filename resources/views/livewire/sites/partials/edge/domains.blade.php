<section class="dply-card overflow-hidden">
    <div class="flex items-start gap-3 border-b border-brand-ink/10 bg-brand-sand/20 px-6 py-5 sm:px-7">
        <x-icon-badge>
            <x-heroicon-o-globe-alt class="h-5 w-5" aria-hidden="true" />
        </x-icon-badge>
        <div class="min-w-0">
            <p class="text-xs font-semibold uppercase tracking-[0.16em] text-brand-sage">{{ __('Hostname') }}</p>
            <h3 class="mt-0.5 text-base font-semibold text-brand-ink">{{ __('Default hostname') }}</h3>
            <p class="mt-1 max-w-2xl text-sm leading-relaxed text-brand-moss">{{ __('Your site is always available on its dply Edge URL.') }}</p>
        </div>
    </div>
    <div class="px-6 py-4 sm:px-8">
        @if ($edgeLiveUrl)
            <p class="font-mono text-sm text-brand-ink break-all">{{ $edgeLiveUrl }}</p>
        @else
            <p class="text-sm text-brand-moss">{{ __('Pending first deploy') }}</p>
        @endif
    </div>
</section>

<section class="dply-card overflow-hidden">
    <div class="flex items-start gap-3 border-b border-brand-ink/10 bg-brand-sand/20 px-6 py-5 sm:px-7">
        <x-icon-badge>
            <x-heroicon-o-link class="h-5 w-5" aria-hidden="true" />
        </x-icon-badge>
        <div class="min-w-0">
            <p class="text-xs font-semibold uppercase tracking-[0.16em] text-brand-sage">{{ __('Domains') }}</p>
            <h3 class="mt-0.5 text-base font-semibold text-brand-ink">{{ __('Custom domains') }}</h3>
            <p class="mt-1 max-w-2xl text-sm leading-relaxed text-brand-moss">{{ __('Point a CNAME at your Edge hostname, then verify DNS here. Managed delivery issues TLS via Custom Hostnames on the dply Edge zone; BYO Cloudflare uses your zone’s orange-cloud proxy.') }}</p>
        </div>
    </div>
    <div class="space-y-4 px-6 py-5 sm:px-8">
        @if ($edgeAttachedDomains !== [])
            <ul class="divide-y divide-brand-ink/8 rounded-xl border border-brand-ink/10">
                @foreach ($edgeAttachedDomains as $hostname => $info)
                    @php
                        $dnsStatus = is_array($info) ? (string) ($info['dns_status'] ?? 'pending') : 'pending';
                        $sslStatus = is_array($info) ? (string) ($info['ssl_status'] ?? '') : '';
                        $cnameTarget = is_array($info) ? (string) ($info['cname_target'] ?? $edgeDeliveryHostname ?? $site->edgeHostname()) : ($edgeDeliveryHostname ?? $site->edgeHostname());
                        $ownership = is_array($info) && is_array($info['ownership_verification'] ?? null) ? $info['ownership_verification'] : null;
                        $statusBadge = match ($dnsStatus) {
                            'ready' => 'bg-emerald-100 text-emerald-800 dark:bg-emerald-950/40 dark:text-emerald-300',
                            'failed' => 'bg-rose-100 text-rose-800 dark:bg-rose-950/40 dark:text-rose-300',
                            default => 'bg-amber-100 text-amber-900 dark:bg-amber-950/40 dark:text-amber-200',
                        };
                        $statusLabel = match ($dnsStatus) {
                            'ready' => __('Ready'),
                            'failed' => __('Failed'),
                            default => __('Pending DNS'),
                        };
                        $sslBadge = match ($sslStatus) {
                            'active' => 'bg-emerald-100 text-emerald-800 dark:bg-emerald-950/40 dark:text-emerald-300',
                            'failed' => 'bg-rose-100 text-rose-800 dark:bg-rose-950/40 dark:text-rose-300',
                            'pending' => 'bg-sky-100 text-sky-900 dark:bg-sky-950/40 dark:text-sky-200',
                            default => null,
                        };
                        $sslLabel = match ($sslStatus) {
                            'active' => __('TLS active'),
                            'failed' => __('TLS failed'),
                            'pending' => __('Issuing certificate'),
                            default => null,
                        };
                    @endphp
                    <li class="px-4 py-4" wire:key="edge-domain-{{ $hostname }}">
                        <div class="flex flex-wrap items-start justify-between gap-3">
                            <div class="min-w-0">
                                <div class="flex flex-wrap items-center gap-2">
                                    <p class="font-mono text-sm text-brand-ink">{{ $hostname }}</p>
                                    <span class="rounded-full px-2 py-0.5 text-2xs font-semibold uppercase tracking-wide {{ $statusBadge }}">{{ $statusLabel }}</span>
                                    @if ($sslLabel && $sslBadge)
                                        <span class="rounded-full px-2 py-0.5 text-2xs font-semibold uppercase tracking-wide {{ $sslBadge }}">{{ $sslLabel }}</span>
                                    @endif
                                </div>
                                @if ($cnameTarget !== '')
                                    <div class="mt-2" x-data="{ copied: false }">
                                        <p class="text-2xs font-semibold uppercase tracking-[0.16em] text-brand-mist">{{ __('CNAME target') }}</p>
                                        <div class="mt-1 flex flex-wrap items-center gap-2">
                                            <code class="rounded-lg bg-brand-sand/30 px-2 py-1 font-mono text-xs text-brand-ink">{{ $cnameTarget }}</code>
                                            <button
                                                type="button"
                                                class="inline-flex items-center gap-1 rounded-lg border border-brand-ink/10 bg-white px-2 py-1 text-xs font-medium text-brand-moss hover:bg-brand-sand/40"
                                                @click="navigator.clipboard.writeText(@js($cnameTarget)); copied = true; setTimeout(() => copied = false, 2000)"
                                            >
                                                <x-heroicon-o-clipboard class="h-4 w-4" />
                                                <span x-show="!copied">{{ __('Copy') }}</span>
                                                <span x-show="copied" x-cloak>{{ __('Copied') }}</span>
                                            </button>
                                        </div>
                                    </div>
                                @endif
                                @if (is_array($ownership) && ($ownership['name'] ?? '') !== '' && ($ownership['value'] ?? '') !== '' && $sslStatus !== 'active')
                                    <div class="mt-2" x-data="{ copied: false }">
                                        <p class="text-2xs font-semibold uppercase tracking-[0.16em] text-brand-mist">{{ __('Ownership :type record', ['type' => strtoupper((string) ($ownership['type'] ?? 'TXT'))]) }}</p>
                                        <p class="mt-1 font-mono text-xs text-brand-moss break-all">{{ $ownership['name'] }}</p>
                                        <div class="mt-1 flex flex-wrap items-center gap-2">
                                            <code class="rounded-lg bg-brand-sand/30 px-2 py-1 font-mono text-xs text-brand-ink break-all">{{ $ownership['value'] }}</code>
                                            <button
                                                type="button"
                                                class="inline-flex items-center gap-1 rounded-lg border border-brand-ink/10 bg-white px-2 py-1 text-xs font-medium text-brand-moss hover:bg-brand-sand/40"
                                                @click="navigator.clipboard.writeText(@js($ownership['value'])); copied = true; setTimeout(() => copied = false, 2000)"
                                            >
                                                <x-heroicon-o-clipboard class="h-4 w-4" />
                                                <span x-show="!copied">{{ __('Copy') }}</span>
                                                <span x-show="copied" x-cloak>{{ __('Copied') }}</span>
                                            </button>
                                        </div>
                                    </div>
                                @endif
                                @if (is_array($info) && ! empty($info['error']))
                                    <p class="mt-2 text-xs text-rose-700 dark:text-rose-300">{{ $info['error'] }}</p>
                                @endif
                                @if (is_array($info) && ! empty($info['ssl_error']))
                                    <p class="mt-2 text-xs text-rose-700 dark:text-rose-300">{{ $info['ssl_error'] }}</p>
                                @endif
                            </div>
                            <div class="flex flex-wrap items-center gap-2">
                                @can('update', $site)
                                    @if ($dnsStatus !== 'ready')
                                        <button
                                            type="button"
                                            wire:click="verifyEdgeDomain('{{ $hostname }}')"
                                            wire:loading.attr="disabled"
                                            wire:target="verifyEdgeDomain('{{ $hostname }}')"
                                            class="inline-flex items-center gap-1.5 rounded-lg border border-brand-ink/15 bg-white px-3 py-1.5 text-xs font-semibold text-brand-ink shadow-sm hover:bg-brand-sand/40"
                                        >
                                            <x-heroicon-o-check-badge class="h-4 w-4" />
                                            <span wire:loading.remove wire:target="verifyEdgeDomain('{{ $hostname }}')">{{ __('Verify DNS') }}</span>
                                            <span wire:loading wire:target="verifyEdgeDomain('{{ $hostname }}')">{{ __('Checking…') }}</span>
                                        </button>
                                    @endif
                                    <button type="button" wire:click="detachEdgeDomain('{{ $hostname }}')" class="text-xs font-medium text-rose-700 hover:text-rose-900 dark:text-rose-400">
                                        {{ __('Remove') }}
                                    </button>
                                @endcan
                            </div>
                        </div>
                    </li>
                @endforeach
            </ul>
        @else
            <x-empty-state
                borderless
                compact
                icon="heroicon-o-globe-alt"
                :title="__('No custom domains yet')"
            />
        @endif

        @can('update', $site)
            <div class="flex flex-col gap-3 sm:flex-row sm:items-end">
                <div class="min-w-0 flex-1">
                    <x-input-label for="edge_domain_input" :value="__('Hostname')" />
                    <x-text-input id="edge_domain_input" type="text" wire:model="edge_domain_input" class="mt-1.5 block w-full font-mono" placeholder="www.example.com" />
                </div>
                <x-primary-button type="button" wire:click="attachEdgeDomain" wire:loading.attr="disabled" wire:target="attachEdgeDomain" class="shrink-0">
                    <span wire:loading.remove wire:target="attachEdgeDomain">{{ __('Attach domain') }}</span>
                    <span wire:loading wire:target="attachEdgeDomain">{{ __('Attaching…') }}</span>
                </x-primary-button>
            </div>
        @endcan
    </div>
</section>
