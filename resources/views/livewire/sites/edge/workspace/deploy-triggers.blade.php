<div>
    <section class="border-b border-brand-ink/10 px-5 py-4 sm:px-6">
        @include('livewire.sites.edge.workspace.partials.feature-guide', [
            'docSlug' => 'edge-deploy-triggers',
            'what' => __('Start Edge deploys without the dashboard — GitHub push/PR webhooks and per-site deploy hook URLs for CMS publish flows.'),
            'steps' => [
                __('Enable the GitHub auto-deploy webhook (linked account under Source control).'),
                __('Optionally create a deploy hook, copy the URL once, and POST to it from your CMS.'),
                __('Keep Deploy on push enabled under Build for production branch deploys.'),
            ],
            'setupLinks' => [
                [
                    'label' => __('Build settings'),
                    'href' => route('sites.show', ['server' => $server, 'site' => $site, 'section' => 'build']),
                ],
                [
                    'label' => __('Previews'),
                    'href' => route('sites.show', ['server' => $server, 'site' => $site, 'section' => 'previews']),
                ],
            ],
            'tips' => [
                __('PR deploys show a Check Run and preview URL comment on GitHub.'),
                __('Hook URLs are shown once at create — revoke and recreate if lost.'),
            ],
        ])
    </section>

    @include('livewire.sites.partials.edge.deploy-triggers')
    @include('livewire.partials.confirm-action-modal')
</div>
