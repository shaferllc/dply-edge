<?php

declare(strict_types=1);

namespace App\Modules\Edge\Http\Controllers\Api;

use App\Models\Site;
use App\Modules\Edge\Http\Resources\EdgeSiteResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class EdgeSiteApiController extends EdgeApiController
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $organization = $this->organization($request);

        $sites = Site::query()
            ->where('organization_id', $organization->id)
            ->whereNotNull('edge_backend')
            ->where('edge_backend', '!=', '')
            ->orderBy('name')
            ->get()
            ->filter(fn (Site $s): bool => $s->usesEdgeRuntime())
            ->values();

        return EdgeSiteResource::collection($sites);
    }

    public function show(Request $request, string $site): EdgeSiteResource|JsonResponse
    {
        $found = $this->findEdgeSite($request, $site);
        if ($found === null) {
            return $this->notFound();
        }

        return new EdgeSiteResource($found);
    }
}
