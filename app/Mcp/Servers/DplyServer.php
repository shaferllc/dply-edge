<?php

declare(strict_types=1);

namespace App\Mcp\Servers;

use App\Mcp\Resources\SiteConfigResource;
use App\Mcp\Resources\SiteListResource;
use App\Mcp\Tools\Diagnostics\GetOperationStatus;
use App\Mcp\Tools\Sites\GetSite;
use App\Mcp\Tools\Sites\ListServers;
use App\Mcp\Tools\Sites\ListSites;
use Laravel\Mcp\Server;
use Laravel\Mcp\Server\Tool;

class DplyServer extends Server
{
    protected string $name = 'dply';

    protected string $version = '1.0.0';

    protected string $instructions = <<<'MARKDOWN'
        This server manages and operates sites hosted on dply (a deployment and
        server-management platform).

        Scope & auth: every operation is scoped to the organization of the API
        token you connected with and is gated by that token's abilities — you can
        only see and act on that organization's sites. Start with `list_sites`
        (or the `dply://sites` resource) to discover sites and their ids, then
        `get_site` for details.

        Asynchronous work: mutating operations are queued, not instant.
        `deploy_site` returns immediately — poll `list_deployments` / `get_deployment`
        until the deployment status is `success` or `failed`. Other async
        operations return an `operation_id`; poll `get_operation_status` with it.

        Site types: OPcache flush, environment pushes, and similar host-level
        operations apply only to VM/host sites, not to container, serverless, or
        edge sites; those calls are rejected with a clear message.
        MARKDOWN;

    /**
     * @var array<int, class-string<Tool>>
     */
    protected array $tools = [
        // Discovery / read
        ListSites::class,
        GetSite::class,
        ListServers::class,
        // Deploy + poll
        // Environment
        // Database
        // Logs add-on (per-server edge Vector agent)
        // Async operation polling
        GetOperationStatus::class,
    ];

    /**
     * @var array<int, class-string<Server\Resource>>
     */
    protected array $resources = [
        SiteListResource::class,
        SiteConfigResource::class,
    ];
}
