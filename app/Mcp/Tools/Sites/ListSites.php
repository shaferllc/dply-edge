<?php

declare(strict_types=1);

namespace App\Mcp\Tools\Sites;

use App\Mcp\Support\SitePayload;
use App\Mcp\Tools\AbstractDplyTool;
use App\Models\Organization;
use App\Models\Site;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;

class ListSites extends AbstractDplyTool
{
    protected string $name = 'list_sites';

    protected string $description = 'List the apps in the authenticated organization: id, slug, name, runtime mode, status, live URL and last deploy time.';

    protected string $ability = 'sites.read';

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [];
    }

    protected function run(Request $request, Organization $organization): Response
    {
        $sites = Site::query()
            ->whereHas('server', fn ($q) => $q->where('organization_id', $organization->id))
            ->orderBy('name')
            ->get(['id', 'server_id', 'name', 'slug', 'status', 'meta', 'last_deploy_at', 'created_at']);

        return Response::json(['data' => $sites->reject(fn (Site $s): bool => $s->isEdgePreview())->map(SitePayload::summary(...))->values()->all()]);
    }
}
