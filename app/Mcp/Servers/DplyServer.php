<?php

declare(strict_types=1);

namespace App\Mcp\Servers;

use App\Mcp\Resources\SiteConfigResource;
use App\Mcp\Resources\SiteListResource;
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

        There are no write tools. To deploy, roll back, change environment
        variables or manage domains, use the dply CLI or the REST API.
        MARKDOWN;

    /**
     * @var array<int, class-string<Tool>>
     */
    protected array $tools = [
        ListSites::class,
        GetSite::class,
    ];

    /**
     * @var array<int, class-string<Server\Resource>>
     */
    protected array $resources = [
        SiteListResource::class,
        SiteConfigResource::class,
    ];
}
