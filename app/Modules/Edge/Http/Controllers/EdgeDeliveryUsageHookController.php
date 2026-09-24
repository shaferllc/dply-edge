<?php

declare(strict_types=1);

namespace App\Modules\Edge\Http\Controllers;

use App\Models\EdgeDeliveryUsage;
use App\Models\Site;
use App\Modules\Edge\Services\Containers\EdgeContainerDeployer;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * The site worker records each HTTP delivery here. The token is the same
 * one injected as DPLY_QUEUE_TOKEN. The publish token never reaches the app.
 *
 * User request: "ok then lets build that out and we need to charge for it".
 */
class EdgeDeliveryUsageHookController
{
    public function __invoke(Request $request, Site $site): Response
    {
        $token = (string) $request->header('x-dply-queue-token', '');
        if ($token === '' || ! hash_equals(EdgeContainerDeployer::queueToken($site), $token)) {
            return response('Forbidden', 403);
        }

        $messages = max(0, min(1000, (int) $request->input('messages', 1)));
        $bytes = max(0, min(10_000_000, (int) $request->input('bytes', 0)));
        if ($messages === 0) {
            return response('OK', 204);
        }

        $row = EdgeDeliveryUsage::query()->firstOrCreate(
            ['site_id' => $site->id, 'date' => now()->toDateString()],
            ['organization_id' => $site->organization_id, 'messages' => 0, 'bandwidth_bytes' => 0],
        );
        $row->increment('messages', $messages);
        $row->increment('bandwidth_bytes', $bytes);

        return response('OK', 204);
    }
}
