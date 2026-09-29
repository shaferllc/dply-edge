<?php

declare(strict_types=1);

namespace App\Events\Edge;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A deploy started, moved to a new step, or finished. Moves the deploy pill
 * on every open page in the organization (resources/js/deploy-pill.js).
 *
 * The payload is EdgeDeployProgress::payload(): step and status, never log
 * text. The pill trusts a push only for deploys its own fetch listed, since
 * the org channel reaches members whose app role hides that app.
 */
final class EdgeDeploymentProgressed implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets;

    /** @param  array<string, mixed>  $deployment */
    public function __construct(
        public readonly string $organizationId,
        public readonly array $deployment,
    ) {}

    /** @return list<PrivateChannel> */
    public function broadcastOn(): array
    {
        return [new PrivateChannel('organization.'.$this->organizationId)];
    }

    public function broadcastAs(): string
    {
        return 'edge.deployment.progress';
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        // The failure text is build output: members whose app role hides the
        // app share this channel. The pill fetches it through /deploy-pill.
        return array_diff_key($this->deployment, ['failure' => true]);
    }
}
