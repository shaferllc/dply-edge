<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Resolve an audit log action string into UI metadata: family bucket,
 * human label, icon name, and tone.
 *
 * Why this exists: the activity feed renders dozens of distinct action
 * strings (`server.created`, `site.env.var_added`, `billing.subscription_canceled`, …).
 * Building the icon + label + color inline in the blade with 100+ match
 * arms makes the view unreadable and impossible to keep consistent. This
 * class owns the mapping in one place so the activity view stays a thin
 * presenter.
 *
 * Resolution order:
 *   1. Exact-action overrides (`server.deleted`, `billing.checkout_started`, …)
 *      where the default family/icon would be misleading.
 *   2. Prefix patterns (`server.firewall.*`, `site.env.*`, …) so a new sub-
 *      action under an existing family inherits the family's look without
 *      another entry here.
 *   3. Top-level family default (`server.*`, `site.*`, …).
 *   4. Last-resort fallback for actions outside known families.
 */
final class AuditActionMeta
{
    /** @var list<array{id: string, label: string, icon: string}> */
    public const FAMILIES = [
        ['id' => 'site', 'label' => 'Apps', 'icon' => 'heroicon-o-globe-alt'],
        ['id' => 'edge', 'label' => 'Edge', 'icon' => 'heroicon-o-bolt'],
        ['id' => 'resources', 'label' => 'Resources', 'icon' => 'heroicon-o-circle-stack'],
        ['id' => 'team', 'label' => 'Team', 'icon' => 'heroicon-o-user-group'],
        ['id' => 'billing', 'label' => 'Billing', 'icon' => 'heroicon-o-credit-card'],
        ['id' => 'security', 'label' => 'Security', 'icon' => 'heroicon-o-shield-check'],
        ['id' => 'org', 'label' => 'Organization', 'icon' => 'heroicon-o-building-office-2'],
        ['id' => 'other', 'label' => 'Other', 'icon' => 'heroicon-o-ellipsis-horizontal-circle'],
    ];

    /**
     * Look up { family, label, icon, tone } for an audit action string.
     *
     * @return array{family: string, label: string, icon: string, tone: string}
     */
    public static function meta(string $action): array
    {
        $exact = self::exactMap()[$action] ?? null;
        if ($exact !== null) {
            return $exact + ['family' => self::family($action)];
        }

        $prefix = self::prefixMatch($action);
        if ($prefix !== null) {
            return $prefix + ['family' => self::family($action)];
        }

        return [
            'family' => self::family($action),
            'label' => self::humanize($action),
            'icon' => self::familyIcon(self::family($action)),
            'tone' => 'neutral',
        ];
    }

    public static function family(string $action): string
    {
        // Server, project, backup, insight, import and queue-worker actions
        // came from the removed VM products; their historic rows land in
        // `other` (the exact/prefix maps still label them).
        return match (true) {
            str_starts_with($action, 'site.edge.') => 'edge',
            str_starts_with($action, 'site.') => 'site',
            str_starts_with($action, 'database.'),
            str_starts_with($action, 'queue.') => 'resources',
            str_starts_with($action, 'team.') => 'team',
            str_starts_with($action, 'billing.') => 'billing',
            str_starts_with($action, 'api_token.'),
            str_starts_with($action, 'invitation.'),
            str_starts_with($action, 'member.'),
            str_starts_with($action, 'notification_channel.'),
            str_starts_with($action, 'user.'),
            str_starts_with($action, 'credential.'),
            str_starts_with($action, 'impersonation.'),
            str_starts_with($action, 'support.') => 'security',
            str_starts_with($action, 'organization.') => 'org',
            default => 'other',
        };
    }

    private static function familyIcon(string $family): string
    {
        foreach (self::FAMILIES as $f) {
            if ($f['id'] === $family) {
                return $f['icon'];
            }
        }

        return 'heroicon-o-ellipsis-horizontal-circle';
    }

    /**
     * Pretty-print the raw action key when no override is present:
     *   `site.env.var_added`  →  `Site env var added`
     */
    private static function humanize(string $action): string
    {
        $clean = str_replace(['.', '_'], ' ', $action);

        return ucfirst($clean);
    }

