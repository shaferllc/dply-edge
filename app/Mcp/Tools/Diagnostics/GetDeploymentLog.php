<?php

declare(strict_types=1);

namespace App\Mcp\Tools\Diagnostics;

use App\Mcp\Exceptions\DplyMcpException;
use App\Mcp\Tools\AbstractDplyTool;
use App\Models\EdgeDeployment;
use App\Models\Organization;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;

class GetDeploymentLog extends AbstractDplyTool
{
    protected string $name = 'get_deployment_log';

    protected string $description = 'The build and deploy log of one deploy (the latest if no deployment_id), last lines first trimmed to tail_lines. Shows the whole release step: build, rollout, migrations and the checks before traffic switches.';

    protected string $ability = 'edge.read';

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'site_id' => $schema->string()->description('The app id (or slug).')->required(),
            'deployment_id' => $schema->string()->description('A deploy id from list_deployments. Default: the latest deploy.'),
            'tail_lines' => $schema->integer()->description('Lines from the end, 1-3000. Default 300.'),
        ];
    }

    protected function run(Request $request, Organization $organization): Response
    {
        $input = $request->validate([
            'site_id' => ['required', 'string'],
            'deployment_id' => ['nullable', 'string'],
            'tail_lines' => ['nullable', 'integer', 'min:1', 'max:3000'],
        ]);
        $site = $this->resolveSite($input['site_id'], $organization);

        $deployment = EdgeDeployment::query()
            ->where('site_id', $site->id)
            ->when($input['deployment_id'] ?? null, fn ($q, string $id) => $q->whereKey($id))
            ->latest()
            ->first() ?? throw new DplyMcpException('No deploy found for this app.');

        $lines = preg_split('/\R/', (string) $deployment->readBuildLog($site)) ?: [];
        $tail = array_slice($lines, -((int) ($input['tail_lines'] ?? 300)));

        return Response::json(['data' => [
            'deployment_id' => $deployment->id,
            'status' => $deployment->status,
            'failure_reason' => $deployment->failure_reason,
            'total_lines' => count($lines),
            'log' => implode("\n", $tail),
        ]]);
    }
}
