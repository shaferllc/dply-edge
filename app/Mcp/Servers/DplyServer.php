<?php

declare(strict_types=1);

namespace App\Mcp\Servers;

use App\Mcp\Resources\SiteConfigResource;
use App\Mcp\Resources\SiteListResource;
use App\Mcp\Tools\Diagnostics\GetAppLogs;
use App\Mcp\Tools\Diagnostics\GetDeploymentLog;
use App\Mcp\Tools\Diagnostics\GetRecentRequests;
use App\Mcp\Tools\Diagnostics\GetSiteHealth;
use App\Mcp\Tools\Diagnostics\ListDeployments;
use App\Mcp\Tools\Diagnostics\ProbeUrl;
use App\Mcp\Tools\Sites\GetSite;
use App\Mcp\Tools\Sites\ListSites;
use Laravel\Mcp\Server;
use Laravel\Mcp\Server\Tool;

class DplyServer extends Server
{
    protected string $name = 'dply';

    protected string $version = '1.0.0';

    protected string $instructions = <<<'MARKDOWN'
        This server gives read-only access to the apps an organization hosts on
        dply Edge (static sites, SSR apps and containers on Cloudflare).

        Scope & auth: every call is scoped to the organization of the API token
        you connected with and gated by that token's abilities (`sites.read`).
        Start with `list_sites` (or the `dply://sites` resource) to find app ids,
        then `get_site` for one app's details: live URL, repository and branch,
        runtime mode, and custom domains with their DNS and TLS status.

        Diagnosing a problem (tokens with `edge.read`): start with
        `get_site_health` (live and latest deploy, billing pause, container
        instances), then `get_deployment_log` for a failed deploy,
        `get_app_logs` for a container app's own output and exceptions,
        `get_recent_requests` (min_status 400) for what visitors got, and
        `probe_url` to request a path on the app's own hostnames yourself.

        There are no write tools. To deploy, roll back, change environment
        variables or manage domains, use the dply CLI or the REST API.
        MARKDOWN;

    /**
     * @var array<int, class-string<Tool>>
     */
    protected array $tools = [
        ListSites::class,
        GetSite::class,
        GetSiteHealth::class,
        ListDeployments::class,
        GetDeploymentLog::class,
        GetAppLogs::class,
        GetRecentRequests::class,
        ProbeUrl::class,
    ];

    /**
     * @var array<int, class-string<Server\Resource>>
     */
    protected array $resources = [
        SiteListResource::class,
        SiteConfigResource::class,
    ];
}