    /**
     * Exact-action overrides — used when the default family/icon needs
     * specifics (delete vs. create, secret vs. plain config, etc.).
     *
     * @return array<string, array{label: string, icon: string, tone: string}>
     */
    private static function exactMap(): array
    {
        return [
            // Servers
            'server.created' => ['label' => 'Server created', 'icon' => 'heroicon-o-plus-circle', 'tone' => 'success'],
            'server.deleted' => ['label' => 'Server deleted', 'icon' => 'heroicon-o-trash', 'tone' => 'danger'],
            'server.cloned' => ['label' => 'Server cloned', 'icon' => 'heroicon-o-document-duplicate', 'tone' => 'info'],
            'server.clone.droplet_created' => ['label' => 'Clone droplet created', 'icon' => 'heroicon-o-cube-transparent', 'tone' => 'info'],
            'server.clone.failed' => ['label' => 'Clone failed', 'icon' => 'heroicon-o-exclamation-triangle', 'tone' => 'danger'],
            'server.deletion_scheduled' => ['label' => 'Server removal scheduled', 'icon' => 'heroicon-o-clock', 'tone' => 'warning'],
            'server.deletion_schedule_cancelled' => ['label' => 'Scheduled removal cancelled', 'icon' => 'heroicon-o-arrow-uturn-left', 'tone' => 'info'],
            'server.timezone_updated' => ['label' => 'Server timezone updated', 'icon' => 'heroicon-o-clock', 'tone' => 'info'],
            'server.settings_connection_updated' => ['label' => 'Connection settings updated', 'icon' => 'heroicon-o-adjustments-horizontal', 'tone' => 'info'],
            'server.ssh_access_repaired' => ['label' => 'SSH access repaired', 'icon' => 'heroicon-o-wrench-screwdriver', 'tone' => 'success'],
            'server.ssh_login_detected' => ['label' => 'SSH login detected', 'icon' => 'heroicon-o-finger-print', 'tone' => 'warning'],
            'server.webhook.test_dispatched' => ['label' => 'Webhook test dispatched', 'icon' => 'heroicon-o-paper-airplane', 'tone' => 'info'],
            'server.webhook.delivery_resent' => ['label' => 'Webhook delivery resent', 'icon' => 'heroicon-o-arrow-path', 'tone' => 'info'],
            'server.maintenance.enabled' => ['label' => 'Server maintenance enabled', 'icon' => 'heroicon-o-wrench', 'tone' => 'warning'],
            'server.maintenance.disabled' => ['label' => 'Server maintenance ended', 'icon' => 'heroicon-o-play-circle', 'tone' => 'success'],

            // Sites
            'support.access.start' => ['label' => 'dply support opened access to your data', 'icon' => 'heroicon-o-lifebuoy', 'tone' => 'warning'],
            'support.database.query' => ['label' => 'dply support queried a database', 'icon' => 'heroicon-o-lifebuoy', 'tone' => 'warning'],
            'support.database.verify' => ['label' => 'dply support ran a backup restore check', 'icon' => 'heroicon-o-lifebuoy', 'tone' => 'info'],
            'support.resource.sleep' => ['label' => 'dply support put a resource to sleep', 'icon' => 'heroicon-o-lifebuoy', 'tone' => 'info'],
            'site.suspended' => ['label' => 'Site suspended', 'icon' => 'heroicon-o-pause-circle', 'tone' => 'warning'],
            'site.resumed' => ['label' => 'Site resumed', 'icon' => 'heroicon-o-play-circle', 'tone' => 'success'],
            'site.clone_started' => ['label' => 'Site clone started', 'icon' => 'heroicon-o-document-duplicate', 'tone' => 'info'],
            'site.repository_updated' => ['label' => 'Repository settings updated', 'icon' => 'heroicon-o-code-bracket-square', 'tone' => 'info'],
            'site.php_settings_updated' => ['label' => 'PHP settings updated', 'icon' => 'heroicon-o-cog-6-tooth', 'tone' => 'info'],
            'site.ssl.issuance_queued' => ['label' => 'SSL issuance queued', 'icon' => 'heroicon-o-lock-closed', 'tone' => 'info'],
            'site.ssl.issued' => ['label' => 'SSL certificate issued', 'icon' => 'heroicon-o-lock-closed', 'tone' => 'success'],
            'site.ssl.failed' => ['label' => 'SSL certificate failed', 'icon' => 'heroicon-o-lock-open', 'tone' => 'danger'],
            'site.deploy.success' => ['label' => 'Deploy succeeded', 'icon' => 'heroicon-o-rocket-launch', 'tone' => 'success'],
            'site.deploy.failed' => ['label' => 'Deploy failed', 'icon' => 'heroicon-o-exclamation-triangle', 'tone' => 'danger'],
            'site.deploy.skipped' => ['label' => 'Deploy skipped', 'icon' => 'heroicon-o-pause-circle', 'tone' => 'warning'],
            'site.deploy.finished' => ['label' => 'Deploy finished', 'icon' => 'heroicon-o-flag', 'tone' => 'info'],
            'site.deploy.ephemeral_credential_provisioned' => ['label' => 'Ephemeral deploy key issued', 'icon' => 'heroicon-o-key', 'tone' => 'info'],
            'site.deploy.ephemeral_credential_revoked' => ['label' => 'Ephemeral deploy key revoked', 'icon' => 'heroicon-o-key', 'tone' => 'info'],
            'site.cloud.deploy.success' => ['label' => 'Cloud deploy succeeded', 'icon' => 'heroicon-o-cube', 'tone' => 'success'],
            'site.cloud.deploy.failed' => ['label' => 'Cloud deploy failed', 'icon' => 'heroicon-o-cube', 'tone' => 'danger'],
            'site.domain.added' => ['label' => 'Domain added', 'icon' => 'heroicon-o-plus', 'tone' => 'success'],
            'site.domain.removed' => ['label' => 'Domain removed', 'icon' => 'heroicon-o-minus', 'tone' => 'danger'],
            'site.domain.updated' => ['label' => 'Domain updated', 'icon' => 'heroicon-o-pencil-square', 'tone' => 'info'],
            'site.env.var_added' => ['label' => 'Env var added', 'icon' => 'heroicon-o-plus', 'tone' => 'success'],
            'site.env.var_updated' => ['label' => 'Env var updated', 'icon' => 'heroicon-o-pencil-square', 'tone' => 'info'],
            'site.env.var_removed' => ['label' => 'Env var removed', 'icon' => 'heroicon-o-minus', 'tone' => 'danger'],
            'site.env.bulk_imported' => ['label' => 'Env vars bulk imported', 'icon' => 'heroicon-o-arrow-down-tray', 'tone' => 'info'],
            'site.deploy.webhook_queued' => ['label' => 'Deploy webhook queued', 'icon' => 'heroicon-o-rocket-launch', 'tone' => 'info'],
            'site.webhook.secret_rotated' => ['label' => 'Webhook secret rotated', 'icon' => 'heroicon-o-key', 'tone' => 'warning'],
            'site.integration_webhook.created' => ['label' => 'Integration webhook created', 'icon' => 'heroicon-o-link', 'tone' => 'success'],
            'site.integration_webhook.deleted' => ['label' => 'Integration webhook deleted', 'icon' => 'heroicon-o-link-slash', 'tone' => 'danger'],
            'site.notifications.subscriptions_updated' => ['label' => 'Notification subscriptions updated', 'icon' => 'heroicon-o-bell', 'tone' => 'info'],
            'site.webserver_config.applied' => ['label' => 'Webserver config applied', 'icon' => 'heroicon-o-document-check', 'tone' => 'success'],
            'site.webserver_config.restored' => ['label' => 'Webserver config restored', 'icon' => 'heroicon-o-arrow-uturn-left', 'tone' => 'info'],

            // Edge
            'site.edge.created' => ['label' => 'Edge site created', 'icon' => 'heroicon-o-plus-circle', 'tone' => 'success'],
            'site.edge.deleted' => ['label' => 'Edge site deleted', 'icon' => 'heroicon-o-trash', 'tone' => 'danger'],
            'site.edge.deletion_scheduled' => ['label' => 'Edge site deletion scheduled', 'icon' => 'heroicon-o-clock', 'tone' => 'warning'],
            'site.edge.deployment.cancelled' => ['label' => 'Edge deployment cancelled', 'icon' => 'heroicon-o-x-circle', 'tone' => 'warning'],
            'site.edge.preview.promoted' => ['label' => 'Preview promoted', 'icon' => 'heroicon-o-arrow-up-circle', 'tone' => 'success'],
            'site.deploy_contract.run' => ['label' => 'Deploy contract run', 'icon' => 'heroicon-o-clipboard-document-check', 'tone' => 'neutral'],
            'site.deploy_contract.waived' => ['label' => 'Deploy contract waived', 'icon' => 'heroicon-o-exclamation-triangle', 'tone' => 'warning'],
            'site.edge.deploy_hook.created' => ['label' => 'Deploy hook created', 'icon' => 'heroicon-o-link', 'tone' => 'success'],
            'site.edge.deploy_hook.revoked' => ['label' => 'Deploy hook revoked', 'icon' => 'heroicon-o-link-slash', 'tone' => 'danger'],
            'site.edge.cache.purged_by_tag' => ['label' => 'Edge cache purged by tag', 'icon' => 'heroicon-o-arrow-path', 'tone' => 'info'],
            'site.edge.cache.purge_paths' => ['label' => 'Edge cache purged (paths)', 'icon' => 'heroicon-o-arrow-path', 'tone' => 'info'],
            'site.edge.cache.purge_tag' => ['label' => 'Edge cache purge requested', 'icon' => 'heroicon-o-arrow-path', 'tone' => 'info'],
            'site.edge.images.saved' => ['label' => 'Edge images config saved', 'icon' => 'heroicon-o-photo', 'tone' => 'info'],
            'site.edge.images.disabled' => ['label' => 'Edge images disabled', 'icon' => 'heroicon-o-photo', 'tone' => 'warning'],
            'site.edge.images.secret_rotated' => ['label' => 'Edge images secret rotated', 'icon' => 'heroicon-o-key', 'tone' => 'warning'],
            'site.edge.origin.updated' => ['label' => 'Edge origin updated', 'icon' => 'heroicon-o-server', 'tone' => 'info'],
            'site.edge.origin.secret_rotated' => ['label' => 'Edge origin secret rotated', 'icon' => 'heroicon-o-key', 'tone' => 'warning'],
            'site.edge.preview_protection.updated' => ['label' => 'Preview protection updated', 'icon' => 'heroicon-o-shield-check', 'tone' => 'info'],
            'site.edge.comment_widget.enabled' => ['label' => 'Comment widget enabled', 'icon' => 'heroicon-o-chat-bubble-bottom-center-text', 'tone' => 'success'],
            'site.edge.comment_widget.disabled' => ['label' => 'Comment widget disabled', 'icon' => 'heroicon-o-chat-bubble-bottom-center-text', 'tone' => 'warning'],
            'site.edge.converted_to_hybrid' => ['label' => 'Site converted to hybrid', 'icon' => 'heroicon-o-arrows-right-left', 'tone' => 'info'],

            // Projects
            'project.deploy.queued' => ['label' => 'Project deploy queued', 'icon' => 'heroicon-o-rocket-launch', 'tone' => 'info'],
            'project.server_attached' => ['label' => 'Server attached to project', 'icon' => 'heroicon-o-link', 'tone' => 'success'],
            'project.server_detached' => ['label' => 'Server detached from project', 'icon' => 'heroicon-o-link-slash', 'tone' => 'warning'],
            'project.site_attached' => ['label' => 'Site attached to project', 'icon' => 'heroicon-o-link', 'tone' => 'success'],
            'project.site_detached' => ['label' => 'Site detached from project', 'icon' => 'heroicon-o-link-slash', 'tone' => 'warning'],
            'project.member_updated' => ['label' => 'Project member updated', 'icon' => 'heroicon-o-user-circle', 'tone' => 'info'],
            'project.member_removed' => ['label' => 'Project member removed', 'icon' => 'heroicon-o-user-minus', 'tone' => 'danger'],
            'project.environment_added' => ['label' => 'Project environment added', 'icon' => 'heroicon-o-plus', 'tone' => 'success'],
            'project.environment_removed' => ['label' => 'Project environment removed', 'icon' => 'heroicon-o-minus', 'tone' => 'danger'],

            // Team
            'team.created' => ['label' => 'Team created', 'icon' => 'heroicon-o-user-group', 'tone' => 'success'],
            'team.updated' => ['label' => 'Team renamed', 'icon' => 'heroicon-o-pencil-square', 'tone' => 'info'],
            'team.deleted' => ['label' => 'Team deleted', 'icon' => 'heroicon-o-trash', 'tone' => 'danger'],
            'team.member_added' => ['label' => 'Team member added', 'icon' => 'heroicon-o-user-plus', 'tone' => 'success'],
            'team.member_removed' => ['label' => 'Team member removed', 'icon' => 'heroicon-o-user-minus', 'tone' => 'danger'],

            // Security
            'api_token.created' => ['label' => 'API token created', 'icon' => 'heroicon-o-key', 'tone' => 'success'],
            'api_token.revoked' => ['label' => 'API token revoked', 'icon' => 'heroicon-o-key', 'tone' => 'danger'],
            'api_token.device_authorized' => ['label' => 'Device authorized', 'icon' => 'heroicon-o-device-phone-mobile', 'tone' => 'success'],
            'invitation.sent' => ['label' => 'Invitation sent', 'icon' => 'heroicon-o-envelope', 'tone' => 'info'],
            'invitation.cancelled' => ['label' => 'Invitation cancelled', 'icon' => 'heroicon-o-no-symbol', 'tone' => 'warning'],
            'member.role_changed' => ['label' => 'Member role changed', 'icon' => 'heroicon-o-user-circle', 'tone' => 'info'],
            'member.removed' => ['label' => 'Member removed', 'icon' => 'heroicon-o-user-minus', 'tone' => 'danger'],
            'member.left' => ['label' => 'Member left', 'icon' => 'heroicon-o-arrow-left-start-on-rectangle', 'tone' => 'warning'],
            'organization.ownership_transferred' => ['label' => 'Ownership transferred', 'icon' => 'heroicon-o-key', 'tone' => 'warning'],
            'notification_channel.created' => ['label' => 'Notification channel created', 'icon' => 'heroicon-o-bell-alert', 'tone' => 'success'],
            'user.password_changed' => ['label' => 'Password changed', 'icon' => 'heroicon-o-lock-closed', 'tone' => 'warning'],
            'user.passkey_removed' => ['label' => 'Passkey removed', 'icon' => 'heroicon-o-finger-print', 'tone' => 'danger'],
            'user.oauth_unlinked' => ['label' => 'OAuth account unlinked', 'icon' => 'heroicon-o-link-slash', 'tone' => 'warning'],
            'user.two_factor_enabled' => ['label' => 'Two-factor enabled', 'icon' => 'heroicon-o-shield-check', 'tone' => 'success'],
            'user.two_factor_disabled' => ['label' => 'Two-factor disabled', 'icon' => 'heroicon-o-shield-exclamation', 'tone' => 'warning'],
            'user.ssh_key_added' => ['label' => 'SSH key added', 'icon' => 'heroicon-o-key', 'tone' => 'success'],
            'user.ssh_key_updated' => ['label' => 'SSH key updated', 'icon' => 'heroicon-o-pencil-square', 'tone' => 'info'],
            'user.ssh_key_removed' => ['label' => 'SSH key removed', 'icon' => 'heroicon-o-key', 'tone' => 'danger'],
            'user.profile_updated' => ['label' => 'Profile updated', 'icon' => 'heroicon-o-user-circle', 'tone' => 'info'],
            'user.email_changed' => ['label' => 'Email address changed', 'icon' => 'heroicon-o-envelope', 'tone' => 'warning'],
            'user.account_deleted' => ['label' => 'Account deleted', 'icon' => 'heroicon-o-user-minus', 'tone' => 'danger'],
            'credential.created' => ['label' => 'Provider credential added', 'icon' => 'heroicon-o-key', 'tone' => 'success'],
            'credential.verified' => ['label' => 'Provider credential verified', 'icon' => 'heroicon-o-check-badge', 'tone' => 'success'],
            'credential.verify_failed' => ['label' => 'Provider credential verify failed', 'icon' => 'heroicon-o-exclamation-triangle', 'tone' => 'danger'],
            'credential.deleted' => ['label' => 'Provider credential removed', 'icon' => 'heroicon-o-trash', 'tone' => 'danger'],

            // Org
            'organization.created' => ['label' => 'Organization created', 'icon' => 'heroicon-o-building-office-2', 'tone' => 'success'],
            'organization.deploy_email_notifications_updated' => ['label' => 'Deploy email notifications updated', 'icon' => 'heroicon-o-envelope', 'tone' => 'info'],
            'organization.email_server_credentials_updated' => ['label' => 'Server credential email updated', 'icon' => 'heroicon-o-envelope', 'tone' => 'info'],
            'organization.email_database_credentials_updated' => ['label' => 'Database credential email updated', 'icon' => 'heroicon-o-envelope', 'tone' => 'info'],

            // Billing
            'billing.checkout_started' => ['label' => 'Checkout started', 'icon' => 'heroicon-o-shopping-cart', 'tone' => 'info'],
            'billing.portal_accessed' => ['label' => 'Billing portal opened', 'icon' => 'heroicon-o-arrow-top-right-on-square', 'tone' => 'info'],
            'billing.interval_switched' => ['label' => 'Billing interval switched', 'icon' => 'heroicon-o-arrows-right-left', 'tone' => 'info'],
            'billing.subscription_canceled' => ['label' => 'Subscription canceled', 'icon' => 'heroicon-o-x-circle', 'tone' => 'danger'],
            'billing.subscription_resumed' => ['label' => 'Subscription resumed', 'icon' => 'heroicon-o-check-circle', 'tone' => 'success'],

            // Scripts
            'script.created' => ['label' => 'Script created', 'icon' => 'heroicon-o-command-line', 'tone' => 'success'],
            'script.updated' => ['label' => 'Script updated', 'icon' => 'heroicon-o-pencil-square', 'tone' => 'info'],
            'script.deleted' => ['label' => 'Script deleted', 'icon' => 'heroicon-o-trash', 'tone' => 'danger'],

            // Marketplace
            'marketplace.deploy_command_imported' => ['label' => 'Deploy command imported', 'icon' => 'heroicon-o-arrow-down-tray', 'tone' => 'success'],
            'marketplace.server_recipe_imported' => ['label' => 'Server recipe imported', 'icon' => 'heroicon-o-arrow-down-tray', 'tone' => 'success'],
            'marketplace.webserver_template_imported' => ['label' => 'Webserver template imported', 'icon' => 'heroicon-o-arrow-down-tray', 'tone' => 'success'],
            'marketplace.workspace_runbook_imported' => ['label' => 'Project runbook imported', 'icon' => 'heroicon-o-book-open', 'tone' => 'success'],

            // Backups
            'backup.destination.created' => ['label' => 'Backup destination added', 'icon' => 'heroicon-o-plus', 'tone' => 'success'],
            'backup.destination.updated' => ['label' => 'Backup destination updated', 'icon' => 'heroicon-o-pencil-square', 'tone' => 'info'],
            'backup.destination.deleted' => ['label' => 'Backup destination removed', 'icon' => 'heroicon-o-trash', 'tone' => 'danger'],
            'backup.schedule.created' => ['label' => 'Backup schedule created', 'icon' => 'heroicon-o-calendar', 'tone' => 'success'],
            'backup.schedule.deleted' => ['label' => 'Backup schedule deleted', 'icon' => 'heroicon-o-trash', 'tone' => 'danger'],
            'backup.schedule.run_now' => ['label' => 'Backup ran now', 'icon' => 'heroicon-o-play', 'tone' => 'info'],
            'backup.schedule.test_alert' => ['label' => 'Backup test alert sent', 'icon' => 'heroicon-o-bell-alert', 'tone' => 'info'],
            'backup.database.deleted' => ['label' => 'Database backup deleted', 'icon' => 'heroicon-o-trash', 'tone' => 'danger'],
            'backup.database.run_dispatched' => ['label' => 'Database backup dispatched', 'icon' => 'heroicon-o-rocket-launch', 'tone' => 'info'],
            'backup.site_files.deleted' => ['label' => 'Site files backup deleted', 'icon' => 'heroicon-o-trash', 'tone' => 'danger'],
            'backup.site_files.run_dispatched' => ['label' => 'Site files backup dispatched', 'icon' => 'heroicon-o-rocket-launch', 'tone' => 'info'],

            // Insights
            'insight.acknowledged' => ['label' => 'Insight acknowledged', 'icon' => 'heroicon-o-eye', 'tone' => 'info'],
            'insight.unacknowledged' => ['label' => 'Insight un-acknowledged', 'icon' => 'heroicon-o-eye-slash', 'tone' => 'warning'],
            'insight.ignored' => ['label' => 'Insight ignored', 'icon' => 'heroicon-o-no-symbol', 'tone' => 'warning'],
            'insight.unignored' => ['label' => 'Insight un-ignored', 'icon' => 'heroicon-o-eye', 'tone' => 'info'],
            'insight.fix_run_dispatched' => ['label' => 'Insight fix dispatched', 'icon' => 'heroicon-o-wrench', 'tone' => 'info'],
            'insight.fix_reverted' => ['label' => 'Insight fix reverted', 'icon' => 'heroicon-o-arrow-uturn-left', 'tone' => 'warning'],

            // Imports
            'import.migration.started' => ['label' => 'Migration started', 'icon' => 'heroicon-o-play-circle', 'tone' => 'info'],
            'import.migration.step_retried' => ['label' => 'Migration step retried', 'icon' => 'heroicon-o-arrow-path', 'tone' => 'info'],
            'import.migration.step_skipped' => ['label' => 'Migration step skipped', 'icon' => 'heroicon-o-forward', 'tone' => 'warning'],
            'import.migration.aborted' => ['label' => 'Migration aborted', 'icon' => 'heroicon-o-x-circle', 'tone' => 'danger'],
            'import.migration.cutover_begun' => ['label' => 'Cutover begun', 'icon' => 'heroicon-o-arrow-right-circle', 'tone' => 'info'],
            'import.migration.cutover_rolled_back' => ['label' => 'Cutover rolled back', 'icon' => 'heroicon-o-arrow-uturn-left', 'tone' => 'warning'],
            'import.migration.cutover_marked_resolved' => ['label' => 'Cutover marked resolved', 'icon' => 'heroicon-o-check-circle', 'tone' => 'success'],
        ];
    }

