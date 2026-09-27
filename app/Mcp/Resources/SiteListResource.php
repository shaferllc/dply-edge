<?php

declare(strict_types=1);

namespace App\Mcp\Resources;

use App\Mcp\Concerns\ResolvesDplyContext;
use App\Mcp\Support\SitePayload;
use App\Models\Site;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\MimeType;
use Laravel\Mcp\Server\Attributes\Uri;
use Laravel\Mcp\Server\Resource;

/**
 * Snapshot of the organization's sites so an AI client can ground itself without
 * a tool call. Mirrors the list_sites tool output.
 */
#[Uri('dply://sites')]
#[MimeType('application/json')]
#[Description('The apps in the authenticated dply organization (id, name, runtime mode, status, live URL).')]
class SiteListResource extends Resource
{
    use ResolvesDplyContext;

    protected string $name = 'sites';

    public function handle(Request $request): Response
    {
        $this->requireAbility('sites.read');

        $organization = $this->organization();

        $sites = Site::query()
            ->whereHas('server', fn ($q) => $q->where('organization_id', $organization->id))
            ->orderBy('name')
            ->get(['id', 'server_id', 'name', 'slug', 'status', 'meta', 'last_deploy_at', 'created_at']);

        return Response::json(['data' => $sites->reject(fn (Site $s): bool => $s->isEdgePreview())->map(SitePayload::summary(...))->values()->all()]);
    }
}
