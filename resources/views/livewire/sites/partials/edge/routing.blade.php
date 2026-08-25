@php
    use App\Models\EdgeDeployment;

    // Pull the latest deployment whose repo_config snapshot includes
    // any routing rules. Eager-loaded when available so we skip the
    // query; falls through to a small direct fetch when EdgeSettings
    // didn't load the relation for this section.
    $deploymentsWithConfig = $site->relationLoaded('edgeDeployments') && $site->edgeDeployments !== null
        ? $site->edgeDeployments->filter(fn (EdgeDeployment $d): bool => is_array($d->repo_config) && $d->repo_config !== [])
        : EdgeDeployment::query()
            ->where('site_id', $site->id)
            ->whereNotNull('repo_config')
            ->orderByDesc('created_at')
            ->limit(10)
            ->get()
            ->filter(fn (EdgeDeployment $d): bool => is_array($d->repo_config) && $d->repo_config !== []);

    $latestRouting = $deploymentsWithConfig
        ->first(fn (EdgeDeployment $d): bool => $d->status === EdgeDeployment::STATUS_LIVE)
        ?->repo_config
        ?? $deploymentsWithConfig->first()?->repo_config;

    $redirects = is_array($latestRouting['redirects'] ?? null) ? $latestRouting['redirects'] : [];
    $rewrites = is_array($latestRouting['rewrites'] ?? null) ? $latestRouting['rewrites'] : [];
    $headers = is_array($latestRouting['headers'] ?? null) ? $latestRouting['headers'] : [];
    $sourcePath = is_string($latestRouting['source_path'] ?? null) ? $latestRouting['source_path'] : 'dply.yaml';
@endphp

