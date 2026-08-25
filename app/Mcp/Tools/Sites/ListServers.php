<?php

declare(strict_types=1);

namespace App\Mcp\Tools\Sites;

use App\Mcp\Tools\AbstractDplyTool;
use App\Models\Organization;
use App\Models\Server;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;

class ListServers extends AbstractDplyTool
{
    protected string $name = 'list_servers';

    protected string $description = 'List the servers in the authenticated organization (id, name, status, IP, provider). Useful for picking a deploy target or scoping list_sites.';

    protected string $ability = 'servers.read';

    /**
     * @return array<string, \Illuminate\JsonSchema\Types\Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [];
    }

    protected function run(Request $request, Organization $organization): Response
    {
        // Machines only, matching /servers and the REST fleet index — Edge
        // apps, function namespaces and Cloud containers are placeholder host
        // rows, not servers.
        $servers = Server::query()
            ->where('organization_id', $organization->id)
            ->orderBy('name')
            ->get(['id', 'name', 'status', 'ip_address', 'provider', 'created_at']);

        return Response::json([
            'data' => $servers->map(fn (Server $s) => [
                'id' => $s->id,
                'name' => $s->name,
                'status' => $s->status,
                'provider' => $s->provider->value,
                'created_at' => $s->created_at->toIso8601String(),
            ])->all(),
        ]);
    }
}
