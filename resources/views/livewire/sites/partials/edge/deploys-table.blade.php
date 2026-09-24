@php
    $compact = $compact ?? false;
    $limit = $compact ? 5 : 20;
    $tableDeployments = $edgeDeployments->take($limit);
@endphp

<section class="dply-card overflow-hidden">
    <div class="flex flex-wrap items-baseline justify-between gap-3 border-b border-brand-ink/10 px-6 py-4 sm:px-8">
        <div>
            <h3 class="text-base font-semibold text-brand-ink">{{ __('Deploy history') }}</h3>
            @unless ($compact)
                <p class="mt-0.5 text-sm text-brand-moss">{{ __('Each build publishes static assets to the Edge CDN.') }}</p>
            @endunless
        </div>
        @if ($compact)
            <a href="{{ route('sites.show', ['server' => $server, 'site' => $site, 'section' => 'deploys']) }}" wire:navigate class="text-xs font-medium text-brand-sage hover:underline">
                {{ __('View all →') }}
            </a>
        @elseif ($edgeDeployments->count() > 0)
            @can('update', $site)
                <button
                    type="button"
                    wire:click="redeployEdge"
                    wire:loading.attr="disabled"
                    wire:target="redeployEdge"
                    class="inline-flex items-center gap-1.5 rounded-lg border border-brand-ink/15 bg-white px-3 py-1.5 text-xs font-semibold text-brand-ink hover:bg-brand-sand/40"
                >
                    <x-heroicon-o-arrow-path class="h-4 w-4" wire:loading.remove wire:target="redeployEdge" />
                    {{ __('Redeploy now') }}
                </button>
            @endcan
        @endif
    </div>

    @unless ($compact)
        @can('update', $site)
            @php
                $edgeDeployRefMissingProvider = $this->edgeDeployRefMissingProvider();
            @endphp
            @if ($edgeDeployRefMissingProvider === null)
                <div class="border-b border-brand-ink/10 px-6 py-3 sm:px-8">
                    <form wire:submit.prevent="deployEdgeCommit" class="space-y-0">
                        <div class="flex flex-wrap items-end gap-2">
                            <div class="min-w-[16rem] flex-1">
                                <label for="edge_deploy_commit_sha" class="text-xs font-semibold uppercase tracking-[0.14em] text-brand-mist">
                                    {{ __('Deploy ref') }}
                                </label>
                                <div class="mt-1 flex gap-2">
                                    <input
                                        id="edge_deploy_commit_sha"
                                        type="text"
                                        wire:model="edge_deploy_commit_sha"
                                        placeholder="{{ __('Commit SHA, or browse below') }}"
                                        autocomplete="off"
                                        spellcheck="false"
                                        class="min-w-0 flex-1 rounded-lg border border-brand-ink/15 bg-white px-3 py-1.5 font-mono text-xs text-brand-ink focus:border-brand-sage focus:ring-1 focus:ring-brand-sage"
                                    />
                                    <button
                                        type="button"
                                        wire:click="openEdgeDeployRefPicker"
                                        class="inline-flex shrink-0 items-center gap-1.5 rounded-lg border border-brand-ink/15 bg-brand-sand/30 px-3 py-1.5 text-xs font-semibold text-brand-ink hover:bg-brand-sand/60"
                                    >
                                        <x-heroicon-o-magnifying-glass class="h-4 w-4" />
                                        {{ __('Browse') }}
                                    </button>
                                </div>
                            </div>
                            <button
                                type="submit"
                                wire:loading.attr="disabled"
                                wire:target="deployEdgeCommit"
                                class="inline-flex items-center gap-1.5 rounded-lg border border-brand-ink/15 bg-white px-3 py-1.5 text-xs font-semibold text-brand-ink hover:bg-brand-sand/40"
                            >
                                {{ __('Deploy') }}
                            </button>
                        </div>
                        @if ($edge_deploy_commit_branch !== null)
                            <p class="mt-2 flex flex-wrap items-center gap-1.5 text-xs text-brand-moss">
                                <span>{{ __('Will deploy on branch') }}</span>
                                <span class="inline-flex items-center gap-1 rounded-md bg-brand-sand/40 px-1.5 py-0.5 font-mono text-xs font-semibold text-brand-ink">
                                    {{ $edge_deploy_commit_branch }}
                                    <button type="button" wire:click="$set('edge_deploy_commit_branch', null)" class="text-brand-mist hover:text-brand-ink" title="{{ __('Clear branch override (deploy will record the site default).') }}">
                                        <x-heroicon-m-x-mark class="h-3 w-3" aria-hidden="true" />
                                    </button>
                                </span>
                            </p>
                        @else
                            <p class="mt-2 text-xs text-brand-moss">{{ __('Pick a commit, branch tip, or tag from your connected Git provider. Re-flips KV if we already built that commit; otherwise rebuilds from that ref.') }}</p>
                        @endif
                        @if ($edge_deploy_ref_picker_open)
                            @include('livewire.sites.partials.edge.deploy-ref-picker')
                        @endif
                    </form>
                </div>
            @else
                @php
                    $missingProviderLabel = match ($edgeDeployRefMissingProvider) {
                        'github' => 'GitHub',
                        'gitlab' => 'GitLab',
                        'bitbucket' => 'Bitbucket',
                        default => ucfirst($edgeDeployRefMissingProvider),
                    };
                @endphp
                <div class="flex flex-wrap items-center justify-between gap-3 border-b border-brand-ink/10 bg-brand-sand/20 px-6 py-3 text-sm text-brand-moss sm:px-8">
                    <p>{{ __('Connect :provider to deploy a specific commit, branch tip, or tag.', ['provider' => $missingProviderLabel]) }}</p>
                    <x-connect-provider-link class="!text-sm">
                        {{ __('Connect :provider', ['provider' => $missingProviderLabel]) }} &rarr;
                    </x-connect-provider-link>
                </div>
            @endif
        @endcan
    @endunless

    @if ($tableDeployments->isEmpty())
        <div class="px-6 py-8 text-center text-sm text-brand-moss sm:px-8">
            <p>{{ __('No deployments yet.') }}</p>
            @can('update', $site)
                <button type="button" wire:click="redeployEdge" wire:loading.attr="disabled" class="mt-3 text-sm font-medium text-brand-forest hover:underline dark:text-brand-sage">
                    {{ __('Trigger first deploy') }}
                </button>
            @endcan
        </div>
    @else
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-brand-ink/8 text-sm">
                <thead class="bg-brand-sand/30 text-left text-2xs font-semibold uppercase tracking-[0.14em] text-brand-mist">
                    <tr>
                        <th class="px-6 py-3 sm:px-8">{{ __('Deployment') }}</th>
                        <th class="whitespace-nowrap px-4 py-3">{{ __('Status') }}</th>
                        <th class="whitespace-nowrap px-4 py-3">{{ __('Branch') }}</th>
                        <th class="whitespace-nowrap px-4 py-3">{{ __('Commit') }}</th>
                        <th class="w-px whitespace-nowrap px-4 py-3 text-right">{{ __('Build') }}</th>
                        <th class="whitespace-nowrap px-4 py-3">{{ __('Published') }}</th>
                        <th class="px-6 py-3 text-right sm:px-8">{{ __('Actions') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-brand-ink/8 text-brand-ink">
                    @foreach ($tableDeployments as $deployment)
                        @php
                            $isActive = $edgeActiveDeploymentId === $deployment->id;
                            $depBadge = match ($deployment->status) {
                                \App\Models\EdgeDeployment::STATUS_LIVE => 'bg-emerald-100 text-emerald-800 dark:bg-raw-emerald-950/40 dark:text-emerald-300',
                                \App\Models\EdgeDeployment::STATUS_FAILED => 'bg-rose-100 text-rose-800 dark:bg-rose-950/40 dark:text-rose-300',
                                \App\Models\EdgeDeployment::STATUS_BUILDING, \App\Models\EdgeDeployment::STATUS_PUBLISHING => 'bg-sky-100 text-sky-800 dark:bg-sky-950/40 dark:text-sky-300',
                                default => 'bg-brand-sand/60 text-brand-moss',
                            };
                        @endphp
                        <tr wire:key="edge-dep-{{ $deployment->id }}">
                            <td class="px-6 py-3 font-mono text-xs sm:px-8">
                                <a
                                    href="{{ route('sites.edge.deployments.show', ['server' => $server, 'site' => $site, 'deployment' => $deployment]) }}"
                                    wire:navigate
                                    class="font-semibold text-brand-forest hover:underline dark:text-brand-sage"
                                    title="{{ __('Open deployment detail') }}"
                                >
                                    {{ \Illuminate\Support\Str::limit($deployment->id, 14, '') }}
                                </a>
                                @php
                                    $deploymentAliases = $deployment->aliasHostnames();
                                @endphp
                                @if ($deploymentAliases !== [])
                                    <div class="mt-1 flex flex-col gap-0.5 text-2xs font-normal text-brand-moss">
                                        @foreach ($deploymentAliases as $alias)
                                            <a href="{{ route('sites.edge.deployments.show', ['server' => $server, 'site' => $site, 'deployment' => $deployment, 'tab' => 'aliases']) }}" wire:navigate class="inline-flex items-center gap-1 hover:text-brand-forest dark:hover:text-brand-sage" title="{{ __('Stable per-deploy URL — always points at this build.') }}">
                                                <x-heroicon-o-link class="h-3 w-3 opacity-60" aria-hidden="true" />
                                                {{ $alias }}
                                            </a>
                                        @endforeach
                                    </div>
                                @endif
                            </td>
                            <td class="whitespace-nowrap px-4 py-3">
                                <span class="inline-flex rounded-full px-2 py-0.5 text-2xs font-semibold uppercase tracking-wide {{ $depBadge }}">
                                    {{ str_replace('_', ' ', (string) $deployment->status) }}
                                </span>
                                @if ($isActive)
                                    <span class="ms-1 inline-flex rounded-full bg-brand-sand/70 px-2 py-0.5 text-2xs font-semibold uppercase tracking-wide text-brand-moss dark:bg-brand-sand/20">
                                        {{ __('Production') }}
                                    </span>
                                @endif
                            </td>
                            <td class="px-4 py-3 font-mono text-xs">{{ $deployment->git_branch ?? $edgeBranch }}</td>
                            <td class="px-4 py-3 font-mono text-xs">
                                @if ($deployment->git_commit)
                                    <span title="{{ $deployment->git_commit }}">{{ substr($deployment->git_commit, 0, 7) }}</span>
                                @else
                                    <span class="text-brand-mist">—</span>
                                @endif
                            </td>
                            <td class="w-px whitespace-nowrap px-4 py-3 text-right text-xs tabular-nums text-brand-moss">
                                @php
                                    // Clone → build → deploy. Queue wait and publish are excluded
                                    // (BuildEdgeSiteJob times from build_started_at), so this is
                                    // the number that moves when a build gets faster.
                                    $secs = (int) ($deployment->build_seconds ?? 0);
                                @endphp
                                @if ($secs > 0)
                                    <span title="{{ __('Clone, build and deploy — excludes queue wait.') }}">
                                        {{ $secs < 60 ? $secs.'s' : intdiv($secs, 60).'m '.str_pad((string) ($secs % 60), 2, '0', STR_PAD_LEFT).'s' }}
                                    </span>
                                @else
                                    <span class="text-brand-mist">—</span>
                                @endif
                            </td>
                            <td class="whitespace-nowrap px-4 py-3 text-xs text-brand-moss">
                                {{ $deployment->published_at?->diffForHumans() ?? ($deployment->created_at?->diffForHumans() ?? '—') }}
                                @if ($deployment->pruned_at)
                                    <span class="ms-1 inline-flex rounded-full bg-brand-sand/60 px-1.5 py-0.5 text-2xs font-semibold uppercase tracking-wide text-brand-mist" title="{{ __('R2 artifacts deleted by retention policy.') }}">{{ __('Pruned') }}</span>
                                @endif
                            </td>
                            <td class="px-6 py-3 text-right text-xs sm:px-8">
                                @if (! $isActive && ($deployment->status === \App\Models\EdgeDeployment::STATUS_LIVE || $deployment->status === \App\Models\EdgeDeployment::STATUS_SUPERSEDED) && $deployment->storage_prefix !== null)
                                    @can('update', $site)
                                        <button type="button" wire:click="confirmRollbackEdgeDeployment('{{ $deployment->id }}')" class="font-medium text-brand-forest hover:underline dark:text-brand-sage">
                                            {{ __('Roll back') }}
                                        </button>
                                    @endcan
                                @elseif ($deployment->storage_prefix === null && $deployment->git_commit)
                                    @can('update', $site)
                                        <button type="button" wire:click="$set('edge_deploy_commit_sha', '{{ $deployment->git_commit }}')" class="font-medium text-brand-moss hover:underline" title="{{ __('Fill the deploy-commit input with this SHA so you can rebuild from it.') }}">
                                            {{ __('Rebuild') }}
                                        </button>
                                    @endcan
                                @else
                                    <span class="text-brand-mist">—</span>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</section>
