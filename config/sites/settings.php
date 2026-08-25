<?php

return [
    /**
     * Default group for site files under VM-backed PHP sites (nginx + PHP-FPM on Debian/Ubuntu).
     * Used when resetting ownership to :effective_user:web_group.
     */
    'vm_site_file_web_group' => env('DPLY_VM_SITE_FILE_WEB_GROUP', 'www-data'),

    'workspace_tabs' => [
        'general' => ['label' => 'General'],
        'settings' => ['label' => 'Settings'],
        'routing' => ['label' => 'Routing'],
        'backends' => ['label' => 'Backends'],
        'dns' => ['label' => 'DNS'],
        'certificates' => ['label' => 'Certificates'],
        'deploy' => ['label' => 'Deploy'],
        'repository' => ['label' => 'Repository'],
        'runtime' => ['label' => 'Runtime'],
        'system-user' => ['label' => 'System user'],
        'worker-fleet' => ['label' => 'Worker Servers'],
        'laravel-stack' => ['label' => 'Laravel'],
        'rails-stack' => ['label' => 'Rails'],
        'wordpress' => ['label' => 'WordPress'],
        'environment' => ['label' => 'Environment'],
        // Serverless-only leaves split out of Runtime and Overview.
        'access' => ['label' => 'Access'],
        'data' => ['label' => 'Data'],
        'assets' => ['label' => 'Assets'],
        'resources' => ['label' => 'Resources'],
        'logs' => ['label' => 'Logs'],
        'platform' => ['label' => 'Platform'],
        'notifications' => ['label' => 'Notifications'],
        'basic-auth' => ['label' => 'Authentication'],
        'cli' => ['label' => 'CLI'],
        'danger' => ['label' => 'Danger zone'],
        'edge-deploys' => ['label' => 'Deploys'],
        'edge-domains' => ['label' => 'Routing'],
        'edge-build' => ['label' => 'Build'],
        'edge-deploy-triggers' => ['label' => 'Deploy triggers'],
        'edge-environment' => ['label' => 'Environment'],
        'edge-delivery' => ['label' => 'Delivery'],
        'edge-bindings' => ['label' => 'Bindings'],
        'edge-routing' => ['label' => 'Routing'],
        'edge-error-pages' => ['label' => 'Error pages'],
        'edge-crons' => ['label' => 'Crons'],
        'edge-firewall' => ['label' => 'Firewall'],
        'edge-bot-protection' => ['label' => 'Bot protection'],
        'edge-rate-limits' => ['label' => 'Rate limits'],
        'edge-waiting-room' => ['label' => 'Waiting room'],
        'edge-forms' => ['label' => 'Forms'],
        'edge-jobs' => ['label' => 'Jobs'],
        'edge-snippets' => ['label' => 'Snippets'],
        'edge-tags' => ['label' => 'Tags'],
        'edge-members' => ['label' => 'Members'],
        'edge-alerts' => ['label' => 'Alerts'],
        'edge-audit' => ['label' => 'Audit log'],
        'edge-previews' => ['label' => 'Previews'],
        'edge-traffic' => ['label' => 'Traffic & analytics'],
        'edge-billing' => ['label' => 'Billing & usage'],
        'edge-logs' => ['label' => 'Build & deploy logs'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Sidebar group labels
    |--------------------------------------------------------------------------
    | Display labels for the `group` keys assigned in {@see App\Support\SiteSettingsSidebar}.
    | The sidebar partial renders a heading whenever the group changes; only groups
    | that have at least one visible item produce a heading.
    */
    /*
     * Sidebar group labels, keyed by the `group` on each nav item.
     *
     * The first five are the Edge workspace's own grouping (2026-08-25). An
     * edge site has 26 sections and the sidebar used to render them as one
     * flat wall where "Deploys" and "Waiting room" carried equal weight. They
     * are grouped by WHY you opened a section, not by which subsystem it
     * configures — which is why Logs sits under Traffic (you open it when
     * something looks wrong) rather than next to Build.
     *
     * The rest are the legacy VM/custom groups, still used by customItems().
     */
    'nav_groups' => [
        'ship' => 'Ship',
        'traffic' => 'Traffic',
        'protect' => 'Protect',
        'extend' => 'Extend',
        'manage' => 'Manage',

        'general' => 'General',
        'networking' => 'Networking',
        'site' => 'Site',
        'deploy' => 'Deploy',
        'runtime' => 'Runtime',
        'observability' => 'Observability',
        'background' => 'Background',
        'access' => 'Access',
        'danger' => 'Danger',
    ],
];
