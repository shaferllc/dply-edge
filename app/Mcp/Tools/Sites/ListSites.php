<?php

declare(strict_types=1);

namespace App\Mcp\Tools\Sites;

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

    protected string $description = 'List all sites in the authenticated organization. Optionally filter by server. Returns each site\'s id, name, kind (vm | cloud | edge | serverless), server, runtime, status, and deploy strategy.';

    protected string $ability = 'sites.read';

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'server_id' => $schema->string()
                ->description('Only return sites on this server id.'),
        ];
    }

    protected function run(Request $request, Organization $organization): Response
    {
        $serverId = $request->get('server_id');

        $sites = Site::query()
            ->whereHas('server', fn ($q) => $q->where('organization_id', $organization->id))
            ->when($serverId, fn ($q) => $q->where('server_id', $serverId))
            ->with(['server:id,name'])
            ->orderBy('name')
            // edge_backend / container_backend / meta are what siteKind() reads —
            // without them every row would report itself as a plain VM site.
            ->get(['id', 'server_id', 'name', 'slug', 'type', 'runtime', 'deploy_strategy', 'status', 'document_root', 'edge_backend', 'container_backend', 'meta', 'last_deploy_at', 'created_at']);

        return Response::json([
            'data' => $sites->map(fn (Site $s) => [
                'id' => $s->id,
                'slug' => $s->slug,
                'name' => $s->name,
                'kind' => $s->siteKind(),
                'server_id' => $s->server_id,
                'server_name' => $s->server?->name,
                'type' => $s->type,
                'runtime' => $s->runtime,
                'status' => $s->status,
                'deploy_strategy' => $s->deploy_strategy,
                'document_root' => $s->document_root,
                'last_deploy_at' => $s->last_deploy_at?->toIso8601String(),
                'created_at' => $s->created_at->toIso8601String(),
            ])->all(),
        ]);
    }
}
