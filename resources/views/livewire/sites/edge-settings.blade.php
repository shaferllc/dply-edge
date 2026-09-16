<div class="max-w-7xl mx-auto px-4 py-8 sm:px-6 lg:px-8">
    <div class="lg:grid lg:grid-cols-12 lg:gap-10">
        @include('livewire.sites.settings.partials.sidebar')

        <div class="min-w-0 lg:col-span-9">
            <x-breadcrumb-trail
                :items="$settingsBreadcrumbs"
                :site="$site"
                doc-contextual
                :contextual-doc-slug="$contextualDocSlug"
                class="mb-6"
            />

            {{-- Merged chrome: one outer card, sand identity header, children as strips.
                 Matches BYO site Settings — Overview owns its own identity strip
                 (like general-tab); other sections get the shell sand header. --}}
            <section class="dply-card min-w-0 overflow-hidden p-0">
                @if ($section !== 'general')
                    <x-workspace-panel-head
                        class="border-b border-brand-ink/10"
                        :icon="$sectionHeader['icon']"
                        :title="$sectionHeader['title']"
                        :note="$sectionDescription"
                    >
                        <x-slot:actions>
                            @include('livewire.sites.partials.header-role-badge')
                        </x-slot:actions>
                    </x-workspace-panel-head>
                @endif

                @include('livewire.sites.partials.edge.guardrail-banner')

                @if ($sectionConsoleActionKinds !== [])
                    <div class="border-b border-brand-ink/10">
                        @include('livewire.partials.console-action-banner-static', [
                            'run' => $sectionConsoleActionRun,
                            'kindLabels' => (array) config('console_actions.kinds', []),
                        ])
                    </div>
                @endif

                <div
                    role="tabpanel"
                    id="site-settings-panel"
                    aria-labelledby="site-settings-sidebar"
                    class="min-w-0 [&_.space-y-6]:space-y-0 [&_.dply-card]:my-0 [&_.dply-card]:rounded-none [&_.dply-card]:border-0 [&_.dply-card]:border-b [&_.dply-card]:border-brand-ink/10 [&_.dply-card]:shadow-none [&_.dply-card]:last:border-b-0"
                >
                    @if ($section === 'general')
                        @livewire('sites.edge.workspace.overview', ['server' => $server, 'site' => $site], key('edge-section-overview-'.$site->id))
                    @elseif ($section === 'edge-deploys')
                        @livewire('sites.edge.workspace.deploys', ['server' => $server, 'site' => $site], key('edge-section-deploys-'.$site->id))
                    @elseif ($section === 'edge-build')
                        @livewire('sites.edge.workspace.build', ['server' => $server, 'site' => $site], key('edge-section-build-'.$site->id))
                    @elseif ($section === 'edge-environment')
                        @livewire('sites.edge.workspace.environment', ['server' => $server, 'site' => $site], key('edge-section-environment-'.$site->id))
                    @elseif ($section === 'edge-deploy-triggers')
                        @livewire('sites.edge.workspace.deploy-triggers', ['server' => $server, 'site' => $site], key('edge-section-deploy-triggers-'.$site->id))
                    @elseif ($section === 'edge-delivery')
                        @livewire('sites.edge.workspace.delivery', ['server' => $server, 'site' => $site], key('edge-section-delivery-'.$site->id))
                    @elseif ($section === 'edge-routing')
                        @livewire('sites.edge.workspace.routing', ['server' => $server, 'site' => $site], key('edge-section-routing-'.$site->id))
                    @elseif ($section === 'edge-error-pages')
                        @livewire('sites.edge.workspace.error-pages', ['server' => $server, 'site' => $site], key('edge-section-error-pages-'.$site->id))
                    @elseif ($section === 'edge-bindings')
                        @livewire('sites.edge.workspace.bindings', ['server' => $server, 'site' => $site], key('edge-section-bindings-'.$site->id))
                    @elseif ($section === 'edge-crons')
                        @livewire('sites.edge.workspace.crons', ['server' => $server, 'site' => $site], key('edge-section-crons-'.$site->id))
                    @elseif ($section === 'edge-firewall')
                        @livewire('sites.edge.workspace.firewall', ['server' => $server, 'site' => $site], key('edge-section-firewall-'.$site->id))
                    @elseif ($section === 'edge-bot-protection')
                        @livewire('sites.edge.workspace.bot-protection', ['server' => $server, 'site' => $site], key('edge-section-bot-protection-'.$site->id))
                    @elseif ($section === 'edge-rate-limits')
                        @livewire('sites.edge.workspace.rate-limits', ['server' => $server, 'site' => $site], key('edge-section-rate-limits-'.$site->id))
                    @elseif ($section === 'edge-waiting-room')
                        @livewire('sites.edge.workspace.waiting-room', ['server' => $server, 'site' => $site], key('edge-section-waiting-room-'.$site->id))
                    @elseif ($section === 'edge-forms')
                        @livewire('sites.edge.workspace.forms', ['server' => $server, 'site' => $site], key('edge-section-forms-'.$site->id))
                    @elseif ($section === 'edge-jobs')
                        @livewire('sites.edge.workspace.jobs', ['server' => $server, 'site' => $site], key('edge-section-jobs-'.$site->id))
                    @elseif ($section === 'edge-snippets')
                        @livewire('sites.edge.workspace.snippets', ['server' => $server, 'site' => $site], key('edge-section-snippets-'.$site->id))
                    @elseif ($section === 'edge-load-balancing')
                        @livewire('sites.edge.workspace.load-balancing', ['server' => $server, 'site' => $site], key('edge-section-load-balancing-'.$site->id))
                    @elseif ($section === 'edge-tags')
                        @livewire('sites.edge.workspace.tags', ['server' => $server, 'site' => $site], key('edge-section-tags-'.$site->id))
                    @elseif ($section === 'edge-members')
                        @livewire('sites.edge.workspace.members', ['server' => $server, 'site' => $site], key('edge-section-members-'.$site->id))
                    @elseif ($section === 'edge-alerts')
                        @livewire('sites.edge.workspace.alerts', ['server' => $server, 'site' => $site], key('edge-section-alerts-'.$site->id))
                    @elseif ($section === 'edge-audit')
                        @include('livewire.sites.partials.edge.audit-log')
                    @elseif ($section === 'edge-previews')
                        @livewire('sites.edge.workspace.previews', ['server' => $server, 'site' => $site], key('edge-section-previews-'.$site->id))
                    @elseif ($section === 'edge-billing')
                        @livewire('sites.edge.workspace.billing', ['server' => $server, 'site' => $site], key('edge-section-billing-'.$site->id))
                    @elseif ($section === 'edge-traffic')
                        @livewire('sites.edge.workspace.traffic', ['server' => $server, 'site' => $site], key('edge-section-traffic-'.$site->id))
                    @elseif ($section === 'edge-logs')
                        @livewire('sites.edge.workspace.logs', ['server' => $server, 'site' => $site], key('edge-section-logs-'.$site->id))
                    @elseif ($section === 'danger')
                        @livewire('sites.edge.workspace.danger', ['server' => $server, 'site' => $site], key('edge-section-danger-'.$site->id))
                    @endif
                </div>
            </section>
        </div>
    </div>

    @include('livewire.partials.confirm-action-modal')
</div>
