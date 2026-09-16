<?php

declare(strict_types=1);

namespace App\Mcp\Tools\Diagnostics;

use App\Mcp\Exceptions\DplyMcpException;
use App\Mcp\Tools\AbstractDplyTool;
use App\Models\ConsoleAction;
use App\Models\Organization;
use App\Models\Site;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;

class GetOperationStatus extends AbstractDplyTool
{
    protected string $name = 'get_operation_status';

    protected string $description = 'Poll the status of an asynchronous site operation (env push, OPcache flush, database create, SSL issue, …) by the operation_id returned when it was queued. Returns its status, label, and log output.';

    protected string $ability = 'sites.read';

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'operation_id' => $schema->string()
                ->description('The operation_id returned by an async tool.')
                ->required(),
        ];
    }

    protected function run(Request $request, Organization $organization): Response
    {
        ['operation_id' => $operationId] = $request->validate([
            'operation_id' => ['required', 'string'],
        ]);

        $action = ConsoleAction::query()->with('subject')->find($operationId);

        if (! $action instanceof ConsoleAction) {
            throw new DplyMcpException("Operation \"{$operationId}\" was not found in this organization.");
        }

        // Org-scope: PR1 async ops are all site-subject. Reject anything we
        // can't tie to a site the token's organization owns.
        $subject = $action->subject;
        if (! $subject instanceof Site || $subject->server?->organization_id !== $organization->id) {
            throw new DplyMcpException("Operation \"{$operationId}\" was not found in this organization.");
        }

        return Response::json([
            'data' => [
                'operation_id' => $action->id,
                'kind' => $action->kind,
                'status' => $action->status,
                'label' => $action->label,
                'site_id' => $subject->id,
                'error' => $action->error,
                'output' => $action->output,
                'started_at' => $action->started_at?->toIso8601String(),
                'finished_at' => $action->finished_at?->toIso8601String(),
            ],
        ]);
    }
}
