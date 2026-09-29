{{-- Previews: a sentence, a row per preview, and dialogs for one preview, a new preview, and settings. --}}
@php
    use App\Models\EdgeDeployment;
    use App\Models\EdgeDeployReplay;
    use App\Models\Site;
    use App\Modules\Edge\Actions\CreateEdgePreviewSite;
    use Illuminate\Support\Str;

    $previews = $edgeIsPreviewChild ? collect() : CreateEdgePreviewSite::listForParent($site);
    $canDeploy = auth()->user()?->can('deploy', $site) ?? false;
    $canEdit = auth()->user()?->can('update', $site) ?? false;
    $split = is_array($site->edgeMeta()['split'] ?? null) && ($site->edgeMeta()['split']['enabled'] ?? false) ? $site->edgeMeta()['split'] : null;
    $protection = (string) ($site->edgeSiteAccessRule?->mode ?? 'off');
    $commentsOn = (bool) ($site->edgeMeta()['comment_widget']['enabled'] ?? false);
    $adhocPending = ! $edgeIsPreviewChild && $canDeploy && $this->adhocPreviewIsPending();

    // One plain description per preview, shared by its row and its dialog.
    $describe = function (Site $preview) use ($split, $edge_adhoc_preview_pending_site_id): array {
        $meta = $preview->edgeMeta();
        $deployment = $preview->relationLoaded('edgeDeployments') ? $preview->edgeDeployments->first() : $preview->edgeDeployments()->latest()->first();
        $commit = is_array($deployment?->meta['commit'] ?? null) ? $deployment->meta['commit'] : [];
        $pr = $meta['preview_pr_number'] ?? null;
        $sha = substr((string) ($meta['preview_head_sha'] ?? ''), 0, 7);
        $name = ($pr !== null && $pr !== '') ? '#'.$pr : (string) ($meta['preview_branch'] ?? $sha);
        $subject = (string) ($commit['subject'] ?? '');
        $live = $preview->status === Site::STATUS_EDGE_ACTIVE && $deployment?->status === EdgeDeployment::STATUS_LIVE && $deployment->storage_prefix !== null;
        $failed = $preview->status === Site::STATUS_EDGE_FAILED || $deployment?->status === EdgeDeployment::STATUS_FAILED;
        $pending = $edge_adhoc_preview_pending_site_id !== null && $edge_adhoc_preview_pending_site_id === (string) $preview->id;
        $pct = $split !== null && ($split['preview_site_id'] ?? null) === (string) $preview->id ? (int) ($split['percentage'] ?? 0) : 0;

        return [
            'name' => $name,
            'title' => trim($name.' '.($subject !== '' ? $subject : '')),
            'branch' => (string) ($meta['preview_branch'] ?? ''),
            'sha' => $sha,
            'kind' => $pr !== null && $pr !== '' ? __('Pull request') : (($meta['preview_ref_kind'] ?? null) === 'tag' ? __('Tag') : __('Ad-hoc')),
            'author' => (string) ($commit['author'] ?? ''),
            'deployment' => $deployment,
            'live' => $live && ! $pending,
            'failed' => $failed,
            'pending' => $pending || (! $live && ! $failed),
            'url' => $preview->edgeLiveUrl(),
            'reason' => trim((string) ($deployment?->failure_reason ?: ($meta['last_error'] ?? ''))),
            'pct' => $pct,
            'log' => $deployment !== null
                ? route('sites.edge.deployments.show', ['server' => $preview->server_id, 'site' => $preview, 'deployment' => $deployment, 'tab' => 'log'])
                : route('sites.show', ['server' => $preview->server_id, 'site' => $preview, 'section' => 'logs']),
        ];
    };
    $described = $previews->mapWithKeys(fn ($p) => [(string) $p->id => $describe($p)]);
    $liveCount = $described->where('live', true)->count();
    $splitPreview = $split !== null ? $described->get((string) ($split['preview_site_id'] ?? '')) : null;
    $row = 'flex min-h-12 w-full items-center gap-3 border-b border-brand-ink/10 py-3 text-left hover:bg-brand-sand/20';
    $close = fn (string $m) => '<button type="button" x-on:click="$dispatch(\'close-modal\', \''.$m.'\')" class="dply-icon-btn h-9 w-9 shrink-0" aria-label="'.e(__('Close')).'">'.svg('heroicon-o-x-mark', 'h-5 w-5', ['aria-hidden' => 'true'])->toHtml().'</button>';
@endphp

