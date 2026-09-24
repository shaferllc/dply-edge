<div @if ($isInProgress ?? false) wire:poll.2s @endif>
    <section class="border-b border-brand-ink/10 px-5 py-4 sm:px-6">
        @include('livewire.sites.edge.workspace.partials.feature-guide', [
            'docSlug' => 'edge-deploys',
            'what' => __('Ship a new Edge version, watch the build, roll back to a previous artifact, or deploy a specific Git ref — without leaving this page.'),
            'steps' => [
                __('Click Deploy (sidebar) or Redeploy now to build the current production branch at its latest commit.'),
                __('While building, the journey card streams clone / install / build / publish — open a row for full logs.'),
                __('Roll back to a prior successful deploy to make that artifact live again, or Deploy ref for a branch / commit / tag.'),
            ],
            'setupLinks' => [
                [
                    'label' => __('Build settings'),
                    'href' => route('sites.show', ['server' => $server, 'site' => $site, 'section' => 'build']),
                ],
                [
                    'label' => __('Deploy triggers'),
                    'href' => route('sites.show', ['server' => $server, 'site' => $site, 'section' => 'deploy-triggers']),
                ],
            ],
            'tips' => [
                __('Failed builds: fix the repo or Build settings, then Redeploy / Rebuild — Docker runs on the control-plane worker, not your laptop.'),
                __('Rollback republishes a previous artifact; it does not re-run npm.'),
            ],
            'open' => (bool) ($isInProgress ?? false),
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

    @include('livewire.sites.partials.edge.deploys-table', ['compact' => false])
    @include('livewire.partials.confirm-action-modal')
</div>
