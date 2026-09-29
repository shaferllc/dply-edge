<div>
    @unless ($edgeIsPreviewChild)
        <section class="border-b border-brand-ink/10 px-5 py-4 sm:px-6">
            @include('livewire.sites.edge.workspace.partials.feature-guide', [
                'docSlug' => 'preview-deployments',
                'what' => __('Spin up PR and ad-hoc preview URLs for this production site — review, promote to prod, or split traffic without changing main.'),
                'steps' => [
                    __('Open a pull request so the GitHub webhook builds a preview, or choose Preview a commit or branch.'),
                    __('Open the preview URL when the build finishes (Fake Edge needs a local *.test hostname).'),
                    __('Click a preview to replay production traffic against it, send it a share of traffic, promote it, or tear it down.'),
                ],
                'setupLinks' => [
                    [
                        'label' => __('Deploy triggers / webhook'),
                        'href' => route('sites.show', ['server' => $server, 'site' => $site, 'section' => 'deploy-triggers']),
                    ],
                ],
                'tips' => [
                    __('Same commit SHA reuses the preview; a new SHA gets its own URL.'),
                    __('Protection locks preview URLs and the live site. The comment widget stays on preview URLs only.'),
                    __('Previews build with this app’s environment variables and linked secrets. PRs from forks don’t get a preview.'),
                    __('Run checks before promoting. Shadow replay needs production traffic from the last hour; with none, the check passes.'),
                ],
            ])
        </section>

    @endunless

    @include('livewire.sites.partials.edge.previews', [
        'latestReplays' => $latestReplays ?? collect(),
        'deployReplayEnabled' => $deployReplayEnabled ?? false,
        'deployContractEnabled' => $deployContractEnabled ?? false,
        'deployContracts' => $deployContracts ?? collect(),
    ])

    @unless ($edgeIsPreviewChild)
        @include('livewire.sites.partials.edge.preview-settings')

    @endunless

    @include('livewire.partials.confirm-action-modal')
</div>
