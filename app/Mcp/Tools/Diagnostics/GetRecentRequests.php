<?php

declare(strict_types=1);

namespace App\Mcp\Tools\Diagnostics;

use App\Mcp\Tools\AbstractDplyTool;
use App\Models\Organization;
use App\Modules\Edge\Support\EdgeAnalyticsEngineTraffic;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;

class GetRecentRequests extends AbstractDplyTool
{
    protected string $name = 'get_recent_requests';

    protected string $description = 'Requests the edge served for one app, newest first: time, method, host, path, status, duration and cache status. Set min_status to 400 to see only errors.';

    protected string $ability = 'edge.read';

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'site_id' => $schema->string()->description('The app id (or slug).')->required(),
            'minutes' => $schema->integer()->description('How far back, 1-1440. Default 60.'),
            'min_status' => $schema->integer()->description('Only responses with at least this status, e.g. 400 or 500.'),
            'limit' => $schema->integer()->description('Rows, 1-200. Default 100.'),
        ];
    }

    protected function run(Request $request, Organization $organization): Response
    {
        $input = $request->validate([
            'site_id' => ['required', 'string'],
            'minutes' => ['nullable', 'integer', 'min:1', 'max:1440'],
            'min_status' => ['nullable', 'integer', 'min:100', 'max:599'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:200'],
        ]);
        $site = $this->resolveSite($input['site_id'], $organization);
        $min = (int) ($input['min_status'] ?? 0);

        // Filtered in the query: filtering a 200-row read lost a busy app's older errors.
        $rows = app(EdgeAnalyticsEngineTraffic::class)->recent($site, now()->subMinutes((int) ($input['minutes'] ?? 60)), (int) ($input['limit'] ?? 100), $min);

        return Response::json(['data' => $rows]);
    }
}
