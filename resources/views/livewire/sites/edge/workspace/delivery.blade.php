@php
    $isHybrid = ($edgeRuntimeMode ?? 'static') === 'hybrid' && is_array($edgeOrigin ?? null);
    $imagesMeta = is_array($edgeMeta['images'] ?? null) ? $edgeMeta['images'] : [];
    $imageSecret = is_string($imagesMeta['signing_secret'] ?? null) ? (string) $imagesMeta['signing_secret'] : '';
    $repoAllowed = is_array($repoImages['allowed_hosts'] ?? null) ? $repoImages['allowed_hosts'] : [];
    $repoOriginUrl = is_string($repoOrigin['url'] ?? null) ? (string) $repoOrigin['url'] : '';
    $repoOriginRoutes = is_array($repoOrigin['routes'] ?? null) ? $repoOrigin['routes'] : [];
    $hasRepoOrigin = $repoOriginUrl !== '';
    $hasRepoImages = $repoAllowed !== [];
@endphp

<div>
    @if (! empty($edgeDeliveryBanner))
        <div class="border-b border-brand-ink/10 px-5 py-3 sm:px-6">
            @include('livewire.sites.partials.edge.delivery-banner')
        </div>
    @endif

    {{-- Backend mode only — hostnames live under Routing → Domains. --}}
    <section class="border-b border-brand-ink/10 px-5 py-4 sm:px-6">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div class="min-w-0">
                <p class="text-2xs font-semibold uppercase tracking-[0.16em] text-brand-mist">{{ __('Backend') }}</p>
                <p class="mt-1 text-sm text-brand-ink">{{ $edgeDeliveryBackendLabel ?? $site->edgeBackendLabel() }}</p>
                @if ($site->usesOrgCloudflareEdge() && $site->edgeProviderCredential)
                    <p class="mt-0.5 text-xs text-brand-moss">{{ __('Account: :name', ['name' => $site->edgeProviderCredential->name]) }}</p>
                @endif
            </div>
            <a
                href="{{ route('sites.show', ['server' => $server, 'site' => $site, 'section' => 'edge-routing', 'tab' => 'domains']) }}"
                wire:navigate
                class="shrink-0 text-xs font-medium text-brand-sage hover:underline"
            >
                {{ __('Domains & hostnames') }}
            </a>
        </div>
    </section>

    @if ($isHybrid)
        <section class="border-b border-brand-ink/10 px-5 py-4 sm:px-6">
            <p class="text-2xs font-semibold uppercase tracking-[0.16em] text-brand-mist">{{ __('Hybrid origin') }}</p>
            <p class="mt-1 text-sm text-brand-moss">{{ __('Static assets stay on Edge; matching paths proxy to this origin.') }}</p>

            @can('update', $site)
                <form wire:submit.prevent="saveEdgeHybridOrigin" class="mt-4 space-y-4">
                    <label class="block">
                        <span class="text-2xs font-semibold uppercase tracking-wide text-brand-mist">{{ __('Origin URL') }}</span>
                        <input type="url" wire:model="buildForm.edge_origin_url" autocomplete="off" spellcheck="false" placeholder="https://origin.example.com" class="mt-1.5 w-full rounded-lg border border-brand-ink/15 bg-white px-3 py-2 font-mono text-sm text-brand-ink shadow-sm focus:border-brand-sage focus:ring-1 focus:ring-brand-sage dark:border-brand-mist/20 dark:bg-zinc-900" />
                        @error('buildForm.edge_origin_url') <p class="mt-1 text-xs text-rose-700">{{ $message }}</p> @enderror
                        @if (! empty($edgeOrigin['managed']))
                            <p class="mt-1 text-xs text-brand-moss">{{ __('Provisioned by dply.') }}</p>
                        @endif
                    </label>
                    <label class="block">
                        <span class="text-2xs font-semibold uppercase tracking-wide text-brand-mist">{{ __('Proxy routes') }}</span>
                        <textarea wire:model="buildForm.edge_origin_routes" rows="3" spellcheck="false" placeholder="/api/*&#10;/_next/data/*" class="mt-1.5 w-full rounded-lg border border-brand-ink/15 bg-white px-3 py-2 font-mono text-xs text-brand-ink shadow-sm focus:border-brand-sage focus:ring-1 focus:ring-brand-sage dark:border-brand-mist/20 dark:bg-zinc-900"></textarea>
                        <p class="mt-1 text-xs text-brand-moss">{{ __('One pattern per line. Path rules that rewrite without an origin stay under Routing → Rewrites.') }}</p>
                        @error('buildForm.edge_origin_routes') <p class="mt-1 text-xs text-rose-700">{{ $message }}</p> @enderror
                    </label>
                    <label class="block">
                        <span class="text-2xs font-semibold uppercase tracking-wide text-brand-mist">{{ __('Healthcheck path') }}</span>
                        <input type="text" wire:model="buildForm.edge_origin_healthcheck_path" autocomplete="off" spellcheck="false" placeholder="/" class="mt-1.5 w-full max-w-xs rounded-lg border border-brand-ink/15 bg-white px-3 py-2 font-mono text-sm text-brand-ink shadow-sm focus:border-brand-sage focus:ring-1 focus:ring-brand-sage dark:border-brand-mist/20 dark:bg-zinc-900" />
                        @error('buildForm.edge_origin_healthcheck_path') <p class="mt-1 text-xs text-rose-700">{{ $message }}</p> @enderror
                    </label>
                    <label class="block">
                        <span class="text-2xs font-semibold uppercase tracking-wide text-brand-mist">{{ __('Failover HTML') }}</span>
                        <textarea wire:model="buildForm.edge_origin_failover_html" rows="3" spellcheck="false" placeholder="{{ __('Optional — blank uses the built-in 503 page.') }}" class="mt-1.5 w-full rounded-lg border border-brand-ink/15 bg-white px-3 py-2 font-mono text-xs text-brand-ink shadow-sm focus:border-brand-sage focus:ring-1 focus:ring-brand-sage dark:border-brand-mist/20 dark:bg-zinc-900"></textarea>
                        @error('buildForm.edge_origin_failover_html') <p class="mt-1 text-xs text-rose-700">{{ $message }}</p> @enderror
                    </div>

                    <div class="sm:col-span-2">
                        <p class="text-xs font-semibold uppercase tracking-wide text-brand-mist">{{ __('Cloudflare Access service token') }}</p>
                        <p class="mt-1 text-xs text-brand-moss">
                            {{ __('Optional. Set this when the origin sits behind Cloudflare Access — typically a Cloudflare Tunnel hostname, which has no public IP but is still reachable by anyone who learns it. The Worker sends the token on every origin request.') }}
                        </p>
                        <div class="mt-2 grid grid-cols-1 gap-3 sm:grid-cols-2">
                            <div>
                                <label for="origin-access-id" class="text-xs font-medium text-brand-ink dark:text-brand-sand">{{ __('Client ID') }}</label>
                                <input id="origin-access-id" type="text" wire:model="buildForm.edge_origin_access_client_id" autocomplete="off" spellcheck="false" placeholder="xxxx.access" class="mt-1.5 w-full rounded-lg border border-brand-ink/15 bg-white px-3 py-2 font-mono text-xs text-brand-ink shadow-sm focus:border-brand-sage focus:ring-1 focus:ring-brand-sage dark:border-brand-mist/20 dark:bg-zinc-900" />
                                @error('buildForm.edge_origin_access_client_id') <p class="mt-1 text-xs text-rose-700">{{ $message }}</p> @enderror
                            </div>
                            <div>
                                <label for="origin-access-secret" class="text-xs font-medium text-brand-ink dark:text-brand-sand">{{ __('Client Secret') }}</label>
                                <input id="origin-access-secret" type="password" wire:model="buildForm.edge_origin_access_client_secret" autocomplete="new-password" spellcheck="false" placeholder="{{ $edgeOriginHasAccessSecret ? __('Stored — leave blank to keep') : __('Paste to set') }}" class="mt-1.5 w-full rounded-lg border border-brand-ink/15 bg-white px-3 py-2 font-mono text-xs text-brand-ink shadow-sm focus:border-brand-sage focus:ring-1 focus:ring-brand-sage dark:border-brand-mist/20 dark:bg-zinc-900" />
                                @error('buildForm.edge_origin_access_client_secret') <p class="mt-1 text-xs text-rose-700">{{ $message }}</p> @enderror
                            </div>
                        </div>
                        <p class="mt-1.5 text-xs text-brand-moss">{{ __('Clearing the Client ID removes both halves.') }}</p>
                    </label>

                    <div class="flex flex-wrap items-center justify-end gap-3">
                        <span wire:loading.inline-flex wire:target="saveEdgeHybridOrigin" class="inline-flex items-center gap-1.5 text-xs text-brand-moss">
                            <x-spinner size="sm" variant="muted" />
                            {{ __('Saving…') }}
                        </span>
                        <button type="submit" wire:loading.attr="disabled" wire:target="saveEdgeHybridOrigin" class="rounded-lg bg-brand-ink px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-brand-ink/90 disabled:cursor-wait disabled:opacity-60">
                            {{ __('Save origin') }}
                        </button>
                    </div>
                </form>
            @else
                <dl class="mt-3 grid grid-cols-1 gap-y-1.5 text-xs sm:grid-cols-[7rem_1fr]">
                    <dt class="text-brand-mist">{{ __('URL') }}</dt>
                    <dd class="font-mono text-brand-ink break-all">{{ $edgeOrigin['url'] ?? '—' }}</dd>
                    @if (! empty($edgeOrigin['routes']))
                        <dt class="text-brand-mist">{{ __('Routes') }}</dt>
                        <dd class="font-mono text-brand-ink">{{ implode(', ', $edgeOrigin['routes']) }}</dd>
                    @endif
                </dl>
            @endcan
        </section>

        <details class="group border-b border-brand-ink/10">
            <summary class="flex cursor-pointer list-none items-center justify-between gap-3 bg-brand-sand/10 px-5 py-3.5 text-sm font-semibold text-brand-ink hover:bg-brand-sand/20 sm:px-6 [&::-webkit-details-marker]:hidden">
                <span>{{ __('Origin advanced') }}</span>
                <x-heroicon-m-chevron-down class="h-4 w-4 text-brand-mist transition group-open:rotate-180" />
            </summary>
            <div class="space-y-5 border-t border-brand-ink/10 px-5 py-4 sm:px-6">
                @can('update', $site)
                    @if (! empty($edgeOrigin['auth_secret']))
                        <div x-data="{ copied: false }">
                            <p class="text-2xs font-semibold uppercase tracking-wide text-brand-mist">{{ __('Origin auth secret') }}</p>
                            <p class="mt-1 text-xs text-brand-moss">{{ __('Sent as X-Dply-Origin-Auth on proxied requests.') }}</p>
                            <div class="mt-2 flex flex-wrap items-center gap-2">
                                <input type="password" readonly value="{{ $edgeOrigin['auth_secret'] }}" class="block min-w-0 flex-1 rounded-lg border border-brand-ink/15 bg-white px-3 py-2 font-mono text-xs text-brand-ink dark:border-brand-mist/20 dark:bg-zinc-900" onclick="this.select()" />
                                <button type="button" class="inline-flex items-center gap-1 rounded-lg border border-brand-ink/10 bg-white px-3 py-2 text-xs font-medium text-brand-moss hover:bg-brand-sand/40" @click="navigator.clipboard.writeText(@js($edgeOrigin['auth_secret'])); copied = true; setTimeout(() => copied = false, 2000)">
                                    <x-heroicon-o-clipboard class="h-4 w-4" />
                                    <span x-show="!copied">{{ __('Copy') }}</span>
                                    <span x-show="copied" x-cloak>{{ __('Copied') }}</span>
                                </button>
                                <button
                                    type="button"
                                    wire:click="openConfirmActionModal('rotateEdgeHybridOriginSecret', [], @js(__('Rotate origin secret')), @js(__('Rotate the origin auth secret? Update your origin to accept the new value.')), @js(__('Rotate')), true)"
                                    class="inline-flex items-center gap-1 rounded-lg border border-rose-200 bg-white px-3 py-2 text-xs font-semibold text-rose-900 hover:bg-rose-50"
                                >
                                    {{ __('Rotate') }}
                                </button>
                            </div>
                        </div>
                    @endif

                    <div>
                        <p class="text-2xs font-semibold uppercase tracking-wide text-brand-mist">{{ __('Purge cache by tag') }}</p>
                        <form wire:submit.prevent="purgeEdgeCacheByTag" class="mt-2 flex flex-wrap items-center gap-2">
                            <input type="text" wire:model="buildForm.edge_cache_purge_tag" autocomplete="off" spellcheck="false" placeholder="article-42" class="min-w-0 flex-1 rounded-lg border border-brand-ink/15 bg-white px-3 py-2 font-mono text-xs text-brand-ink shadow-sm focus:border-brand-sage focus:ring-1 focus:ring-brand-sage dark:border-brand-mist/20 dark:bg-zinc-900" />
                            <button type="submit" wire:loading.attr="disabled" wire:target="purgeEdgeCacheByTag" class="inline-flex items-center gap-1.5 rounded-lg border border-brand-ink/15 bg-white px-3 py-2 text-xs font-semibold text-brand-ink hover:bg-brand-sand/40 disabled:cursor-wait disabled:opacity-60">
                                <span wire:loading.remove wire:target="purgeEdgeCacheByTag">{{ __('Purge') }}</span>
                                <span wire:loading wire:target="purgeEdgeCacheByTag">{{ __('Purging…') }}</span>
                            </button>
                        </form>
                        @error('buildForm.edge_cache_purge_tag') <p class="mt-1 text-xs text-rose-700">{{ $message }}</p> @enderror
                    </div>

                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <p class="text-xs text-brand-moss">{{ __('Probe the merged origin URL.') }}</p>
                        <button type="button" wire:click="testOrigin" wire:loading.attr="disabled" wire:target="testOrigin" class="rounded-lg border border-brand-ink/15 bg-white px-3 py-1.5 text-xs font-semibold text-brand-ink hover:bg-brand-sand/40 disabled:cursor-wait disabled:opacity-60">
                            <span wire:loading.remove wire:target="testOrigin">{{ __('Test origin') }}</span>
                            <span wire:loading wire:target="testOrigin">{{ __('Probing…') }}</span>
                        </button>
                    </div>
                    @if ($originProbe !== null)
                        @php $tone = ($originProbe['ok'] ?? false) ? 'border-emerald-200 bg-emerald-50 text-emerald-900' : 'border-rose-200 bg-rose-50 text-rose-900'; @endphp
                        <div class="rounded-lg border px-3 py-2 text-xs {{ $tone }}">
                            @if (isset($originProbe['status']))
                                <p><span class="font-semibold">{{ __('HTTP :status', ['status' => $originProbe['status']]) }}</span> · {{ __(':latency ms', ['latency' => $originProbe['latency_ms'] ?? 0]) }}</p>
                            @endif
                            @if (! empty($originProbe['target'])) <p class="mt-0.5 break-all font-mono text-2xs opacity-80">{{ $originProbe['target'] }}</p> @endif
                            @if (! empty($originProbe['error'])) <p class="mt-1 text-xs">{{ $originProbe['error'] }}</p> @endif
                        </div>
                    @endif
                @endcan

                <div>
                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <p class="text-2xs font-semibold uppercase tracking-wide text-brand-mist">{{ __('From :file', ['file' => $sourcePath]) }}</p>
                        <a href="{{ route('sites.edge.dply-yaml', ['server' => $site->server_id, 'site' => $site->id]) }}" class="inline-flex items-center gap-1 text-xs font-medium text-brand-sage hover:underline">
                            <x-heroicon-o-arrow-down-tray class="h-3.5 w-3.5" aria-hidden="true" />
                            {{ __('Generate :file', ['file' => $sourcePath]) }}
                        </a>
                    </div>
                    @if ($hasRepoOrigin)
                        <dl class="mt-2 grid grid-cols-1 gap-y-1.5 text-xs sm:grid-cols-[7rem_1fr]">
                            <dt class="text-brand-mist">{{ __('URL') }}</dt>
                            <dd class="font-mono text-brand-ink break-all">{{ $repoOriginUrl }}</dd>
                            @if ($repoOriginRoutes !== [])
                                <dt class="text-brand-mist">{{ __('Routes') }}</dt>
                                <dd class="font-mono text-brand-ink">{{ implode(', ', $repoOriginRoutes) }}</dd>
                            @endif
                        </dl>
                    @else
                        <p class="mt-2 text-sm text-brand-moss">{{ __('None declared in :file yet.', ['file' => $sourcePath]) }}</p>
                    @endif

                    <x-edge-yaml-example :file="$sourcePath">