    /**
     * Prefix patterns for concatenated actions (`server.firewall.*`, etc.).
     * First match wins; order matters, so more specific prefixes go first.
     *
     * @return array{label: string, icon: string, tone: string}|null
     */
    private static function prefixMatch(string $action): ?array
    {
        $verbTone = function (string $action): string {
            return match (true) {
                str_contains($action, '.deleted'),
                str_contains($action, '.removed'),
                str_contains($action, '.revoked'),
                str_contains($action, '.cancelled'),
                str_contains($action, '.failed') => 'danger',
                str_contains($action, '.created'),
                str_contains($action, '.added'),
                str_contains($action, '.enabled') => 'success',
                str_contains($action, '.rotated'),
                str_contains($action, '.disabled'),
                str_contains($action, '.suspended') => 'warning',
                default => 'info',
            };
        };

        $patterns = [
            'server.firewall.' => ['icon' => 'heroicon-o-fire', 'family_label' => 'Firewall'],
            'server.ssh_keys.' => ['icon' => 'heroicon-o-key', 'family_label' => 'SSH key'],
            'server.caches.' => ['icon' => 'heroicon-o-circle-stack', 'family_label' => 'Cache engine'],
            'server.databases.' => ['icon' => 'heroicon-o-circle-stack', 'family_label' => 'Database engine'],
            'server.service.bulk_' => ['icon' => 'heroicon-o-bolt', 'family_label' => 'Service (bulk)'],
            'server.service.' => ['icon' => 'heroicon-o-cog-6-tooth', 'family_label' => 'Service'],
            'queue_worker.' => ['icon' => 'heroicon-o-queue-list', 'family_label' => 'Queue worker'],
            'script.' => ['icon' => 'heroicon-o-command-line', 'family_label' => 'Script'],
        ];

        foreach ($patterns as $prefix => $meta) {
            if (str_starts_with($action, $prefix)) {
                $tail = substr($action, strlen($prefix));

                return [
                    'label' => $meta['family_label'].' '.str_replace('_', ' ', $tail),
                    'icon' => $meta['icon'],
                    'tone' => $verbTone($action),
                ];
            }
        }

        return null;
    }
}
