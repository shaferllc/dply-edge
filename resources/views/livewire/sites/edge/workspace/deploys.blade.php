{{-- Deploys: a sentence about what's live, rows to deploy, the history as rows, and a dialog per deploy. --}}
@php
    use App\Models\EdgeDeployment;
    use Illuminate\Support\Str;

    $deploys = $edgeDeployments->take(20);
    $live = $deploys->firstWhere('id', $edgeActiveDeploymentId);
    $latest = $deploys->first();
    $canDeploy = auth()->user()?->can('deploy', $site) ?? false;
    $sha = fn ($d) => $d->git_commit ? substr($d->git_commit, 0, 7) : Str::limit((string) $d->id, 8, '');
    $subject = fn ($d) => is_string($d->meta['commit']['subject'] ?? null) ? $d->meta['commit']['subject'] : null;
    $took = function ($d): ?string {
        $s = (int) ($d->build_seconds ?? 0);

        return $s > 0 ? ($s < 60 ? $s.'s' : intdiv($s, 60).'m '.str_pad((string) ($s % 60), 2, '0', STR_PAD_LEFT).'s') : null;
    };
    $canRollBack = fn ($d) => $d->id !== $edgeActiveDeploymentId && in_array($d->status, [EdgeDeployment::STATUS_LIVE, EdgeDeployment::STATUS_SUPERSEDED], true) && $d->storage_prefix !== null && $d->pruned_at === null;
    $say = function ($d) use ($edgeActiveDeploymentId, $canRollBack): array {
        return match (true) {
            in_array($d->status, [EdgeDeployment::STATUS_BUILDING, EdgeDeployment::STATUS_PUBLISHING], true) => [__('is building'), __('Building'), 'warn'],
            $d->status === EdgeDeployment::STATUS_FAILED => [__('failed'), __('Failed'), 'bad'],
            $d->id === $edgeActiveDeploymentId => [__('is live'), __('Live'), 'ok'],
            $d->pruned_at !== null || $d->storage_prefix === null => [__('was live; its files were removed'), __('Files removed'), 'muted'],
            $canRollBack($d) => [__('was live'), __('Roll back'), 'muted'],
            default => [__('was replaced'), __('Replaced'), 'muted'],
        };
    };
    $tone = fn (string $t) => match ($t) {
        'ok' => 'text-brand-sage',
        'bad' => 'text-rose-600 dark:text-rose-300',
        'warn' => 'text-amber-600 dark:text-amber-300',
        default => 'text-brand-moss',
    };
    $rollbackCount = $deploys->filter($canRollBack)->count();
    $failedSinceLive = $live ? $deploys->takeUntil(fn ($d) => $d->id === $live->id)->where('status', EdgeDeployment::STATUS_FAILED)->count() : 0;
    $row = 'flex min-h-12 w-full items-center gap-3 border-b border-brand-ink/10 py-3 text-left hover:bg-brand-sand/20';
    $close = fn (string $m) => '<button type="button" x-on:click="$dispatch(\'close-modal\', \''.$m.'\')" class="dply-icon-btn h-9 w-9 shrink-0" aria-label="'.e(__('Close')).'">'.svg('heroicon-o-x-mark', 'h-5 w-5', ['aria-hidden' => 'true'])->toHtml().'</button>';
    $refMissing = $canDeploy ? $this->edgeDeployRefMissingProvider() : null;
@endphp

<div @if ($isInProgress ?? false) wire:poll.2s @endif>
    <section class="border-b border-brand-ink/10 px-5 py-4 sm:px-6">
        @include('livewire.sites.edge.workspace.partials.feature-guide', [
            'docSlug' => 'deployments',
            'what' => __('Ship a new version, watch it build, roll back to an earlier build, or deploy a specific commit, branch or tag.'),
            'steps' => [
                __('Redeploy builds the production branch at its latest commit.'),
                __('Click a deploy for its URL, build log, and Roll back or Rebuild.'),
            ],
            'tips' => [
                __('Rolling back republishes an earlier build in seconds; it doesn’t rebuild.'),
                __('Builds older than your “releases to keep” setting lose their files and can only be rebuilt.'),
            ],
            'open' => false,
        ])
    </section>

    @if (($deploymentJourney ?? null) !== null && ($inProgressDeployment ?? null) !== null)
        <div class="border-b border-brand-ink/10">
            @include('livewire.sites.partials.edge.deployment-journey-card', [
                'journey' => $deploymentJourney,
                'deployment' => $inProgressDeployment,
            ])
        </div>
    @endif

    <section class="space-y-8 px-5 py-8 sm:px-10 sm:py-10">
        <div>
            <p class="text-2xs font-semibold uppercase tracking-[0.16em] text-brand-mist">{{ __('Deploys') }}</p>
            <p class="mt-3 max-w-3xl text-2xl font-medium leading-snug tracking-tight text-brand-ink sm:text-3xl">
                @if ($deploys->isEmpty())
                    {{ __('Nothing has been deployed yet.') }}
                @else
                    @if ($latest && in_array($latest->status, [EdgeDeployment::STATUS_BUILDING, EdgeDeployment::STATUS_PUBLISHING], true))
                        <span class="font-mono text-amber-600 dark:text-amber-300">{{ $sha($latest) }}</span> {{ __('is building now.') }}
                    @endif
                    @if ($live)
                        <span class="font-mono text-brand-sage">{{ $sha($live) }}</span> {{ __('has been live for :for.', ['for' => ($live->published_at ?? $live->created_at)?->diffForHumans(syntax: \Carbon\CarbonInterface::DIFF_ABSOLUTE)]) }}
                    @else
                        <span class="text-amber-600 dark:text-amber-300">{{ __('Nothing is live yet.') }}</span>
                    @endif
                    @if ($failedSinceLive > 0)
                        <span class="text-rose-600 dark:text-rose-300">{{ trans_choice('The deploy after it failed.|:count deploys after it failed.', $failedSinceLive) }}</span>
                    @endif
                    {{ $rollbackCount > 0 ? trans_choice(':count earlier build is ready to roll back to.|:count earlier builds are ready to roll back to.', $rollbackCount) : __('There’s no earlier build to roll back to.') }}
                @endif
            </p>
        </div>

        @if ($canDeploy)
            <div>
                <p class="border-b border-brand-ink/10 pb-2 text-sm font-semibold text-brand-ink">{{ __('Deploy') }}</p>
                <button type="button" wire:click="redeployEdge" wire:loading.attr="disabled" wire:target="redeployEdge" class="{{ $row }} font-medium text-brand-sage">
                    <x-heroicon-m-arrow-path class="h-4 w-4 shrink-0" aria-hidden="true" wire:loading.class="animate-spin" wire:target="redeployEdge" />
                    <span class="flex-1 text-sm sm:text-base">{{ $deploys->isEmpty() ? __('Run the first deploy') : __('Redeploy the latest :branch', ['branch' => $edgeBranch]) }}</span>
                    <span class="shrink-0 text-xs text-brand-moss">{{ __('Build now') }}</span>
                </button>
                <button type="button" x-on:click="$dispatch('open-modal', 'deploy-ref')" class="{{ $row }}">
                    <span class="flex-1 text-sm text-brand-ink sm:text-base">{{ __('Deploy a specific commit, branch or tag') }}</span>
                    <x-heroicon-m-chevron-right class="h-4 w-4 shrink-0 text-brand-mist" aria-hidden="true" />
                </button>
            </div>
        @endif

        @if ($deploys->isNotEmpty())
            <div>
                <p class="border-b border-brand-ink/10 pb-2 text-sm font-semibold text-brand-ink">{{ __('History') }}</p>
                @foreach ($deploys as $d)
                    @php [$verb, $state, $t] = $say($d); @endphp
                    <button type="button" wire:click="openDeploy(@js((string) $d->id))" class="{{ $row }}" wire:key="deploy-row-{{ $d->id }}">
                        <span class="min-w-0 flex-1 text-sm text-brand-ink sm:text-base">
                            <span class="font-mono">{{ $sha($d) }}</span> {{ $verb }}@if ($subject($d)) <span class="text-brand-moss">· {{ Str::limit($subject($d), 60) }}</span>@endif
                        </span>
                        <span class="shrink-0 text-xs {{ $tone($t) }}">{{ $state }} · {{ ($d->published_at ?? $d->created_at)?->diffForHumans(short: true) }}</span>
                        <x-heroicon-m-chevron-right class="h-4 w-4 shrink-0 text-brand-mist" aria-hidden="true" />
                    </button>
                @endforeach
            </div>
        @endif
    </section>

    {{-- One deploy --}}
    <x-modal name="deploy-detail" maxWidth="2xl" overlayClass="bg-brand-ink/40" focusable>
        @php $o = $openDeployId ? $deploys->firstWhere('id', $openDeployId) : null; @endphp
        @if ($o)
            @php
                [$verb, $state, $t] = $say($o);
                $detailUrl = route('sites.edge.deployments.show', ['server' => $server, 'site' => $site, 'deployment' => $o]);
                $author = is_string($o->meta['commit']['author'] ?? null) ? $o->meta['commit']['author'] : null;
            @endphp
            <div class="space-y-5 p-6 sm:p-7">
                <div class="flex items-start justify-between gap-4">
                    <div class="min-w-0">
                        <h2 class="text-lg font-semibold text-brand-ink"><span class="font-mono">{{ $sha($o) }}</span> {{ $verb }}@if ($subject($o)) · {{ $subject($o) }}@endif</h2>
                        <p class="mt-0.5 text-sm text-brand-moss">
                            {{ collect([$o->git_branch ?? $edgeBranch, $author, ($o->published_at ?? $o->created_at)?->diffForHumans(), $took($o) ? __('built in :t', ['t' => $took($o)]) : null])->filter()->implode(' · ') }}
                        </p>
                    </div>
                    {!! $close('deploy-detail') !!}
                </div>

                @if ($o->status === EdgeDeployment::STATUS_FAILED && filled($o->failure_reason))
                    <pre class="max-h-40 overflow-auto whitespace-pre-wrap rounded-lg border border-rose-200/60 bg-rose-50/50 p-3 font-mono text-xs text-rose-900 dark:border-raw-rose-900/30 dark:bg-rose-950/20 dark:text-raw-rose-200">{{ Str::limit($o->failure_reason, 1200) }}</pre>
                @endif

                @foreach ($o->aliasHostnames() as $alias)
                    <a href="https://{{ $alias }}" target="_blank" rel="noopener noreferrer" class="flex items-center justify-between gap-3 rounded-lg bg-brand-sand/30 px-3 py-2.5 font-mono text-sm text-brand-forest hover:bg-brand-sand/50 dark:text-brand-sage">
                        <span class="min-w-0 truncate">{{ $alias }}</span>
                        <span class="shrink-0 font-sans text-xs font-medium">{{ __('Open') }} ↗</span>
                    </a>
                @endforeach
                @if ($o->aliasHostnames() !== [])
                    <p class="-mt-3 text-xs text-brand-moss">{{ trans_choice('This address always shows this build, whatever is live.|These addresses always show this build, whatever is live.', count($o->aliasHostnames())) }}</p>
                @endif

                <p class="text-sm text-brand-moss">
                    @if ($canRollBack($o))
                        {{ __('Rolling back makes this build live again in seconds, without rebuilding. What’s live now stays in the history.') }}
                    @elseif ($o->pruned_at !== null || ($o->storage_prefix === null && $o->status !== EdgeDeployment::STATUS_FAILED && ! in_array($o->status, [EdgeDeployment::STATUS_BUILDING, EdgeDeployment::STATUS_PUBLISHING], true)))
                        {{ __('Its files were removed by your releases-to-keep setting, so it can only be rebuilt from its commit.') }}
                    @endif
                </p>

                <div class="flex flex-wrap items-center justify-between gap-2 border-t border-brand-ink/10 pt-4">
                    <span class="flex gap-4 text-sm">
                        <a href="{{ $detailUrl }}?tab=log" wire:navigate class="font-medium text-brand-sage hover:underline">{{ __('Build log') }}</a>
                        <a href="{{ $detailUrl }}" wire:navigate class="font-medium text-brand-sage hover:underline">{{ __('Details') }}</a>
                    </span>
                    @if ($canDeploy)
                        <span class="flex gap-2">
                            @if ($o->git_commit && $refMissing === null)
                                <x-sheet.button type="button" wire:click="rebuildFrom({{ \Illuminate\Support\Js::from((string) $o->id) }})">{{ __('Rebuild from this commit') }}</x-sheet.button>
                            @endif
                            @if ($canRollBack($o))
                                <x-sheet.button type="button" variant="primary" x-on:click="$dispatch('close-modal', 'deploy-detail')" wire:click="confirmRollbackEdgeDeployment({{ \Illuminate\Support\Js::from((string) $o->id) }})">{{ __('Roll back to this') }}</x-sheet.button>
                            @endif
                        </span>
                    @endif
                </div>
            </div>
        @endif
    </x-modal>

    {{-- Deploy a commit, branch or tag --}}
    @if ($canDeploy)
        <x-modal name="deploy-ref" maxWidth="2xl" overlayClass="bg-brand-ink/40" focusable>
            <div class="space-y-4 p-6 sm:p-7">
                <div class="flex items-start justify-between gap-4">
                    <div>
                        <h2 class="text-lg font-semibold text-brand-ink">{{ __('Deploy a commit, branch or tag') }}</h2>
                        <p class="mt-0.5 text-sm text-brand-moss">{{ __('If that commit was already built, it goes live straight away. Otherwise it’s built first.') }}</p>
                    </div>
                    {!! $close('deploy-ref') !!}
                </div>
                @if ($refMissing !== null)
                    @php $providerLabel = match ($refMissing) { 'github' => 'GitHub', 'gitlab' => 'GitLab', 'bitbucket' => 'Bitbucket', default => ucfirst($refMissing) }; @endphp
                    <p class="text-sm text-brand-moss">{{ __('Connect :provider to deploy a specific commit, branch tip, or tag.', ['provider' => $providerLabel]) }}</p>
                    <x-connect-provider-link class="!text-sm">{{ __('Connect :provider', ['provider' => $providerLabel]) }} &rarr;</x-connect-provider-link>
                @else
                    <form wire:submit.prevent="deployEdgeCommit" x-on:submit="$dispatch('close-modal', 'deploy-ref')" class="space-y-3">
                        <div class="flex gap-2">
                            <input id="edge_deploy_commit_sha" type="text" wire:model="edge_deploy_commit_sha" placeholder="{{ __('Commit SHA, or browse') }}" aria-label="{{ __('Commit SHA') }}" autocomplete="off" spellcheck="false" class="dply-input mt-0 min-w-0 flex-1 font-mono text-xs" />
                            <x-sheet.button type="button" wire:click="openEdgeDeployRefPicker">{{ __('Browse') }}</x-sheet.button>
                            <x-sheet.button type="submit" variant="primary" wire:loading.attr="disabled" wire:target="deployEdgeCommit">{{ __('Deploy') }}</x-sheet.button>
                        </div>
                        @if ($edge_deploy_commit_branch !== null)
                            <p class="flex items-center gap-1.5 text-xs text-brand-moss">
                                {{ __('Records it on branch') }} <span class="font-mono font-semibold text-brand-ink">{{ $edge_deploy_commit_branch }}</span>
                                <button type="button" wire:click="$set('edge_deploy_commit_branch', null)" class="text-brand-mist hover:text-brand-ink" aria-label="{{ __('Clear branch') }}">×</button>
                            </p>
                        @endif
                        @if ($edge_deploy_ref_picker_open)
                            @include('livewire.sites.partials.edge.deploy-ref-picker')
                        @endif
                    </form>
                @endif
            </div>
        </x-modal>
    @endif

    @include('livewire.partials.confirm-action-modal')
</div>