origin:
  url: "https://my-app.example.com"
  routes:
    - "/api/*"
    - "/_next/data/*"
                    </x-edge-yaml-example>
                </div>
            </div>
        </details>
    @elseif (auth()->user()?->can('update', $site))
        <section class="border-b border-brand-ink/10 px-5 py-4 sm:px-6">
            <p class="text-2xs font-semibold uppercase tracking-[0.16em] text-brand-mist">{{ __('Hybrid origin') }}</p>
            <p class="mt-1 text-sm text-brand-moss">{{ __('Point dynamic routes at an existing origin. Static assets stay on Edge.') }}</p>
            <form wire:submit.prevent="requestConvertToHybrid" class="mt-4 space-y-3">
                <label class="block">
                    <span class="text-2xs font-semibold uppercase tracking-wide text-brand-mist">{{ __('Origin URL') }}</span>
                    <input type="url" wire:model="buildForm.edge_convert_origin_url" autocomplete="off" spellcheck="false" placeholder="https://origin.example.com" class="mt-1.5 w-full rounded-lg border border-brand-ink/15 bg-white px-3 py-2 font-mono text-sm text-brand-ink shadow-sm focus:border-brand-sage focus:ring-1 focus:ring-brand-sage dark:border-brand-mist/20 dark:bg-zinc-900" />
                    <p class="mt-1 text-xs text-brand-moss">{{ __('Defaults to /api/* and /_next/data/* — edit after converting.') }}</p>
                    @error('buildForm.edge_convert_origin_url') <p class="mt-1 text-xs text-rose-700">{{ $message }}</p> @enderror
                </label>
                <button
                    type="submit"
                    wire:loading.attr="disabled"
                    wire:target="requestConvertToHybrid,convertEdgeStaticToHybrid,confirmActionModal"
                    class="rounded-lg bg-brand-ink px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-brand-ink/90 disabled:cursor-wait disabled:opacity-60"
                >
                    <span wire:loading.remove wire:target="requestConvertToHybrid,convertEdgeStaticToHybrid,confirmActionModal">{{ __('Convert to hybrid') }}</span>
                    <span wire:loading wire:target="requestConvertToHybrid,convertEdgeStaticToHybrid,confirmActionModal">{{ __('Converting…') }}</span>
                </button>
            </form>
        </section>
    @endif

    @can('update', $site)
        <section class="border-b border-brand-ink/10 px-5 py-4 sm:px-6">
            <p class="text-2xs font-semibold uppercase tracking-[0.16em] text-brand-mist">{{ __('Image optimization') }}</p>
            <p class="mt-1 text-sm text-brand-moss">{{ __('Resize and reformat images at the edge via /_dply/image.') }}</p>

            <form wire:submit.prevent="saveEdgeImageOptimization" class="mt-4 space-y-4">
                <label class="flex items-start gap-3 text-sm text-brand-ink">
                    <input type="checkbox" wire:model="buildForm.edge_image_optimization_enabled" class="mt-0.5 rounded border-brand-ink/20 text-brand-sage shadow-sm focus:ring-brand-sage/40" />
                    <span>
                        <span class="font-medium">{{ __('Enable') }}</span>
                        @if ($effectiveImages['enabled'])
                            <span class="ml-1.5 rounded-full bg-emerald-100 px-2 py-0.5 font-mono text-2xs font-semibold uppercase tracking-wide text-emerald-900">{{ __('On') }}</span>
                        @endif
                    </span>
                </label>
                <label class="block">
                    <span class="text-2xs font-semibold uppercase tracking-wide text-brand-mist">{{ __('Allowed source hostnames') }}</span>
                    <textarea wire:model="buildForm.edge_image_allowed_hosts" rows="3" spellcheck="false" placeholder="images.example.com" class="mt-1.5 w-full rounded-lg border border-brand-ink/15 bg-white px-3 py-2 font-mono text-xs text-brand-ink shadow-sm focus:border-brand-sage focus:ring-1 focus:ring-brand-sage dark:border-brand-mist/20 dark:bg-zinc-900"></textarea>
                    <p class="mt-1 text-xs text-brand-moss">{{ __('One per line. Merges with :file.', ['file' => $sourcePath]) }}</p>
                    @error('buildForm.edge_image_allowed_hosts') <p class="mt-1 text-xs text-rose-700">{{ $message }}</p> @enderror
                </label>
                <div class="flex flex-wrap items-center justify-end gap-3">
                    <span wire:loading.inline-flex wire:target="saveEdgeImageOptimization" class="inline-flex items-center gap-1.5 text-xs text-brand-moss">
                        <x-spinner size="sm" variant="muted" />
                        {{ __('Saving…') }}
                    </span>
                    <button type="submit" wire:loading.attr="disabled" wire:target="saveEdgeImageOptimization" class="rounded-lg bg-brand-ink px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-brand-ink/90 disabled:cursor-wait disabled:opacity-60">
                        {{ __('Save images') }}
                    </button>
                </div>
            </form>
        </section>

        <details class="group" @if ($hasRepoImages) open @endif>
            <summary class="flex cursor-pointer list-none items-center justify-between gap-3 bg-brand-sand/10 px-5 py-3.5 text-sm font-semibold text-brand-ink hover:bg-brand-sand/20 sm:px-6 [&::-webkit-details-marker]:hidden">
                <span class="inline-flex items-center gap-2">
                    {{ __('Images advanced') }}
                    @if ($hasRepoImages)
                        <span class="rounded-full bg-brand-sand/60 px-2 py-0.5 font-mono text-2xs font-semibold uppercase tracking-wide text-brand-moss">{{ __('Repo') }}</span>
                    @endif
                </span>
                <x-heroicon-m-chevron-down class="h-4 w-4 text-brand-mist transition group-open:rotate-180" />
            </summary>
            <div class="space-y-5 border-t border-brand-ink/10 px-5 py-4 sm:px-6">
                @if ($imageSecret !== '')
                    <div x-data="{ copiedSig: false }">
                        <p class="text-2xs font-semibold uppercase tracking-wide text-brand-mist">{{ __('Signing secret') }}</p>
                        <p class="mt-1 text-xs text-brand-moss">{{ __('HMAC-signs /_dply/image URLs.') }}</p>
                        <div class="mt-2 flex flex-wrap items-center gap-2">
                            <input type="password" readonly value="{{ $imageSecret }}" class="block min-w-0 flex-1 rounded-lg border border-brand-ink/15 bg-white px-3 py-2 font-mono text-xs text-brand-ink dark:border-brand-mist/20 dark:bg-zinc-900" onclick="this.select()" />
                            <button type="button" class="inline-flex items-center gap-1 rounded-lg border border-brand-ink/10 bg-white px-3 py-2 text-xs font-medium text-brand-moss hover:bg-brand-sand/40" @click="navigator.clipboard.writeText(@js($imageSecret)); copiedSig = true; setTimeout(() => copiedSig = false, 2000)">
                                <x-heroicon-o-clipboard class="h-4 w-4" />
                                <span x-show="!copiedSig">{{ __('Copy') }}</span>
                                <span x-show="copiedSig" x-cloak>{{ __('Copied') }}</span>
                            </button>
                            <button
                                type="button"
                                wire:click="openConfirmActionModal('rotateEdgeImageSigningSecret', [], @js(__('Rotate signing secret')), @js(__('Rotate the signing secret? Existing pre-signed URLs will stop working.')), @js(__('Rotate')), true)"
                                class="inline-flex items-center gap-1 rounded-lg border border-rose-200 bg-white px-3 py-2 text-xs font-semibold text-rose-900 hover:bg-rose-50"
                            >
                                {{ __('Rotate') }}
                            </button>
                        </div>
                    </div>
                @endif

                <div class="flex flex-wrap items-center justify-between gap-2">
                    <p class="text-xs text-brand-moss">{{ __('Hit /_dply/image with a signed sample URL.') }}</p>
                    <button type="button" wire:click="testImage" wire:loading.attr="disabled" wire:target="testImage" class="rounded-lg border border-brand-ink/15 bg-white px-3 py-1.5 text-xs font-semibold text-brand-ink hover:bg-brand-sand/40 disabled:cursor-wait disabled:opacity-60">
                        <span wire:loading.remove wire:target="testImage">{{ __('Test image') }}</span>
                        <span wire:loading wire:target="testImage">{{ __('Probing…') }}</span>
                    </button>
                </div>
                @if ($imageProbe !== null)
                    @php $tone = ($imageProbe['ok'] ?? false) ? 'border-emerald-200 bg-emerald-50 text-emerald-900' : 'border-rose-200 bg-rose-50 text-rose-900'; @endphp
                    <div class="rounded-lg border px-3 py-2 text-xs {{ $tone }}">
                        @if (isset($imageProbe['status'])) <p><span class="font-semibold">{{ __('HTTP :status', ['status' => $imageProbe['status']]) }}</span> · {{ __(':latency ms', ['latency' => $imageProbe['latency_ms'] ?? 0]) }}</p> @endif
                        @if (! empty($imageProbe['target'])) <p class="mt-0.5 break-all font-mono text-2xs opacity-80">{{ $imageProbe['target'] }}</p> @endif
                        @if (! empty($imageProbe['error'])) <p class="mt-1 text-xs">{{ $imageProbe['error'] }}</p> @endif
                        @if (! empty($imageProbe['hint'])) <p class="mt-1 text-xs opacity-80">{{ $imageProbe['hint'] }}</p> @endif
                    </div>
                @endif

                <div>
                    <p class="text-2xs font-semibold uppercase tracking-wide text-brand-mist">{{ __('From :file', ['file' => $sourcePath]) }}</p>
                    @if ($hasRepoImages)
                        <p class="mt-2 font-mono text-xs text-brand-ink break-all">{{ implode(', ', $repoAllowed) }}</p>
                    @else
                        <p class="mt-2 text-sm text-brand-moss">{{ __('None declared in :file yet.', ['file' => $sourcePath]) }}</p>
                    @endif

                    <x-edge-yaml-example class="mt-3" :file="$sourcePath" :hint="__('Signing secret stays dashboard-only.')">
images:
  allowed_hosts:
    - "images.example.com"
    - "cdn.example.com"
                    </x-edge-yaml-example>
                </div>
            </div>
        </details>
    @endcan

    @include('livewire.partials.confirm-action-modal')
</div>
