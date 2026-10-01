<?php

declare(strict_types=1);

namespace App\Mcp\Tools\Diagnostics;

use App\Mcp\Tools\AbstractDplyTool;
use App\Models\EdgeDeployment;
use App\Models\Organization;
use App\Modules\Edge\Support\EdgeContainerInstances;
use App\Modules\Edge\Support\EdgeContainerSettings;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;

class GetSiteHealth extends AbstractDplyTool
{
    protected string $name = 'get_site_health';

    protected string $description = 'Where one app stands right now: the live deploy and the latest one (with its failure reason), whether billing has paused the organization, and for a container app its size, sleep setting, instance ceiling and each instance\'s live state (running, asleep, last activity). Start here when something is wrong.';

    protected string $ability = 'edge.read';

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'site_id' => $schema->string()->description('The app id (or slug).')->required(),
        ];
    }

    protected function run(Request $request, Organization $organization): Response
    {
        ['site_id' => $siteId] = $request->validate(['site_id' => ['required', 'string']]);
        $site = $this->resolveSite($siteId, $organization);
        $mode = (string) ($site->edgeMeta()['runtime_mode'] ?? 'static');

        $deploy = static fn (?EdgeDeployment $d): ?array => $d === null ? null : [
            'id' => $d->id,
            'status' => $d->status,
            'commit' => $d->git_commit,
            'created_at' => $d->created_at?->toIso8601String(),
            'failure_reason' => $d->failure_reason,
        ];
        $latest = EdgeDeployment::query()->where('site_id', $site->id)->latest()->first();
        $live = EdgeDeployment::query()->where('site_id', $site->id)->where('status', EdgeDeployment::STATUS_LIVE)->latest()->first();

        $data = [
            'site_id' => $site->id,
            'name' => $site->name,
            'runtime_mode' => $mode,
            'live_url' => $site->edgeLiveUrl(),
            'paused_by_owner' => ! empty($site->edgeMeta()['paused_at']),
            'organization_billing_paused' => $organization->billing_paused_at !== null,
            'live_deployment' => $deploy($live),
            'latest_deployment' => $deploy($latest),
        ];

        if ($mode === 'container') {
            $settings = EdgeContainerSettings::for($site);
            $data['container'] = [
                'instance_type' => $settings['instance_type'],
                'max_instances' => $settings['max_instances'],
                'min_instances' => $settings['min_instances'],
                'sleep_after' => $settings['sleep_after'],
                'scheduler' => $settings['scheduler'],
                'live' => EdgeContainerInstances::snapshot($site),
            ];
        }

        return Response::json(['data' => $data]);
    }
}
