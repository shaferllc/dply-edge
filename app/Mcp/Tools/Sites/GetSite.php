<?php

declare(strict_types=1);

namespace App\Mcp\Tools\Sites;

use App\Mcp\Tools\AbstractDplyTool;
use App\Models\Organization;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;

class GetSite extends AbstractDplyTool
{
    protected string $name = 'get_site';

    protected string $description = 'Get the full configuration of a single site: runtime, status, document root, git repository/branch, SSL status, and last deploy time.';

    protected string $ability = 'sites.read';

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'site_id' => $schema->string()
                ->description('The site id (or slug) to fetch.')
                ->required(),
        ];
    }

    protected function run(Request $request, Organization $organization): Response
    {
        ['site_id' => $siteId] = $request->validate([
            'site_id' => ['required', 'string'],
        ]);

        $site = $this->resolveSite($siteId, $organization);

        return Response::json([
            'data' => [
                'id' => $site->id,
                'slug' => $site->slug,
                'name' => $site->name,
                'server_id' => $site->server_id,
                'server_name' => $site->server?->name,
                'type' => $site->type,
                'runtime' => $site->runtime,
                'runtime_version' => $site->runtime_version,
                'status' => $site->status,
                'deploy_strategy' => $site->deploy_strategy,
                'document_root' => $site->document_root,
                'git_repository_url' => $site->git_repository_url,
                'git_branch' => $site->git_branch,
                'ssl_status' => $site->ssl_status,
                'last_deploy_at' => $site->last_deploy_at?->toIso8601String(),
                'created_at' => $site->created_at->toIso8601String(),
            ],
        ]);
    }
}