<section class="dply-card overflow-hidden">
    <div class="flex items-start gap-3 border-b border-brand-ink/10 bg-brand-sand/20 px-6 py-5 sm:px-7">
        <x-icon-badge>
            <x-heroicon-o-arrows-right-left class="h-5 w-5" aria-hidden="true" />
        </x-icon-badge>
        <div class="min-w-0">
            <p class="text-xs font-semibold uppercase tracking-[0.16em] text-brand-sage">{{ __('Routing') }}</p>
            <h3 class="mt-0.5 text-base font-semibold text-brand-ink">{{ __('Routing rules') }}</h3>
            <p class="mt-1 max-w-2xl text-sm leading-relaxed text-brand-moss">
                {{ __('Redirects, rewrites, and header rules from the latest deploy. Managed via :file in your repository — edit there, then redeploy.', ['file' => $sourcePath]) }}
            </p>
        </div>
        <div class="flex shrink-0 flex-wrap items-center gap-2">
            <span class="inline-flex items-center gap-1 rounded-full bg-brand-sand/60 px-2 py-0.5 font-mono text-2xs font-semibold uppercase tracking-wide text-brand-moss">
                {{ __('Repo-managed') }}
            </span>
            <a
                href="{{ route('sites.edge.dply-yaml', ['server' => $site->server_id, 'site' => $site->id]) }}"
                class="inline-flex items-center gap-1 rounded-lg border border-brand-ink/15 bg-white px-2.5 py-1 font-mono text-2xs font-semibold uppercase tracking-wide text-brand-ink hover:bg-brand-sand/40"
                title="{{ __('Download a dply.yaml that mirrors the current routing + crons') }}"
            >
                <x-heroicon-o-arrow-down-tray class="h-3 w-3" aria-hidden="true" />
                {{ __('Generate dply.yaml') }}
            </a>
        </div>
    </div>

    @if ($latestRouting === null)
        <div class="px-6 py-8 text-sm text-brand-moss sm:px-8">
            <p>{{ __('No deploy has shipped a :file yet. Drop one at the repo root with redirects / rewrites / headers blocks, then redeploy.', ['file' => 'dply.yaml']) }}</p>
            @include('livewire.sites.partials.edge.dply-yaml-starter-examples')
        </div>
    @else
        <div class="grid grid-cols-1 gap-y-6 px-6 py-5 sm:px-8">
            {{-- Redirects --}}
            <div>
                <h4 class="inline-flex items-center gap-2 text-sm font-semibold text-brand-ink">
                    <span>{{ __('Redirects') }}</span>
                    <span class="rounded-full bg-brand-sand/60 px-2 py-0.5 font-mono text-2xs text-brand-moss">{{ count($redirects) }}</span>
                </h4>
                @if ($redirects === [])
                    <x-empty-state
                        borderless
                        compact
                        icon="heroicon-o-arrows-right-left"
                        :title="__('No redirects declared')"
                    />
                @else
                    <div class="mt-2 overflow-x-auto rounded-lg border border-brand-ink/10">
                        <table class="min-w-full divide-y divide-brand-ink/8 text-xs">
                            <thead class="bg-brand-sand/30 text-left text-2xs font-semibold uppercase tracking-wide text-brand-mist">
                                <tr>
                                    <th class="px-3 py-2">{{ __('From') }}</th>
                                    <th class="px-3 py-2">{{ __('To') }}</th>
                                    <th class="px-3 py-2 text-right">{{ __('Status') }}</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-brand-ink/8 text-brand-ink">
                                @foreach ($redirects as $rule)
                                    <tr>
                                        <td class="px-3 py-2 font-mono break-all">{{ $rule['from'] ?? '—' }}</td>
                                        <td class="px-3 py-2 font-mono break-all">{{ $rule['to'] ?? '—' }}</td>
                                        <td class="px-3 py-2 text-right font-mono">{{ $rule['status'] ?? 301 }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </div>

            {{-- Rewrites --}}
            <div>
                <h4 class="inline-flex items-center gap-2 text-sm font-semibold text-brand-ink">
                    <span>{{ __('Rewrites') }}</span>
                    <span class="rounded-full bg-brand-sand/60 px-2 py-0.5 font-mono text-2xs text-brand-moss">{{ count($rewrites) }}</span>
                </h4>
                @if ($rewrites === [])
                    <x-empty-state
                        borderless
                        compact
                        icon="heroicon-o-arrows-right-left"
                        :title="__('No rewrites declared')"
                    />
                @else
                    <div class="mt-2 overflow-x-auto rounded-lg border border-brand-ink/10">
                        <table class="min-w-full divide-y divide-brand-ink/8 text-xs">
                            <thead class="bg-brand-sand/30 text-left text-2xs font-semibold uppercase tracking-wide text-brand-mist">
                                <tr>
                                    <th class="px-3 py-2">{{ __('From') }}</th>
                                    <th class="px-3 py-2">{{ __('To') }}</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-brand-ink/8 text-brand-ink">
                                @foreach ($rewrites as $rule)
                                    <tr>
                                        <td class="px-3 py-2 font-mono break-all">{{ $rule['from'] ?? '—' }}</td>
                                        <td class="px-3 py-2 font-mono break-all">{{ $rule['to'] ?? '—' }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </div>

            {{-- Header rules --}}
            <div>
                <h4 class="inline-flex items-center gap-2 text-sm font-semibold text-brand-ink">
                    <span>{{ __('Header rules') }}</span>
                    <span class="rounded-full bg-brand-sand/60 px-2 py-0.5 font-mono text-2xs text-brand-moss">{{ count($headers) }}</span>
                </h4>
                @if ($headers === [])
                    <x-empty-state
                        borderless
                        compact
                        icon="heroicon-o-arrows-right-left"
                        :title="__('No header rules declared')"
                    />
                @else
                    <ul class="mt-2 space-y-2">
                        @foreach ($headers as $rule)
                            <li class="rounded-lg border border-brand-ink/10 bg-brand-sand/20 px-3 py-2 text-xs">
                                <p class="font-mono text-brand-ink">{{ __('for') }} <span class="break-all">{{ $rule['for'] ?? '—' }}</span></p>
                                @php $values = is_array($rule['values'] ?? null) ? $rule['values'] : []; @endphp
                                @if ($values !== [])
                                    <dl class="mt-1 grid grid-cols-1 gap-x-3 sm:grid-cols-[12rem_1fr]">
                                        @foreach ($values as $headerName => $headerValue)
                                            <dt class="font-mono text-brand-mist">{{ $headerName }}</dt>
                                            <dd class="font-mono text-brand-ink break-all">{{ $headerValue }}</dd>
                                        @endforeach
                                    </dl>
                                @endif
                            </li>
                        @endforeach
                    </ul>
                @endif
            </div>
        </div>
    @endif
</section>
