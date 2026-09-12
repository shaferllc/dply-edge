<?php

use App\Mcp\Tools\AbstractDplyTool;
use App\Models\ApiToken;

/**
 * Single source of truth for API token ability strings.
 *
 * - UI categories + labels (Profile → API keys and `dply login` approval): categories
 * - Deployer role runtime cap (must be a subset of the catalog): deployer_api_allowlist
 * - HTTP API v1 route middleware: http_route_abilities (values must exist in catalog or *)
 *
 * Trimmed to the Edge product on 2026-09-11. Tokens minted before then may still
 * carry VM-era abilities (commands.run, projects.*, serverless.*, …). They are
 * harmless: no route checks them any more.
 *
 * The MCP server (routes/ai.php → App\Mcp) reuses these abilities — each tool
 * declares the one it requires and AbstractDplyTool enforces it via
 * $token->allows():
 *   list_sites / get_site / get_operation_status ...................... sites.read
 *   list_servers ...................................................... servers.read
 *
 * @see ApiToken::allows()
 * @see AbstractDplyTool
 */

return [
    /*
    |--------------------------------------------------------------------------
    | Deployer role: abilities allowed at runtime (ApiToken::allows)
    |--------------------------------------------------------------------------
    |
    | Mirrors cli.device_flow_role_caps.deployer. Before 2026-09-11 this held
    | only VM abilities, so a deployer's `dply login` token was refused on
    | every /api/v1/edge/* route.
    */
    'deployer_api_allowlist' => [
        'account.read',
        'account.write',
        'edge.read',
        'edge.deploy',
        'edge.env.read',
        'sites.read',
        'servers.read',
    ],

    /*
    |--------------------------------------------------------------------------
    | HTTP API v1 — ability checked per route (keys are read by routes/api.php)
    |--------------------------------------------------------------------------
    */
    'http_route_abilities' => [
        'account.show' => 'account.read',
        'account.organizations' => 'account.read',
        'account.sessions' => 'account.read',
        'account.sessions_destroy' => 'account.write',

        'billing.show' => 'billing.read',
        'billing.breakdown' => 'billing.read',
        'billing.invoices' => 'billing.read',

        'notifications.channels' => 'notifications.read',
        'notifications.events' => 'notifications.read',
        'notifications.site_index' => 'notifications.read',
        'notifications.site_update' => 'notifications.write',
        // Sending a test message actually pages someone, so it is a write.
        'notifications.test' => 'notifications.write',

        'edge.sites.index' => 'edge.read',
        'edge.sites.show' => 'edge.read',
        'edge.deployments.index' => 'edge.read',
        'edge.deployments.show' => 'edge.read',
        'edge.deployments.store' => 'edge.deploy',
        'edge.deployments.rollback' => 'edge.deploy',
        'edge.previews.index' => 'edge.read',
        'edge.previews.store' => 'edge.deploy',
        'edge.previews.destroy' => 'edge.deploy',
        'edge.previews.promote' => 'edge.deploy',
        'edge.domains.index' => 'edge.read',
        'edge.domains.store' => 'edge.write',
        'edge.domains.verify' => 'edge.write',
        'edge.domains.destroy' => 'edge.write',
        'edge.aliases.index' => 'edge.read',
        'edge.access.show' => 'edge.read',
        'edge.access.update' => 'edge.write',
        'edge.cache.purge' => 'edge.write',
        'edge.usage.show' => 'edge.read',
        'edge.logs.index' => 'edge.read',
        'edge.lint.store' => 'edge.read',
        'edge.env.index' => 'edge.env.read',
        'edge.env.update' => 'edge.env.write',
        'edge.env.upsert' => 'edge.env.write',
        'edge.env.destroy' => 'edge.env.write',
    ],

    'categories' => [
        [
            'id' => 'edge',
            'label' => 'Edge',
            'permissions' => [
                ['ability' => 'edge.read', 'label' => 'Read'],
                ['ability' => 'edge.deploy', 'label' => 'Deploy / rollback / promote'],
                ['ability' => 'edge.write', 'label' => 'Manage domains, access and cache'],
            ],
        ],
        [
            'id' => 'edge_env',
            'label' => 'Edge env vars',
            'permissions' => [
                ['ability' => 'edge.env.read', 'label' => 'Read (keys only)'],
                ['ability' => 'edge.env.write', 'label' => 'Write'],
            ],
        ],
        [
            'id' => 'notifications',
            'label' => 'Notifications',
            'permissions' => [
                ['ability' => 'notifications.read', 'label' => 'Read channels and event subscriptions'],
                ['ability' => 'notifications.write', 'label' => 'Route events to channels, send tests'],
            ],
        ],
        [
            'id' => 'billing',
            'label' => 'Billing',
            'permissions' => [
                ['ability' => 'billing.read', 'label' => 'View plan, estimates, and invoices'],
            ],
        ],
        [
            'id' => 'account',
            'label' => 'Account & CLI',
            'permissions' => [
                ['ability' => 'account.read', 'label' => 'Read profile, orgs, and CLI sessions'],
                ['ability' => 'account.write', 'label' => 'Revoke CLI sessions'],
            ],
        ],
        [
            'id' => 'mcp',
            'label' => 'AI assistants (MCP)',
            'permissions' => [
                ['ability' => 'sites.read', 'label' => 'List and read sites'],
                ['ability' => 'servers.read', 'label' => 'List site hosts'],
            ],
        ],
    ],
];
