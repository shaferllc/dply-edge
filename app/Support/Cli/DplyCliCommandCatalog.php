<?php

declare(strict_types=1);

namespace App\Support\Cli;

/**
 * Indexed catalog of invokable `dply` CLI commands for the Profile → CLI page.
 *
 * Kept in PHP (not scraped from the Node package) so Blade/Livewire can render
 * it without shipping Node into the render path. Mirror packages/dply-cli
 * (cli.mjs TOP_LEVEL / EDGE_COMMANDS, shortcuts.mjs) when adding commands.
 * Scopes match config/product/api_token_permissions.php http_route_abilities.
 */
final class DplyCliCommandCatalog
{
    /**
     * @return list<array{key: string, label: string, description: string}>
     */
    public static function groups(): array
    {
        return [
            ['key' => 'setup', 'label' => __('Setup'), 'description' => __('Install, login, link, and deploy')],
            ['key' => 'account', 'label' => __('Account'), 'description' => __('Profile, orgs, sessions, auth')],
            ['key' => 'edge', 'label' => __('Edge'), 'description' => __('Deploys, previews, domains, env, logs')],
            ['key' => 'notifications', 'label' => __('Notifications'), 'description' => __('Channels and event routing')],
            ['key' => 'billing', 'label' => __('Billing'), 'description' => __('Plan estimate and invoices')],
            ['key' => 'shortcuts', 'label' => __('Shortcuts'), 'description' => __('Single-token aliases')],
        ];
    }

