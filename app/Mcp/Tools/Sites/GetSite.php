<?php

declare(strict_types=1);

namespace App\Mcp\Tools\Sites;

use App\Mcp\Support\SitePayload;
use App\Mcp\Tools\AbstractDplyTool;
use App\Models\Organization;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;

class GetSite extends AbstractDplyTool
{
    protected string $name = 'get_site';

    protected string $description = 'Get one app: runtime mode (static, hybrid, ssr or container), status, live URL, repository and branch, custom domains with DNS and TLS status, and last deploy time.';

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

        return Response::json(['data' => SitePayload::detail($site)]);
    }
}
