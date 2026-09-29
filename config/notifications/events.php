<?php

/**
 * Keys used by notification subscriptions (channel + target + event).
 * site.* / edge.* events target a Site (an Edge app); account.* events go to
 * the account or org admins directly and take no subscription target.
 *
 * Trimmed to the Edge product on 2026-09-26: the VM-era server, backup,
 * project, serverless, worker-pool and import categories had no publisher left.
 */
return [
    'categories' => [
        'source_control' => [
            'label' => 'Source control notifications',
            'events' => [
                // Account-scoped (routed to the credential's owner directly,
                // not via a server/site subscription target).
                'account.git_token.unhealthy' => 'Git credential expired or rejected (action required)',
            ],
        ],
        'credentials' => [
            'label' => 'Provider credentials',
            'events' => [
                'account.provider_credential.unhealthy' => 'Cloud API token rejected (action required)',
            ],
        ],
        'site' => [
            'label' => 'Site notifications',
            'events' => [
                'site.deployments' => 'Deployments & failing deployments',
                'site.deployment_started' => 'Deployment started',
            ],
        ],
        'site_uptime' => [
            'label' => 'Site uptime monitoring',
            'events' => [
                'site.uptime.down' => 'Down & recovered',
                'site.uptime.degraded' => 'Degraded (slow responses)',
                'site.ssl.expiring' => 'SSL certificate expiring',
            ],
        ],
        'site_errors' => [
            'label' => 'Site error stream notifications',
            'events' => [
                'site.errors.deploy_failed' => 'Deployment failed',
                'site.errors.operation_failed' => 'Site operation failed',
            ],
        ],
        'edge' => [
            'label' => 'Edge notifications',
            'events' => [
                'edge.deploy.succeeded' => 'Edge deploy succeeded',
                'edge.deploy.failed' => 'Edge deploy failed (action required)',
                'edge.deploy.duration_regressed' => 'Edge deploy got noticeably slower',
                'edge.domain.verified' => 'Custom domain verified',
                'edge.domain.failing' => 'Custom domain verification failing (action required)',
                'edge.usage.over_budget' => 'Edge usage over budget (action required)',
                'edge.rum.breach' => 'Real-user metric threshold breached (action required)',
                'edge.workers.failed_jobs' => 'Queue jobs failing (action required)',
                'edge.workers.crashing' => 'Queue workers keep exiting (action required)',
                'edge.database.disk_filling' => 'Database disk over 80% full (action required)',
                'edge.database.connections_high' => 'Database near its connection limit (action required)',
                'edge.database.memory_high' => 'Database near its memory limit (action required)',
                'edge.database.resize_suggested' => 'A different database size is suggested (needs approval)',
                'edge.database.resized' => 'Database resized',
                'edge.database.resize_failed' => 'Database resize failed (action required)',
                'edge.app.resize_suggested' => 'A smaller app size would do',
            ],
        ],
    ],
];