<section class="space-y-8 px-5 py-8 sm:px-10 sm:py-10">
    <div>
        <p class="text-2xs font-semibold uppercase tracking-[0.16em] text-brand-mist">{{ __('Previews') }}</p>
        <p class="mt-3 max-w-3xl text-2xl font-medium leading-snug tracking-tight text-brand-ink sm:text-3xl">
            @if (! $previewPolicy['enabled'])
                <span class="text-amber-600 dark:text-amber-300">{{ __('Pull requests don’t get previews.') }}</span>
            @elseif ($previewPolicy['pr_only'])
                {{ __('Every pull request gets its own URL.') }}
            @else
                {{ __('Pull requests and matching branches get their own URL.') }}
            @endif
            @if ($liveCount > 0)
                <span class="text-brand-sage">{{ trans_choice(':count preview is live|:count previews are live', $liveCount) }}</span>@if ($splitPreview), {{ __('and') }} {{ $splitPreview['name'] }} {{ __('is getting') }} <span class="text-amber-600 dark:text-amber-300">{{ __(':pct% of production traffic', ['pct' => $splitPreview['pct']]) }}</span>@endif.
            @else
                {{ __('No previews are live right now.') }}
            @endif
            {{ match ($protection) {
                'password' => __('Opening one takes a password.'),
                'dply_account' => __('Opening one takes a dply sign-in.'),
                default => __('Anyone with a link can open them.'),
            } }}
        </p>
    </div>

    <div>
        <p class="border-b border-brand-ink/10 pb-2 text-sm font-semibold text-brand-ink">{{ __('Previews') }}</p>
        @if ($adhocPending)
            @php $pendingPreview = Site::query()->find($edge_adhoc_preview_pending_site_id); @endphp
            <button type="button" x-on:click="$dispatch('open-modal', 'preview-new')" class="{{ $row }}" wire:poll.5s="adhocPreviewIsPending">
                <span class="inline-flex h-2 w-2 shrink-0 animate-pulse rounded-full bg-amber-500"></span>
                <span class="flex-1 text-sm text-brand-ink sm:text-base">
                    {{ $pendingPreview?->status === Site::STATUS_EDGE_ACTIVE
                        ? __('A new preview is going out to the edge. Its URL appears when it’s safe to open.')
                        : __('Building a preview of :sha…', ['sha' => substr((string) ($pendingPreview?->edgeMeta()['preview_head_sha'] ?? ''), 0, 7)]) }}
                </span>
                <span class="shrink-0 text-xs text-brand-moss">{{ __('Watch') }}</span>
                <x-heroicon-m-chevron-right class="h-4 w-4 shrink-0 text-brand-mist" aria-hidden="true" />
            </button>
        @endif
        @foreach ($previews as $preview)
            @php $d = $described[(string) $preview->id]; @endphp
            <button type="button" wire:click="openPreview(@js((string) $preview->id))" class="{{ $row }}" wire:key="preview-row-{{ $preview->id }}">
                <span class="min-w-0 flex-1 text-sm text-brand-ink sm:text-base">
                    <span class="font-mono">{{ $d['name'] }}</span>
                    @if ($d['title'] !== $d['name']) {{ Str::after($d['title'], $d['name'].' ') }} @endif
                    @if ($d['failed'])
                        {{ __('failed to build') }}
                    @elseif ($d['live'] && $d['pct'] > 0)
                        {{ __('is live and getting :pct% of traffic', ['pct' => $d['pct']]) }}
                    @elseif ($d['live'])
                        {{ __('is live') }}
                    @else
                        {{ __('is building') }}
                    @endif
                </span>
                <span @class(['shrink-0 text-xs', 'text-rose-600 dark:text-rose-300' => $d['failed'], 'text-brand-sage' => $d['live'], 'text-amber-600 dark:text-amber-300' => ! $d['failed'] && ! $d['live']])>
                    {{ $d['failed'] ? __('Failed') : ($d['live'] ? ($d['pct'] > 0 ? __(':pct% traffic', ['pct' => $d['pct']]) : __('Live')) : __('Building')) }}
                </span>
                <x-heroicon-m-chevron-right class="h-4 w-4 shrink-0 text-brand-mist" aria-hidden="true" />
            </button>
        @endforeach
        @if ($previews->isEmpty() && ! $adhocPending)
            <p class="border-b border-brand-ink/10 py-3 text-sm text-brand-moss">{{ __('No previews yet. Open a pull request against :branch, or preview a commit below.', ['branch' => $edgeBranch]) }}</p>
        @endif
        @if ($canDeploy && ! $edgeIsPreviewChild)
            <button type="button" x-on:click="$dispatch('open-modal', 'preview-new')" class="{{ $row }} font-medium text-brand-sage">
                <x-heroicon-m-plus class="h-4 w-4 shrink-0" aria-hidden="true" />
                <span class="flex-1 text-sm sm:text-base">{{ __('Preview a commit or branch') }}</span>
            </button>
        @endif
    </div>

    @unless ($edgeIsPreviewChild)
        <div>
            <p class="border-b border-brand-ink/10 pb-2 text-sm font-semibold text-brand-ink">{{ __('How previews work') }}</p>
            <button type="button" x-on:click="$dispatch('open-modal', 'preview-policy')" class="{{ $row }}">
                <span class="flex-1 text-sm text-brand-ink sm:text-base">
                    @if (! $previewPolicy['enabled'])
                        {{ __('Pull requests don’t get previews') }}
                    @elseif ($previewPolicy['pr_only'])
                        {{ __('Pull requests get a preview; pushes to other branches don’t') }}
                    @else
                        {{ __('Pull requests and :branches get a preview', ['branches' => implode(', ', $previewPolicy['branches'] ?: [__('every branch')])]) }}
                    @endif
                    @if (($previewPolicy['exclude_branches'] ?? []) !== [])
                        <span class="text-brand-moss">· {{ __('except :list', ['list' => implode(', ', $previewPolicy['exclude_branches'])]) }}</span>
                    @endif
                </span>
                <span class="shrink-0 text-xs text-brand-moss">{{ $sourcePath }}</span>
                <x-heroicon-m-chevron-right class="h-4 w-4 shrink-0 text-brand-mist" aria-hidden="true" />
            </button>
            <button type="button" x-on:click="$dispatch('open-modal', 'preview-protection')" class="{{ $row }}">
                <span class="flex-1 text-sm text-brand-ink sm:text-base">
                    {{ match ($protection) {
                        'password' => __('Previews and the live site ask for a password'),
                        'dply_account' => __('Previews and the live site ask visitors to sign in to dply'),
                        default => __('Anyone with the link can open a preview'),
                    } }}
                </span>
                <span @class(['shrink-0 text-xs', 'text-brand-moss' => $protection === 'off', 'text-brand-sage' => $protection !== 'off'])>{{ $protection === 'off' ? __('Protection off') : __('Protected') }}</span>
                <x-heroicon-m-chevron-right class="h-4 w-4 shrink-0 text-brand-mist" aria-hidden="true" />
            </button>
            <button type="button" x-on:click="$dispatch('open-modal', 'preview-comments')" class="{{ $row }}">
                <span class="flex-1 text-sm text-brand-ink sm:text-base">{{ $commentsOn ? __('Reviewers can leave notes on preview pages') : __('Reviewers can’t leave notes on preview pages') }}</span>
                <span class="shrink-0 text-xs text-brand-moss">{{ $commentsOn ? __('Comments on') : __('Comments off') }}</span>
                <x-heroicon-m-chevron-right class="h-4 w-4 shrink-0 text-brand-mist" aria-hidden="true" />
            </button>
        </div>
    @endunless
