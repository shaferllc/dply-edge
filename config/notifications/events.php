<?php

/**
 * Keys used by notification subscriptions (channel + target + event).
 * Prefix determines compatible target: server.* → Server, site.* / backup.* → Site.
 *
 * Per-systemd-unit keys (server target) are created from the Services workspace; they match
 * {@see ServerSystemdServiceNotificationKeys::isValidDynamicEventKey} and are
 * accepted by bulk-assign validation even though they are not listed below.
 */
return [
    'categories' => [
        'server' => [
            'label' => 'Server notifications',
            'events' => [
                'server.automatic_updates' => 'Automatic updates',
                'server.ssh_login' => 'SSH login',
                'server.insights_alerts' => 'Insights alerts',
                'server.monitoring' => 'Server monitoring',
                'server.shared_host_alerts' => 'Shared host contention & budget alerts',
                'server.container_launch.completed' => 'Container app deploy ready',
                'server.container_launch.failed' => 'Container app deploy failed (action required)',
                'server.provisioned' => 'Server provisioned',
                'server.provision_failed' => 'Server provisioning failed (action required)',
            ],
        ],
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
        'system_user' => [
            'label' => 'System user notifications',
            'events' => [
                'server.system_user.created' => 'System user created',
                'server.system_user.updated' => 'System user updated',
                'server.system_user.removed' => 'System user removed',
            ],
        ],
        'ssh_key' => [
            'label' => 'SSH key notifications',
            'events' => [
                'server.ssh_key.created' => 'SSH key added',
                'server.ssh_key.removed' => 'SSH key removed',
            ],
        ],
        'cert_inventory' => [
            'label' => 'Certificate notifications',
            'events' => [
                'server.cert.renewed' => 'Certificate issued / renewed',
                'server.cert.renewal_failed' => 'Certificate issue / renewal failed (action required)',
            ],
        ],
        'security_digest' => [
            'label' => 'Security digest notifications',
            'events' => [
                'server.security_digest.critical_finding' => 'Critical security finding',
                'server.security_digest.warning_finding' => 'Security warning detected',
                'server.security_digest.posture_cleared' => 'Security posture returned to healthy',
            ],
        ],
        'release_hygiene' => [
            'label' => 'Release hygiene notifications',
            'events' => [
                'server.release_hygiene.critical_finding' => 'Critical release / disk pressure',
                'server.release_hygiene.warning_finding' => 'Release hygiene warning detected',
                'server.release_hygiene.posture_cleared' => 'Release hygiene returned to healthy',
            ],
        ],
        'health' => [
            'label' => 'Health notifications',
            'events' => [
                'server.health.critical_finding' => 'Critical health alert',
                'server.health.warning_finding' => 'Health warning detected',
                'server.health.posture_cleared' => 'Health returned to healthy',
            ],
        ],
        'errors' => [
            'label' => 'Error stream notifications',
            'events' => [
                'server.errors.deploy_failed' => 'Deployment failed',
                'server.errors.operation_failed' => 'Server operation failed',
            ],
        ],
        'logs' => [
            'label' => 'dply Logs notifications',
            'events' => [
                'server.logs.alert_triggered' => 'Log alert triggered',
            ],
        ],
        'queue' => [
            'label' => 'dply Queue notifications',
            'events' => [
                // A queue's price follows the site it serves, so it can change
                // without anyone touching the queue. Telling the customer is
                // what keeps that from arriving as a surprise on an invoice.
                'queue.namespace.became_billable' => 'Queue namespace started billing',
                'queue.namespace.became_free' => 'Queue namespace became free',
            ],
        ],
        'deploy_window' => [
            'label' => 'Deploy window notifications',
            'events' => [
                'server.deploy_window.deploy_blocked' => 'Deploy blocked by a deny window',
                'server.deploy_window.policy_enabled' => 'Deploy window policy enabled',
                'server.deploy_window.policy_disabled' => 'Deploy window policy disabled',
            ],
        ],
        'maintenance' => [
            'label' => 'Maintenance window notifications',
            'events' => [
                'server.maintenance.enabled' => 'Visitor maintenance enabled',
                'server.maintenance.disabled' => 'Visitor maintenance ended',
                'server.maintenance.auto_expired' => 'Visitor maintenance auto-ended (window expired)',
            ],
        ],
        'patches' => [
            'label' => 'Patch / update notifications',
            'events' => [
                'server.patches.updates_applied' => 'Updates applied (apt upgrade)',
                'server.patches.dist_upgrade_applied' => 'Dist-upgrade applied',
                'server.patches.apply_failed' => 'Update apply failed (action required)',
                'server.patches.reboot_completed' => 'Server rebooted',
                'server.patches.auto_updates_enabled' => 'Automatic updates enabled',
                'server.patches.auto_updates_disabled' => 'Automatic updates disabled',
            ],
        ],
        'server_database' => [
            'label' => 'Database notifications',
            'events' => [
                'server.database.created' => 'Database created',
                'server.database.removed' => 'Database removed',
                'server.database.engine_installed' => 'Database engine installed',
                'server.database.engine_removed' => 'Database engine removed',
                'server.database.user_created' => 'Database user created',
                'server.database.user_removed' => 'Database user removed',
                'server.database.credential_shared' => 'Database credentials shared',
            ],
        ],
        'webserver' => [
            'label' => 'Webserver notifications',
            'events' => [
                'server.webserver.engine_switched' => 'Webserver engine switched',
                'server.webserver.engine_switch_failed' => 'Webserver switch failed (action required)',
                'server.webserver.switch_reverted' => 'Webserver switch reverted',
                'server.webserver.config_saved' => 'Webserver config saved',
            ],
        ],
        'server_backup' => [
            'label' => 'Server backup notifications',
            'events' => [
                'server.backup.run_started' => 'Backup run started',
                'server.backup.completed' => 'Backup completed',
                'server.backup.failed' => 'Backup failed (action required)',
                'server.backup.deleted' => 'Backup deleted',
                'server.backup.schedule_created' => 'Backup schedule created',
                'server.backup.schedule_updated' => 'Backup schedule updated',
                'server.backup.schedule_deleted' => 'Backup schedule deleted',
            ],
        ],
        'snapshot' => [
            'label' => 'Snapshot notifications',
            'events' => [
                'server.snapshot.created' => 'Snapshot created',
                'server.snapshot.restored' => 'Snapshot restored',
                'server.snapshot.deleted' => 'Snapshot deleted',
            ],
        ],
        'load_balancer' => [
            'label' => 'Load balancer notifications',
            'events' => [
                'server.load_balancer.created' => 'Load balancer created',
                'server.load_balancer.deleted' => 'Load balancer deleted',
                'server.load_balancer.target_added' => 'Load balancer target added',
                'server.load_balancer.target_removed' => 'Load balancer target removed',
            ],
        ],
        'firewall_rule' => [
            'label' => 'Firewall notifications',
            'events' => [
                'server.firewall_rule.created' => 'Firewall rule created',
                'server.firewall_rule.updated' => 'Firewall rule updated',
                'server.firewall_rule.deleted' => 'Firewall rule deleted',
                'server.firewall_rule.applied' => 'Firewall rules applied to host',
            ],
        ],
        'networking' => [
            'label' => 'Networking notifications',
            'events' => [
                'server.networking.db_access_enabled' => 'Database remote access enabled',
                'server.networking.db_access_disabled' => 'Database remote access disabled',
                'server.networking.cache_exposed' => 'Cache exposed to network',
                'server.networking.cache_locked_down' => 'Cache locked down',
                'server.networking.network_created' => 'Private network created',
                'server.networking.network_attached' => 'Server attached to network',
                'server.networking.network_detached' => 'Server detached from network',
                'server.networking.route_added' => 'Network route added',
                'server.networking.route_removed' => 'Network route removed',
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
            ],
        ],
        'serverless' => [
            'label' => 'Serverless notifications',
            'events' => [
                // Asset delivery never stops when a site goes over — the
                // overage is billed and surfaced. This is the surfacing.
                'serverless.assets.over_budget' => 'Serverless asset usage over the included allowance',

                // Account-scoped (routed to org admins directly, not via a
                // server/site subscription target) because the subject is a
                // site that did not exist a moment before the event — nobody
                // could have subscribed to it. Fires only when an API token is
                // the actor; a person creating one in the dashboard was there
                // for it.
                'account.serverless.function_created' => 'Function created from the CLI (provisions billable infrastructure)',
                'account.cloud.site_created' => 'Cloud app created from the CLI (provisions billable infrastructure)',
            ],
        ],
        'backup' => [
            'label' => 'Backup notifications',
            'events' => [
                'backup.database' => 'Database backups',
                'backup.site_files' => 'Site file backups',
            ],
        ],
        'project' => [
            'label' => 'Project notifications',
            'events' => [
                'project.deployments' => 'Project deploy batches',
                'project.health' => 'Project health alerts',
                'project.activity' => 'Project activity summaries',
            ],
        ],
        'worker_pool' => [
            'label' => 'Worker pool notifications',
            'events' => [
                'worker_pool.scale_started' => 'Worker pool scaling started',
                'worker_pool.scaled' => 'Worker pool scaled',
                'worker_pool.scale_failed' => 'Worker pool scaling failed (action required)',
            ],
        ],
        'import' => [
            'label' => 'Import & migration notifications',
            'events' => [
                'import.migration.cutover_ready' => 'Migration ready for cutover (action required)',
                'import.migration.step_failed' => 'Migration step failed (action required)',
                'import.migration.cutover_complete' => 'Migration cutover complete',
                'import.migration.aborted' => 'Migration aborted',
                'import.migration.paused_nudge' => 'Migration paused — auto-revoke in <96h (action required)',
            ],
        ],
    ],
];
