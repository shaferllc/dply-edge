<?php

declare(strict_types=1);

namespace App\Modules\Edge\Services;

use App\Models\Site;
use App\Modules\Edge\Jobs\TeardownEdgeSiteJob;

class EdgeSiteCanceller
{
    /**
     * Queue teardown (default) so cancel UX is not blocked on Cloudflare /
     * R2 cleanup. Pass $sync=true when the caller must wait for deletion
     * (e.g. admin delete that asserts the row is gone before responding).
     */
    public function cancel(Site $site, bool $sync = false): void
    {
        if (! $site->usesEdgeRuntime()) {
            throw new \InvalidArgumentException('Site is not an Edge site.');
        }

        $site->forceFill(['status' => Site::STATUS_EDGE_DELETING])->save();

        if ($sync) {
            TeardownEdgeSiteJob::dispatchSync($site->id);

            return;
        }

        TeardownEdgeSiteJob::dispatch($site->id);
    }
}
