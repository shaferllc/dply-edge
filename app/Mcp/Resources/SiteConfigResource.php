<?php

declare(strict_types=1);

namespace App\Mcp\Resources;

use App\Mcp\Concerns\ResolvesDplyContext;
use App\Mcp\Support\SitePayload;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\MimeType;
use Laravel\Mcp\Server\Contracts\HasUriTemplate;
use Laravel\Mcp\Server\Resource;
use Laravel\Mcp\Support\UriTemplate;

/**
 * Current configuration snapshot for one site, addressable as
 * `dply://sites/{site_id}`. Mirrors the get_site tool output.
 */
#[MimeType('application/json')]
#[Description('One dply Edge app: runtime mode, status, live URL, repository, custom domains, last deploy.')]
class SiteConfigResource extends Resource implements HasUriTemplate
{
    use ResolvesDplyContext;

    protected string $name = 'site-config';

    public function uriTemplate(): UriTemplate
    {
        return new UriTemplate('dply://sites/{site_id}');
    }

    public function handle(Request $request): Response
    {
        $this->requireAbility('sites.read');

        $siteId = (string) $request->get('site_id');

        $site = $this->resolveSite($siteId);

        return Response::json(['data' => SitePayload::detail($site)]);
    }
}
