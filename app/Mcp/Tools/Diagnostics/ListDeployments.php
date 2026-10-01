<?php

declare(strict_types=1);

namespace App\Mcp\Tools\Diagnostics;

use App\Mcp\Tools\AbstractDplyTool;
use App\Models\EdgeDeployment;
use App\Models\Organization;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;

class ListDeployments extends AbstractDplyTool
{
    protected string $name = 'list_deployments';

    protected string $description = 'Recent deploys of one app, newest first: status (building, publishing, live, failed, superseded), branch and commit, timings, and the failure reason of a failed one. Use get_deployment_log for the full log.';

    protected string $ability = 'edge.read';

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'site_id' => $schema->string()->description('The app id (or slug).')->required(),
            'limit' => $schema->integer()->description('How many deploys, 1-50. Default 10.'),
        ];
    }

    protected function run(Request $request, Organization $organization): Response
    {
        $input = $request->validate([
            'site_id' => ['required', 'string'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);
        $site = $this->resolveSite($input['site_id'], $organization);

        $rows = EdgeDeployment::query()
            ->where('site_id', $site->id)
            ->latest()
            ->limit((int) ($input['limit'] ?? 10))
            ->get()
            ->map(fn (EdgeDeployment $d): array => [
                'id' => $d->id,
                'status' => $d->status,
                'branch' => $d->git_branch,
                'commit' => $d->git_commit,
                'created_at' => $d->created_at?->toIso8601String(),
                'build_started_at' => $d->build_started_at?->toIso8601String(),
                'build_seconds' => $d->build_seconds,
                'published_at' => $d->published_at?->toIso8601String(),
                'failed_at' => $d->failed_at?->toIso8601String(),
                'failure_reason' => $d->failure_reason,
            ])
            ->all();

        return Response::json(['data' => $rows]);
    }
}
