<?php

declare(strict_types=1);

namespace App\Mcp\Tools\Diagnostics;

use App\Mcp\Exceptions\DplyMcpException;
use App\Mcp\Tools\AbstractDplyTool;
use App\Models\Organization;
use App\Modules\Edge\Services\Containers\EdgeContainerDeployer;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;

class GetAppLogs extends AbstractDplyTool
{
    protected string $name = 'get_app_logs';

    protected string $description = 'A container app\'s recent runtime output, oldest first: the app\'s own stdout/stderr (Laravel logs and exceptions), queue workers, and the routing Worker in front of it (wakes, scaling, proxy errors). Each line has its time, level and source.';

    protected string $ability = 'edge.read';

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'site_id' => $schema->string()->description('The app id (or slug).')->required(),
            'minutes' => $schema->integer()->description('How far back, 1-1440. Default 15.'),
            'contains' => $schema->string()->description('Only lines containing this text.'),
            'limit' => $schema->integer()->description('Most recent lines to return, 1-1000. Default 300.'),
        ];
    }

    protected function run(Request $request, Organization $organization): Response
    {
        $input = $request->validate([
            'site_id' => ['required', 'string'],
            'minutes' => ['nullable', 'integer', 'min:1', 'max:1440'],
            'contains' => ['nullable', 'string', 'max:200'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:1000'],
        ]);
        $site = $this->resolveSite($input['site_id'], $organization);
        if (($site->edgeMeta()['runtime_mode'] ?? '') !== 'container') {
            throw new DplyMcpException('Runtime logs are kept for container apps only. Use get_recent_requests for this app.');
        }

        $lines = EdgeContainerDeployer::appLogLines($site, (int) ($input['minutes'] ?? 15), $input['contains'] ?? null);

        return Response::json(['data' => array_slice($lines, -((int) ($input['limit'] ?? 300)))]);
    }
}