</section>

{{-- One preview --}}
<x-modal name="preview-detail" maxWidth="2xl" overlayClass="bg-brand-ink/40" focusable>
    @php
        $open = $openPreviewId ? $previews->firstWhere('id', $openPreviewId) : null;
        $d = $open ? $described[(string) $open->id] : null;
    @endphp
    @if ($open && $d)
        <div class="space-y-5 p-6 sm:p-7">
            <div class="flex items-start justify-between gap-4">
                <div class="min-w-0">
                    <h2 class="text-lg font-semibold text-brand-ink">{{ $d['title'] }}</h2>
                    <p class="mt-0.5 text-sm text-brand-moss">
                        {{ collect([$d['kind'], $d['branch'], $d['sha'], $d['author'], $d['deployment']?->created_at?->diffForHumans()])->filter()->implode(' · ') }}
                    </p>
                </div>
                {!! $close('preview-detail') !!}
            </div>

            @if ($d['failed'])
                <x-sheet.note tone="danger">{{ $d['reason'] !== '' ? Str::limit($d['reason'], 240) : __('The preview build failed.') }}</x-sheet.note>
                <a href="{{ $d['log'] }}" wire:navigate class="text-sm font-medium text-brand-sage hover:underline">{{ __('Open the build log') }}</a>
            @elseif (! $d['live'])
                <p class="flex items-center gap-2 text-sm text-brand-moss"><span class="inline-flex h-2 w-2 animate-pulse rounded-full bg-amber-500"></span>{{ __('Still building. Its URL appears when the deploy is live.') }} <a href="{{ $d['log'] }}" wire:navigate class="font-medium text-brand-sage hover:underline">{{ __('Build log') }}</a></p>
            @elseif ($d['url'])
                <a href="{{ $d['url'] }}" target="_blank" rel="noopener noreferrer" class="flex items-center justify-between gap-3 rounded-lg bg-brand-sand/30 px-3 py-2.5 font-mono text-sm text-brand-forest hover:bg-brand-sand/50 dark:text-brand-sage">
                    <span class="min-w-0 truncate">{{ $d['url'] }}</span>
                    <span class="shrink-0 font-sans text-xs font-medium">{{ __('Open') }} ↗</span>
                </a>
            @endif

            <a href="{{ route('sites.preview-comments', ['server' => $open->server_id, 'site' => $open]) }}" wire:navigate class="flex min-h-11 items-center justify-between border-y border-brand-ink/10 text-sm text-brand-ink hover:bg-brand-sand/20">
                <span>{{ __('Review notes left on this preview') }}</span>
                <x-heroicon-m-chevron-right class="h-4 w-4 text-brand-mist" aria-hidden="true" />
            </a>

            @if ($d['live'] && $canDeploy)
                <div class="space-y-3">
                    <p class="text-sm font-semibold text-brand-ink">{{ __('Before you promote') }}</p>
                    @php $replay = ($latestReplays ?? collect())->get((string) $open->id); @endphp
                    <div class="flex flex-wrap items-center justify-between gap-2 border-b border-brand-ink/10 pb-3 text-sm">
                        <span class="text-brand-ink">
                            @if ($replay && $replay->status === EdgeDeployReplay::STATUS_COMPLETED)
                                {{ __('Replay: :rate% of recent production paths matched · :reg regressions', ['rate' => data_get($replay->summary, 'pass_rate', 0), 'reg' => data_get($replay->summary, 'regressions', 0)]) }}
                            @elseif ($replay && in_array($replay->status, [EdgeDeployReplay::STATUS_QUEUED, EdgeDeployReplay::STATUS_RUNNING], true))
                                {{ __('Replay is running…') }}
                            @elseif ($replay)
                                {{ $replay->error_message ?: __('The last replay failed.') }}
                            @else
                                {{ __('Replay recent production requests against this preview') }}
                            @endif
                        </span>
                        <x-sheet.button type="button" wire:click="queueEdgeDeployReplay(@js((string) $open->id))" wire:loading.attr="disabled" wire:target="queueEdgeDeployReplay">{{ $replay ? __('Run again') : __('Run replay') }}</x-sheet.button>
                    </div>
                    @include('livewire.sites.partials.edge.deploy-contract-panel', [
                        'preview' => $open,
                        'previewIsLive' => true,
                        'deployContractEnabled' => $deployContractEnabled ?? false,
                        'deployContract' => ($deployContracts ?? collect())->get((string) $open->id, []),
                    ])
                    <div class="flex flex-wrap items-center justify-between gap-2 text-sm" x-data="{ pct: {{ $d['pct'] }} }">
                        <span class="text-brand-ink">{{ __('Share of production traffic') }}</span>
                        <span class="flex flex-wrap items-center gap-1.5">
                            @foreach ([0, 5, 10, 25, 50] as $p)
                                <button type="button" wire:click="setPreviewSplit(@js((string) $open->id), {{ $p }})" @class(['rounded-full border px-3 py-1 text-xs', 'border-brand-sage text-brand-sage' => $d['pct'] === $p, 'border-brand-ink/15 text-brand-moss hover:text-brand-ink' => $d['pct'] !== $p])>{{ $p === 0 ? __('Off') : $p.'%' }}</button>
                            @endforeach
                            <input type="number" min="0" max="99" x-model.number="pct" aria-label="{{ __('Other percentage') }}" class="dply-input mt-0 w-16 py-1 text-xs" x-on:change="$wire.setPreviewSplit(@js((string) $open->id), pct)" />
                        </span>
                    </div>
                    <p class="text-xs text-brand-moss">{{ __('Visitors in the split stay on the preview through a cookie. Only one preview can take traffic at a time.') }}</p>
                </div>
            @endif

            @if ($canDeploy)
                <div class="flex flex-wrap items-center justify-between gap-2 border-t border-brand-ink/10 pt-4">
                    <button type="button" x-on:click="$dispatch('close-modal', 'preview-detail')" wire:click="confirmTearDownEdgePreview(@js((string) $open->id))" class="text-xs font-medium text-rose-600 hover:underline dark:text-rose-300">{{ __('Tear down') }}</button>
                    @if ($d['live'])
                        <x-sheet.button type="button" variant="primary" x-on:click="$dispatch('close-modal', 'preview-detail')" wire:click="confirmPromoteEdgePreview(@js((string) $open->id))">{{ __('Promote to production') }}</x-sheet.button>
                    @endif
                </div>
            @endif
        </div>
    @endif
