@php
    $previews = $edgeIsPreviewChild ? collect() : \App\Modules\Edge\Actions\CreateEdgePreviewSite::listForParent($site);
@endphp

<section class="border-b border-brand-ink/10">
    @unless ($edgeIsPreviewChild)
        @can('update', $site)
            @php
                $adhocPending = $this->adhocPreviewIsPending();
            @endphp
            {{-- Poll every 5s. adhocPreviewIsPending() short-circuits to false
                 when there's no pending preview, so steady-state cost is one
                 cheap DB lookup. Inline @if(...) ... @endif inside an HTML
                 attribute trips the Blade parser, so keep the attribute flat. --}}
            <div
                class="border-b border-brand-ink/10 px-6 py-3 sm:px-8"
                wire:poll.5s="adhocPreviewIsPending"
            >
                <form wire:submit.prevent="createAdhocEdgePreview" class="space-y-0">
                    <div class="flex flex-wrap items-end gap-2">
                        <div class="min-w-[16rem] flex-1">
                            <label for="edge_preview_commit_sha" class="text-xs font-semibold uppercase tracking-[0.14em] text-brand-mist">
                                {{ __('Create preview from commit') }}
                            </label>
                            <div class="mt-1 flex gap-2">
                                <input
                                    id="edge_preview_commit_sha"
                                    type="text"
                                    wire:model="edge_deploy_commit_sha"
                                    placeholder="{{ __('Commit SHA, or browse below') }}"
                                    autocomplete="off"
                                    spellcheck="false"
                                    @disabled($adhocPending)
                                    class="min-w-0 flex-1 rounded-lg border border-brand-ink/15 bg-white px-3 py-1.5 font-mono text-xs text-brand-ink focus:border-brand-sage focus:ring-1 focus:ring-brand-sage disabled:cursor-not-allowed disabled:opacity-60"
                                />
                                <button
                                    type="button"
                                    wire:click="openEdgeDeployRefPicker"
                                    @disabled($adhocPending)
                                    class="inline-flex shrink-0 items-center gap-1.5 rounded-lg border border-brand-ink/15 bg-brand-sand/30 px-3 py-1.5 text-xs font-semibold text-brand-ink hover:bg-brand-sand/60 disabled:cursor-not-allowed disabled:opacity-60"
                                >
                                    <x-heroicon-o-magnifying-glass class="h-4 w-4" />
                                    {{ __('Browse') }}
                                </button>
                            </div>
                        </div>
                        <button
                            type="submit"
                            wire:loading.attr="disabled"
                            wire:target="createAdhocEdgePreview"
                            @disabled($adhocPending)
                            class="inline-flex items-center gap-1.5 rounded-lg bg-brand-ink px-3 py-1.5 text-xs font-semibold text-white shadow-sm hover:bg-brand-ink/90 disabled:cursor-wait disabled:opacity-60"
                        >
                            @if ($adhocPending)
                                @php
                                    // adhocPreviewIsPending() keeps us pending for 45s past
                                    // publish so the URL doesn't get hit during Cloudflare's
                                    // KV negative-cache window. Surface that distinct phase
                                    // here so the label matches what's happening.
                                    $pendingPreviewForLabel = \App\Models\Site::query()->find($edge_adhoc_preview_pending_site_id);
                                    $isPropagating = $pendingPreviewForLabel
                                        && $pendingPreviewForLabel->status === \App\Models\Site::STATUS_EDGE_ACTIVE;
                                @endphp
                                <x-spinner variant="white" size="sm" />
                                <span>{{ $isPropagating ? __('Propagating…') : __('Building…') }}</span>
                            @else
                                <x-spinner variant="white" size="sm" wire:loading wire:target="createAdhocEdgePreview" />
                                <span wire:loading.remove wire:target="createAdhocEdgePreview">{{ __('Create preview') }}</span>
                                <span wire:loading wire:target="createAdhocEdgePreview">{{ __('Queueing…') }}</span>
                            @endif
                        </button>
                    </div>
                    @if ($adhocPending)
                        @php
                            // Re-use the same provisioning-journey calculator the
                            // real edge-site dashboard uses, but scoped to the
                            // pending preview so the progress bar + steps reflect
                            // build → publish → live for THIS specific deploy.
                            $pendingPreview = \App\Models\Site::query()->find($edge_adhoc_preview_pending_site_id);
                            $journey = $pendingPreview
                                ? \App\Support\Sites\SiteShowViewData::edgeProvisioningJourney($pendingPreview)
                                : null;
                            $pendingSha = $pendingPreview
                                ? substr((string) ($pendingPreview->edgeMeta()['preview_head_sha'] ?? ''), 0, 7)
                                : '';
                        @endphp
                        @if ($journey !== null)
                            @php
                                // While the deployment row already reads "live", we still
                                // hold the pending state for ~45s to outlive Cloudflare's KV
                                // negative-cache window. Render that as a distinct phase
                                // so the user understands why the URL isn't shown yet
                                // and the button still spins.
                                $isPropagating = $journey['edgeJourneyIsDone'];
                                $pendingDeployment = $journey['edgeLatestDeployment'] ?? null;
                            @endphp
                            <div class="mt-3 overflow-hidden rounded-2xl border border-indigo-200/80 bg-gradient-to-br from-indigo-50/95 to-white">
                                <div class="flex flex-wrap items-center justify-between gap-2 border-b border-indigo-100/80 px-4 py-3">
                                    <div class="min-w-0">
                                        <p class="text-2xs font-semibold uppercase tracking-[0.16em] text-indigo-700">{{ __('Preview build') }}</p>
                                        <p class="mt-0.5 font-mono text-xs text-brand-moss">
                                            @if ($pendingSha !== '')
                                                {{ __('Preview from commit :sha', ['sha' => $pendingSha]) }}
                                            @else
                                                {{ __('Building preview…') }}
                                            @endif
                                        </p>
                                    </div>
                                    @if ($pendingDeployment !== null)
                                        <a
                                            href="{{ route('sites.edge.deployments.show', ['server' => $pendingPreview->server_id, 'site' => $pendingPreview, 'deployment' => $pendingDeployment, 'tab' => 'log']) }}"
                                            wire:navigate
                                            class="inline-flex shrink-0 items-center gap-1.5 rounded-lg border border-indigo-200 bg-white px-2.5 py-1.5 text-xs font-semibold text-indigo-800 shadow-sm hover:bg-indigo-50"
                                        >
                                            <x-heroicon-o-clipboard-document-list class="h-3.5 w-3.5" aria-hidden="true" />
                                            {{ __('Open full log') }}
                                        </a>
                                    @endif
                                </div>

                                @if ($pendingDeployment !== null)
                                    {{-- Same streaming BuildJourney as Edge provisioning / deploy detail. --}}
                                    <div class="bg-white/60">
                                        @livewire('edge.build-journey', ['deploymentId' => (string) $pendingDeployment->id], key('edge-adhoc-preview-build-'.$pendingDeployment->id))
                                    </div>
                                @else
                                    <div class="px-4 py-6 text-center text-xs text-brand-moss">
                                        <span class="inline-flex h-5 w-5 animate-spin items-center justify-center rounded-full border-2 border-indigo-200 border-t-indigo-600" aria-hidden="true"></span>
                                        <p class="mt-2">{{ __('Waiting for the build to start…') }}</p>
                                    </div>
                                @endif

                                <div class="border-t border-indigo-100/80 px-4 py-2.5 space-y-1.5">
                                    @if ($isPropagating && ! $journey['edgeJourneyHasFailed'])
                                        <p class="text-xs text-brand-moss">
                                            {{ __('Build finished — propagating to edge. Create unlocks once the URL is safe to open.') }}
                                        </p>
                                    @else
                                        <p class="text-xs text-brand-moss">
                                            {{ __('Live build output below. Auto-refreshes; Create unlocks once the URL is safe to open.') }}
                                        </p>
                                    @endif
                                    @if (\App\Modules\Edge\Support\FakeEdgeProvision::enabled())
                                        <p class="text-xs text-amber-800 dark:text-raw-amber-200">
                                            {{ __('Local Fake Edge: the preview URL must resolve to this app (e.g. *.edge.test / *.dply.test via Valet). Public on-dply.live hostnames hit Cloudflare and will not see this build.') }}
                                        </p>
                                    @endif
                                </div>
                            </div>
                        @endif
                    @elseif ($edge_deploy_commit_branch !== null)
                        <p class="mt-2 flex flex-wrap items-center gap-1.5 text-xs text-brand-moss">
                            <span>{{ __('Will preview from branch') }}</span>
                            <span class="inline-flex items-center gap-1 rounded-md bg-brand-sand/40 px-1.5 py-0.5 font-mono text-xs font-semibold text-brand-ink">
                                {{ $edge_deploy_commit_branch }}
                                <button type="button" wire:click="$set('edge_deploy_commit_branch', null)" class="text-brand-mist hover:text-brand-ink" title="{{ __('Clear branch override.') }}">
                                    <x-heroicon-m-x-mark class="h-3 w-3" aria-hidden="true" />
                                </button>
                            </span>
                        </p>
                    @else
                        <p class="mt-2 text-xs text-brand-moss">{{ __('Same SHA reuses the preview; different SHAs get their own URL.') }}</p>
                    @endif
                    @if ($edge_deploy_ref_picker_open)
                        @include('livewire.sites.partials.edge.deploy-ref-picker')
                    @endif
                </form>
            </div>
        @endcan
    @endunless

    @if ($previews->isEmpty())
        <div class="px-6 py-8 text-center text-sm text-brand-moss sm:px-8">
            <x-heroicon-o-sparkles class="mx-auto h-8 w-8 text-brand-mist" />
            <p class="mt-3 font-medium text-brand-ink">{{ __('No active previews') }}</p>
            <p class="mt-1">{{ __('Pick a commit above to spin up a one-off preview, or open a pull request against :branch to have the GitHub webhook create one.', ['branch' => $edgeBranch]) }}</p>
            <a href="{{ route('sites.show', ['server' => $server, 'site' => $site, 'section' => 'edge-deploy-triggers']) }}" wire:navigate class="mt-3 inline-block text-sm font-medium text-brand-forest hover:underline dark:text-brand-sage">
                {{ __('View webhook setup →') }}
            </a>
        </div>
    @else
        <ul class="divide-y divide-brand-ink/8">
            @foreach ($previews as $preview)
                @php
                    $previewMeta = $preview->edgeMeta();
                    $previewBranch = (string) ($previewMeta['preview_branch'] ?? '—');
                    $previewPrNumber = $previewMeta['preview_pr_number'] ?? null;
                    // Legacy rows (created before preview_kind existed) all came
                    // from the PR webhook flow, so default to 'pr' for safety.
                    $previewKind = (string) ($previewMeta['preview_kind'] ?? \App\Modules\Edge\Actions\CreateEdgePreviewSite::KIND_PR);
                    $previewRefKind = $previewMeta['preview_ref_kind'] ?? null;
                    $previewHeadSha = (string) ($previewMeta['preview_head_sha'] ?? '');
                    $previewUrl = $preview->edgeLiveUrl();
                    // Pull commit subject/author off the latest deployment.meta —
                    // populated by EdgeBuildRunner so it works for any provider
                    // (no extra GitHub/GitLab API call needed).
                    $latestPreviewDeployment = $preview->relationLoaded('edgeDeployments')
                        ? $preview->edgeDeployments->first()
                        : $preview->edgeDeployments()->latest()->first();
                    $previewCommitMeta = is_array($latestPreviewDeployment?->meta['commit'] ?? null)
                        ? $latestPreviewDeployment->meta['commit']
                        : [];
                    $previewCommitSubject = isset($previewCommitMeta['subject']) ? (string) $previewCommitMeta['subject'] : '';
                    $previewCommitAuthor = isset($previewCommitMeta['author']) ? (string) $previewCommitMeta['author'] : '';
                    // live_url is assigned at create time — only treat the hostname
                    // as openable after a successful publish (not while building/failed).
                    $previewIsLive = $preview->status === \App\Models\Site::STATUS_EDGE_ACTIVE
                        && $latestPreviewDeployment !== null
                        && $latestPreviewDeployment->status === \App\Models\EdgeDeployment::STATUS_LIVE
                        && $latestPreviewDeployment->storage_prefix !== null;
                    $previewFailed = $preview->status === \App\Models\Site::STATUS_EDGE_FAILED
                        || ($latestPreviewDeployment !== null
                            && $latestPreviewDeployment->status === \App\Models\EdgeDeployment::STATUS_FAILED);
                    $previewFailureReason = trim((string) (
                        $latestPreviewDeployment?->failure_reason
                        ?: ($preview->edgeMeta()['last_error'] ?? '')
                    ));
                    // Suppress the URL while THIS row is the one we're still
                    // holding in the pending-grace window — Cloudflare's KV
                    // negative-cache window can serve "Host not configured"
                    // for the first ~30–60s after publish.
                    $rowIsPending = $edge_adhoc_preview_pending_site_id !== null
                        && $edge_adhoc_preview_pending_site_id === (string) $preview->id;
                    $previewLogUrl = $latestPreviewDeployment !== null
                        ? route('sites.edge.deployments.show', [
                            'server' => $preview->server_id,
                            'site' => $preview,
                            'deployment' => $latestPreviewDeployment,
                            'tab' => 'log',
                        ])
                        : route('sites.show', [
                            'server' => $preview->server_id,
                            'site' => $preview,
                            'section' => 'edge-logs',
                        ]);
                @endphp
                <li class="flex flex-wrap items-center justify-between gap-4 px-6 py-4 sm:px-8">
                    <div class="min-w-0">
                        <p class="font-mono text-sm font-medium text-brand-ink">
                            {{ $previewBranch }}
                            @if ($previewRefKind === 'tag')
                                <span class="ms-1 inline-flex items-center gap-1 rounded-md bg-amber-100 px-1.5 py-0.5 text-2xs font-semibold uppercase tracking-wide text-amber-900 dark:bg-raw-amber-950/40 dark:text-amber-300">{{ __('Tag') }}</span>
                            @elseif ($previewRefKind === 'branch')
                                <span class="ms-1 inline-flex items-center gap-1 rounded-md bg-sky-100 px-1.5 py-0.5 text-2xs font-semibold uppercase tracking-wide text-sky-800 dark:bg-sky-950/40 dark:text-sky-300">{{ __('Branch tip') }}</span>
                            @endif
                            @if (is_int($previewPrNumber) || (is_string($previewPrNumber) && $previewPrNumber !== ''))
                                <span class="ms-1 text-xs font-normal text-brand-moss">· PR #{{ $previewPrNumber }}</span>
                            @elseif ($previewKind === \App\Modules\Edge\Actions\CreateEdgePreviewSite::KIND_ADHOC && $previewHeadSha !== '')
                                <span class="ms-1 inline-flex items-center gap-1 rounded-md bg-violet-100 px-1.5 py-0.5 text-2xs font-semibold uppercase tracking-wide text-violet-800 dark:bg-violet-950/40 dark:text-violet-300">{{ __('Ad-hoc') }}</span>
                                <span class="ms-1 text-xs font-normal text-brand-moss">· {{ substr($previewHeadSha, 0, 7) }}</span>
                            @endif
                            @if ($previewFailed)
                                <span class="ms-1 inline-flex items-center gap-1 rounded-md bg-rose-100 px-1.5 py-0.5 text-2xs font-semibold uppercase tracking-wide text-rose-900 dark:bg-rose-950/40 dark:text-rose-300">{{ __('Failed') }}</span>
                            @elseif (! $previewIsLive && ! $rowIsPending)
                                <span class="ms-1 inline-flex items-center gap-1 rounded-md bg-amber-100 px-1.5 py-0.5 text-2xs font-semibold uppercase tracking-wide text-amber-900 dark:bg-raw-amber-950/40 dark:text-amber-300">{{ __('Building') }}</span>
                            @endif
                        </p>
                        @if ($previewCommitSubject !== '')
                            <p class="mt-1 truncate text-xs text-brand-moss" title="{{ $previewCommitSubject }}{{ $previewCommitAuthor !== '' ? ' — '.$previewCommitAuthor : '' }}">
                                {{ \Illuminate\Support\Str::limit($previewCommitSubject, 100) }}
                                @if ($previewCommitAuthor !== '')
                                    <span class="text-brand-mist">— {{ $previewCommitAuthor }}</span>
                                @endif
                            </p>
                        @endif
                        @if ($rowIsPending)
                            <p class="mt-1 inline-flex items-center gap-1.5 text-xs text-brand-moss">
                                <span class="inline-flex h-2 w-2 animate-pulse rounded-full bg-indigo-600"></span>
                                {{ __('Propagating to edge — URL will appear when safe to open') }}
                            </p>
                        @elseif ($previewFailed)
                            <p class="mt-1 text-xs text-rose-800 dark:text-rose-300">
                                {{ $previewFailureReason !== '' ? \Illuminate\Support\Str::limit($previewFailureReason, 160) : __('Preview build failed.') }}
                            </p>
                            <a href="{{ $previewLogUrl }}" wire:navigate class="mt-1 inline-flex items-center gap-1 text-xs font-semibold text-rose-900 hover:underline dark:text-rose-300">
                                <x-heroicon-o-clipboard-document-list class="h-3.5 w-3.5" aria-hidden="true" />
                                {{ __('View build log') }}
                            </a>
                        @elseif ($previewIsLive && $previewUrl)
                            <a href="{{ $previewUrl }}" target="_blank" rel="noopener noreferrer" class="mt-1 inline-flex items-center gap-1 font-mono text-xs text-brand-forest hover:underline dark:text-brand-sage">
                                {{ $previewUrl }}
                                <x-heroicon-o-arrow-top-right-on-square class="h-3 w-3" />
                            </a>
                        @else
                            <p class="mt-1 inline-flex items-center gap-1.5 text-xs text-brand-moss">
                                <span class="inline-flex h-2 w-2 animate-pulse rounded-full bg-amber-500"></span>
                                {{ __('Build in progress — URL unlocks when the deploy is live.') }}
                            </p>
                            <a href="{{ $previewLogUrl }}" wire:navigate class="mt-1 inline-flex items-center gap-1 text-xs font-medium text-brand-sage hover:underline">
                                {{ __('View build log') }}
                            </a>
                        @endif
                    </div>
                    @can('update', $site)
                        @php
                            $parentSplit = is_array($site->edgeMeta()['split'] ?? null) ? $site->edgeMeta()['split'] : null;
                            $splitTargetsThisPreview = is_array($parentSplit)
                                && ($parentSplit['enabled'] ?? false)
                                && ($parentSplit['preview_site_id'] ?? null) === (string) $preview->id;
                            $splitInputName = 'edge_split_pct_'.$preview->id;
                            $currentSplitPct = $splitTargetsThisPreview ? (int) ($parentSplit['percentage'] ?? 0) : 0;
                        @endphp
                        <div class="flex flex-col items-end gap-2">
                            <div class="flex items-center gap-3">
                                <a
                                    href="{{ route('sites.preview-comments', ['server' => $preview->server_id, 'site' => $preview]) }}"
                                    wire:navigate
                                    class="inline-flex items-center gap-1 text-xs font-medium text-brand-moss hover:text-brand-ink"
                                >
                                    <x-heroicon-o-chat-bubble-left-right class="h-4 w-4" aria-hidden="true" />
                                    {{ __('Review') }}
                                </a>
                                @if ($previewIsLive)
                                    <button
                                        type="button"
                                        wire:click="confirmPromoteEdgePreview('{{ $preview->id }}')"
                                        wire:loading.attr="disabled"
                                        wire:target="confirmPromoteEdgePreview('{{ $preview->id }}'),promoteEdgePreview('{{ $preview->id }}')"
                                        class="inline-flex items-center gap-1 text-xs font-medium text-brand-forest hover:text-brand-ink disabled:cursor-wait disabled:opacity-60 dark:text-brand-sage"
                                        title="{{ __('Copy this preview\'s artifacts into a new production deployment and flip the host map. The preview keeps running.') }}"
                                    >
                                        <x-heroicon-o-arrow-up-tray class="h-4 w-4" aria-hidden="true" />
                                        {{ __('Promote to prod') }}
                                    </button>
                                @endif
                                <button
                                    type="button"
                                    wire:click="confirmTearDownEdgePreview('{{ $preview->id }}')"
                                    wire:loading.attr="disabled"
                                    wire:target="confirmTearDownEdgePreview('{{ $preview->id }}')"
                                    class="text-xs font-medium text-rose-700 hover:text-rose-900 disabled:cursor-wait disabled:opacity-60 dark:text-rose-400"
                                >
                                    {{ __('Tear down') }}
                                </button>
                            </div>
                            @if ($previewIsLive)
                                <div x-data="{ pct: {{ $currentSplitPct }} }" class="flex items-center gap-2 text-xs text-brand-moss">
                                    <label for="{{ $splitInputName }}" class="inline-flex items-center gap-1" title="{{ __('Route a % of production traffic to this preview (sticky via cookie).') }}">
                                        <x-heroicon-o-beaker class="h-3 w-3" aria-hidden="true" />
                                        {{ __('Split') }}
                                    </label>
                                    <input
                                        id="{{ $splitInputName }}"
                                        type="number"
                                        min="0" max="99" step="1"
                                        x-model.number="pct"
                                        class="w-14 rounded-md border border-brand-ink/15 bg-white px-1.5 py-0.5 font-mono text-xs text-brand-ink focus:border-brand-sage focus:ring-1 focus:ring-brand-sage dark:border-brand-mist/20 dark:bg-zinc-900" />
                                    <span>%</span>
                                    <button
                                        type="button"
                                        x-on:click="$wire.saveEdgeSplitTraffic('{{ $preview->id }}', pct, true)"
                                        class="rounded-md border border-brand-ink/15 bg-white px-2 py-0.5 text-2xs font-semibold uppercase tracking-wide text-brand-ink hover:bg-brand-sand/40 dark:border-brand-mist/20 dark:bg-zinc-900">
                                        {{ $splitTargetsThisPreview ? __('Update') : __('Apply') }}
                                    </button>
                                    @if ($splitTargetsThisPreview)
                                        <button
                                            type="button"
                                            x-on:click="pct = 0; $wire.saveEdgeSplitTraffic('{{ $preview->id }}', 0, true)"
                                            class="text-2xs font-semibold uppercase tracking-wide text-rose-700 hover:underline dark:text-rose-400">
                                            {{ __('Off') }}
                                        </button>
                                    @endif
                                </div>
                                @include('livewire.sites.partials.edge.deploy-contract-panel', [
                                    'preview' => $preview,
                                    'previewIsLive' => $previewIsLive,
                                    'deployContractEnabled' => $deployContractEnabled ?? false,
                                    'deployContract' => ($deployContracts ?? collect())->get((string) $preview->id, []),
                                ])
                                @if (($deployReplayEnabled ?? false) && $previewIsLive)
                                    @php
                                        $replay = ($latestReplays ?? collect())->get((string) $preview->id);
                                    @endphp
                                    <div class="w-full max-w-md space-y-2 rounded-lg border border-brand-ink/10 bg-brand-sand/20 px-3 py-2 text-xs text-brand-moss">
                                        <div class="flex flex-wrap items-center justify-between gap-2">
                                            <span class="inline-flex items-center gap-1 font-semibold text-brand-ink">
                                                <x-heroicon-o-arrow-path class="h-3 w-3" aria-hidden="true" />
                                                {{ __('Shadow replay') }}
                                            </span>
                                            <button
                                                type="button"
                                                wire:click="queueEdgeDeployReplay('{{ $preview->id }}')"
                                                wire:loading.attr="disabled"
                                                wire:target="queueEdgeDeployReplay('{{ $preview->id }}')"
                                                class="rounded-md border border-brand-ink/15 bg-white px-2 py-0.5 text-2xs font-semibold uppercase tracking-wide text-brand-ink hover:bg-brand-sand/40 disabled:opacity-60"
                                            >
                                                {{ __('Run sample') }}
                                            </button>
                                        </div>
                                        @if ($replay)
                                            <p class="text-brand-moss">
                                                @if ($replay->status === \App\Models\EdgeDeployReplay::STATUS_COMPLETED)
                                                    {{ __('Last run: :rate% status match · :reg regressions', [
                                                        'rate' => data_get($replay->summary, 'pass_rate', 0),
                                                        'reg' => data_get($replay->summary, 'regressions', 0),
                                                    ]) }}
                                                @elseif (in_array($replay->status, [\App\Models\EdgeDeployReplay::STATUS_QUEUED, \App\Models\EdgeDeployReplay::STATUS_RUNNING], true))
                                                    {{ __('Replay in progress…') }}
                                                @else
                                                    {{ $replay->error_message ?: __('Last replay failed.') }}
                                                @endif
                                            </p>
                                        @else
                                            <p>{{ __('Replays recent production GET/HEAD paths against this preview before you promote or split traffic.') }}</p>
                                        @endif
                                    </div>
                                @endif
                            @endif
                        </div>
                    @endcan
                </li>
            @endforeach
        </ul>
    @endif
</section>