    /**
     * @return list<array{id: string, group: string, title: string, command: string, summary: string, keywords: string, scope: string|null, server_bound: bool}>
     */
    public static function entries(): array
    {
        /** @var list<array{id: string, group: string, title: string, command: string, summary: string, keywords?: string, scope?: string|null}> $raw */
        $raw = [
            // Setup
            ['id' => 'login', 'group' => 'setup', 'title' => 'login', 'command' => 'dply login', 'summary' => 'Browser device-flow login, then interactive shell.', 'keywords' => 'auth sign-in authenticate'],
            ['id' => 'login-no-shell', 'group' => 'setup', 'title' => 'login --no-shell', 'command' => 'dply login --no-shell', 'summary' => 'Authenticate without dropping into the interactive shell (CI).', 'keywords' => 'ci scripts'],
            ['id' => 'logout', 'group' => 'setup', 'title' => 'logout', 'command' => 'dply logout', 'summary' => 'Remove the saved token from this machine.', 'keywords' => 'sign-out'],
            ['id' => 'use', 'group' => 'setup', 'title' => 'use', 'command' => 'dply use', 'summary' => 'Switch which dply instance the CLI talks to (list, <host>, <url>, forget).', 'keywords' => 'instance switch host'],
            ['id' => 'menu', 'group' => 'setup', 'title' => 'menu', 'command' => 'dply menu', 'summary' => 'Numbered menus — type names or numbers.', 'keywords' => 'browse interactive'],
            ['id' => 'shell', 'group' => 'setup', 'title' => 'shell', 'command' => 'dply shell', 'summary' => 'Interactive mode (same as bare `dply` on a TTY).', 'keywords' => 'repl interactive'],
            ['id' => 'whoami', 'group' => 'setup', 'title' => 'whoami', 'command' => 'dply whoami', 'summary' => 'Show account + session (alias for account show).', 'keywords' => 'me identity'],
            ['id' => 'help', 'group' => 'setup', 'title' => 'help', 'command' => 'dply help', 'summary' => 'Top-level command help.', 'keywords' => 'usage docs'],
            ['id' => 'ls', 'group' => 'setup', 'title' => 'ls', 'command' => 'dply ls', 'summary' => 'Compact command index.', 'keywords' => 'index list commands'],
            ['id' => 'update', 'group' => 'setup', 'title' => 'update', 'command' => 'dply update', 'summary' => 'Install the CLI build this instance is serving — how you pick up new commands.', 'keywords' => 'upgrade newer version outdated install'],
            ['id' => 'update-check', 'group' => 'setup', 'title' => 'update --check', 'command' => 'dply update --check', 'summary' => 'Report only; exits 1 when your build differs from the instance.', 'keywords' => 'upgrade ci version'],
            ['id' => 'sites', 'group' => 'setup', 'title' => 'sites', 'command' => 'dply sites', 'summary' => 'List your Edge sites (name filter).', 'scope' => 'edge.read', 'keywords' => 'list apps'],
            ['id' => 'link', 'group' => 'setup', 'title' => 'link', 'command' => 'dply link <site>', 'summary' => 'Link this folder to an Edge site (.dply/site.json); picker when no id.', 'scope' => 'edge.read', 'keywords' => 'repo link'],
            ['id' => 'deploy', 'group' => 'setup', 'title' => 'deploy', 'command' => 'dply deploy --prod --wait', 'summary' => 'Deploy the linked Edge site (or --site <id>) and wait for it.', 'scope' => 'edge.deploy', 'keywords' => 'ship release ci github actions'],

            // Account
            ['id' => 'account-show', 'group' => 'account', 'title' => 'account show', 'command' => 'dply account show', 'summary' => 'Profile, org, token, and abilities.', 'scope' => 'account.read'],
            ['id' => 'account-orgs', 'group' => 'account', 'title' => 'account orgs', 'command' => 'dply account orgs', 'summary' => 'Organizations this user belongs to.', 'scope' => 'account.read'],
            ['id' => 'account-sessions', 'group' => 'account', 'title' => 'account sessions', 'command' => 'dply account sessions', 'summary' => 'Active CLI sessions in this org.', 'scope' => 'account.read'],
            ['id' => 'account-revoke', 'group' => 'account', 'title' => 'account revoke', 'command' => 'dply account revoke <session-id>', 'summary' => 'Revoke a CLI session by ID.', 'scope' => 'account.write'],
            ['id' => 'account-logout', 'group' => 'account', 'title' => 'account logout', 'command' => 'dply account logout', 'summary' => 'Remove the saved token (same as `dply logout`).'],
            ['id' => 'auth-refresh', 'group' => 'account', 'title' => 'auth refresh', 'command' => 'dply auth refresh', 'summary' => 'Device-flow re-approval for additional scopes.', 'keywords' => 'scopes refresh'],

            // Edge
            ['id' => 'edge-deploy', 'group' => 'edge', 'title' => 'edge deploy', 'command' => 'dply edge deploy --site <site>', 'summary' => 'Queue a deploy (--commit / --branch / --prod).', 'scope' => 'edge.deploy'],
            ['id' => 'edge-deployments', 'group' => 'edge', 'title' => 'edge deployments', 'command' => 'dply edge deployments --site <site>', 'summary' => 'List recent Edge deployments.', 'scope' => 'edge.read'],
            ['id' => 'edge-status', 'group' => 'edge', 'title' => 'edge status', 'command' => 'dply edge status --site <site> --wait', 'summary' => 'Edge site + latest deployment (--wait blocks until it settles).', 'scope' => 'edge.read'],
            ['id' => 'edge-lint', 'group' => 'edge', 'title' => 'edge lint', 'command' => 'dply edge lint', 'summary' => 'Validate dply.yaml in cwd (--path).', 'scope' => 'edge.read', 'keywords' => 'yaml config'],
            ['id' => 'edge-open', 'group' => 'edge', 'title' => 'edge open', 'command' => 'dply edge open --site <site>', 'summary' => 'Open live URL (--dashboard for workspace).', 'scope' => 'edge.read'],
            ['id' => 'edge-rollback', 'group' => 'edge', 'title' => 'edge rollback', 'command' => 'dply edge rollback <deployment> --site <site>', 'summary' => 'Re-point production at a prior deployment.', 'scope' => 'edge.deploy'],
            ['id' => 'edge-promote', 'group' => 'edge', 'title' => 'edge promote', 'command' => 'dply edge promote <preview> --site <site>', 'summary' => 'Promote a preview to production.', 'scope' => 'edge.deploy'],
            ['id' => 'edge-previews', 'group' => 'edge', 'title' => 'edge previews', 'command' => 'dply edge previews list --site <site>', 'summary' => 'list | create [--commit|--branch] [--wait] | rm <id>.', 'scope' => 'edge.read'],
            ['id' => 'edge-domains', 'group' => 'edge', 'title' => 'edge domains', 'command' => 'dply edge domains list --site <site>', 'summary' => 'list | add <host> | verify <host> | rm <host>.', 'scope' => 'edge.read', 'keywords' => 'custom domain dns'],
            ['id' => 'edge-aliases', 'group' => 'edge', 'title' => 'edge aliases', 'command' => 'dply edge aliases --site <site>', 'summary' => 'List per-deploy stable URLs.', 'scope' => 'edge.read'],
            ['id' => 'edge-purge', 'group' => 'edge', 'title' => 'edge purge', 'command' => 'dply edge purge --tag <tag> --site <site>', 'summary' => 'Purge edge cache by tag.', 'scope' => 'edge.write', 'keywords' => 'cache cdn'],
            ['id' => 'edge-usage', 'group' => 'edge', 'title' => 'edge usage', 'command' => 'dply edge usage --site <site>', 'summary' => 'Traffic / billing usage.', 'scope' => 'edge.read'],
            ['id' => 'edge-logs', 'group' => 'edge', 'title' => 'edge logs', 'command' => 'dply edge logs --site <site>', 'summary' => 'Tail request logs (--interval · --window · --once).', 'scope' => 'edge.read', 'keywords' => 'tail requests'],
            ['id' => 'edge-env-list', 'group' => 'edge', 'title' => 'edge env list', 'command' => 'dply edge env list --site <site>', 'summary' => 'List environment variable keys (pull to fetch them).', 'scope' => 'edge.env.read', 'keywords' => '.env dotenv'],
            ['id' => 'edge-env-set', 'group' => 'edge', 'title' => 'edge env set', 'command' => 'dply edge env set KEY=value --site <site>', 'summary' => 'Set, rm, or push --file .env.', 'scope' => 'edge.env.write'],

            // Notifications
            ['id' => 'notifications', 'group' => 'notifications', 'title' => 'notifications', 'command' => 'dply notifications --site <site>', 'summary' => 'What fires for a site, and which channels get it.', 'scope' => 'notifications.read', 'keywords' => 'alerts routing subscriptions'],
            ['id' => 'notifications-channels', 'group' => 'notifications', 'title' => 'notifications channels', 'command' => 'dply notifications channels', 'summary' => 'Channels this token can route events to.', 'scope' => 'notifications.read', 'keywords' => 'slack email webhook'],
            ['id' => 'notifications-events', 'group' => 'notifications', 'title' => 'notifications events', 'command' => 'dply notifications events --subject site', 'summary' => 'The event catalog.', 'scope' => 'notifications.read'],
            ['id' => 'notifications-subscribe', 'group' => 'notifications', 'title' => 'notifications subscribe', 'command' => 'dply notifications subscribe edge.deploy.failed --channel <id> --site <site>', 'summary' => 'Route an event to a channel (unsubscribe to undo).', 'scope' => 'notifications.write', 'keywords' => 'route alert'],
            ['id' => 'notifications-test', 'group' => 'notifications', 'title' => 'notifications test', 'command' => 'dply notifications test <channel>', 'summary' => 'Send a channel its test message.', 'scope' => 'notifications.write', 'keywords' => 'ping verify'],

            // Billing
            ['id' => 'billing-show', 'group' => 'billing', 'title' => 'billing show', 'command' => 'dply billing show', 'summary' => 'Plan + monthly estimate (org admin).', 'scope' => 'billing.read'],
            ['id' => 'billing-breakdown', 'group' => 'billing', 'title' => 'billing breakdown', 'command' => 'dply billing breakdown', 'summary' => 'Line-item estimate.', 'scope' => 'billing.read'],
            ['id' => 'billing-invoices', 'group' => 'billing', 'title' => 'billing invoices', 'command' => 'dply billing invoices', 'summary' => 'Recent Stripe invoices.', 'scope' => 'billing.read'],

            // Shortcuts
            ['id' => 'sc-r', 'group' => 'shortcuts', 'title' => 'r', 'command' => 'dply r', 'summary' => '→ auth refresh', 'keywords' => 'refresh scopes'],
            ['id' => 'sc-me', 'group' => 'shortcuts', 'title' => 'me', 'command' => 'dply me', 'summary' => '→ whoami', 'keywords' => 'who'],
            ['id' => 'sc-m', 'group' => 'shortcuts', 'title' => 'm', 'command' => 'dply m', 'summary' => '→ menu'],
            ['id' => 'sc-orgs', 'group' => 'shortcuts', 'title' => 'orgs', 'command' => 'dply orgs', 'summary' => '→ account orgs'],
            ['id' => 'sc-bill', 'group' => 'shortcuts', 'title' => 'bill', 'command' => 'dply bill', 'summary' => '→ billing show'],
        ];

        return array_map(static fn (array $entry): array => [
            'id' => $entry['id'],
            'group' => $entry['group'],
            'title' => $entry['title'],
            'command' => $entry['command'],
            'summary' => $entry['summary'],
            'keywords' => trim(($entry['keywords'] ?? '').' '.$entry['title'].' '.$entry['command'].' '.$entry['summary']),
            'scope' => $entry['scope'] ?? null,
            'server_bound' => false,
        ], $raw);
    }

    /**
     * @return array{groups: list<array{key: string, label: string, description: string, count: int}>, entries: list<array<string, mixed>>, total: int}
     */
    public static function forServer(): array
    {
        $entries = self::entries();
        $counts = array_count_values(array_column($entries, 'group'));

        return [
            'groups' => array_map(
                static fn (array $group): array => [...$group, 'count' => $counts[$group['key']] ?? 0],
                self::groups(),
            ),
            'entries' => $entries,
            'total' => count($entries),
        ];
    }
}