</x-modal>

{{-- Preview a commit or branch --}}
@if ($canDeploy && ! $edgeIsPreviewChild)
    <x-modal name="preview-new" maxWidth="2xl" overlayClass="bg-brand-ink/40" focusable>
        <div class="space-y-4 p-6 sm:p-7">
            <div class="flex items-start justify-between gap-4">
                <div>
                    <h2 class="text-lg font-semibold text-brand-ink">{{ __('Preview a commit or branch') }}</h2>
                    <p class="mt-0.5 text-sm text-brand-moss">{{ __('It gets its own URL. The same commit reuses its preview; a new one gets a new URL.') }}</p>
                </div>
                {!! $close('preview-new') !!}
            </div>
            <form wire:submit.prevent="createAdhocEdgePreview" class="space-y-3">
                <div class="flex gap-2">
                    <input type="text" wire:model="edge_deploy_commit_sha" placeholder="{{ __('Commit SHA, or browse') }}" aria-label="{{ __('Commit SHA') }}" autocomplete="off" spellcheck="false" @disabled($adhocPending) class="dply-input mt-0 min-w-0 flex-1 font-mono text-xs" />
                    <x-sheet.button type="button" wire:click="openEdgeDeployRefPicker" :disabled="$adhocPending">{{ __('Browse') }}</x-sheet.button>
                    <x-sheet.button type="submit" variant="primary" :disabled="$adhocPending" wire:loading.attr="disabled" wire:target="createAdhocEdgePreview">
                        <span wire:loading.remove wire:target="createAdhocEdgePreview">{{ $adhocPending ? __('Building…') : __('Create preview') }}</span>
                        <span wire:loading wire:target="createAdhocEdgePreview">{{ __('Queueing…') }}</span>
                    </x-sheet.button>
                </div>
                @if ($edge_deploy_commit_branch !== null && ! $adhocPending)
                    <p class="flex items-center gap-1.5 text-xs text-brand-moss">
                        {{ __('Will preview the tip of') }} <span class="font-mono font-semibold text-brand-ink">{{ $edge_deploy_commit_branch }}</span>
                        <button type="button" wire:click="$set('edge_deploy_commit_branch', null)" class="text-brand-mist hover:text-brand-ink" aria-label="{{ __('Clear branch') }}">×</button>
                    </p>
                @endif
                @if ($edge_deploy_ref_picker_open)
                    @include('livewire.sites.partials.edge.deploy-ref-picker')
                @endif
            </form>

            @if ($adhocPending)
                @php
                    $pendingPreview = Site::query()->find($edge_adhoc_preview_pending_site_id);
                    $journey = $pendingPreview ? \App\Support\Sites\SiteShowViewData::edgeProvisioningJourney($pendingPreview) : null;
                    $pendingDeployment = $journey['edgeLatestDeployment'] ?? null;
                @endphp
                <div class="overflow-hidden rounded-xl border border-brand-ink/10">
                    @if ($pendingDeployment !== null)
                        @include('livewire.sites.partials.edge.deployment-journey-card', ['deployment' => $pendingDeployment])
                    @else
                        <p class="px-4 py-6 text-center text-xs text-brand-moss">{{ __('Waiting for the build to start…') }}</p>
                    @endif
                </div>
                <p class="text-xs text-brand-moss">
                    {{ ($journey['edgeJourneyIsDone'] ?? false) ? __('Built. Going out to the edge now; the URL appears when it’s safe to open.') : __('Live build output. You can close this; the preview keeps building.') }}
                </p>
                @if (\App\Modules\Edge\Support\FakeEdgeProvision::enabled())
                    <p class="text-xs text-amber-800 dark:text-raw-amber-200">{{ __('Local Fake Edge: the preview URL must resolve to this app (e.g. *.edge.test / *.dply.test via Valet).') }}</p>
                @endif
            @endif
        </div>
    </x-modal>
@endif
