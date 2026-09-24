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
        'deploys' => ['label' => 'Deploys'],
        'domains' => ['label' => 'Routing'],
        'build' => ['label' => 'Build'],
        'deploy-triggers' => ['label' => 'Deploy triggers'],
        'environment' => ['label' => 'Environment'],
        'delivery' => ['label' => 'Delivery'],
        'bindings' => ['label' => 'Bindings'],
        'routing' => ['label' => 'Routing'],
        'error-pages' => ['label' => 'Error pages'],
        'crons' => ['label' => 'Crons'],
        'resources' => ['label' => 'Resources'],
        'security' => ['label' => 'Security'],
        'firewall' => ['label' => 'Firewall'],
        'bot-protection' => ['label' => 'Bot protection'],
        'rate-limits' => ['label' => 'Rate limits'],
        'waiting-room' => ['label' => 'Waiting room'],
        'forms' => ['label' => 'Forms'],
        'jobs' => ['label' => 'Jobs'],
        'snippets' => ['label' => 'Snippets'],
        'tags' => ['label' => 'Tags'],
        'container' => ['label' => 'Container'],
        'members' => ['label' => 'Members'],
        'alerts' => ['label' => 'Alerts'],
        'audit' => ['label' => 'Audit log'],
        'previews' => ['label' => 'Previews'],
        'traffic' => ['label' => 'Traffic & analytics'],
        'billing' => ['label' => 'Billing & usage'],
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
