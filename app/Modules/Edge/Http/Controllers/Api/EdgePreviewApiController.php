<?php

declare(strict_types=1);

namespace App\Modules\Edge\Http\Controllers\Api;

use App\Models\Site;
use App\Modules\Edge\Actions\CreateEdgePreviewSite;
use App\Modules\Edge\Actions\PromoteEdgePreview;
use App\Modules\Edge\Http\Resources\EdgeDeploymentResource;
use App\Modules\Edge\Http\Resources\EdgeSiteResource;
use App\Modules\Edge\Jobs\TeardownEdgeSiteJob;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\ValidationException;

class EdgePreviewApiController extends EdgeApiController
{
    public function index(Request $request, string $site): AnonymousResourceCollection|JsonResponse
    {
        $found = $this->findEdgeSite($request, $site);
        if ($found === null || $found->isEdgePreview()) {
            return $this->notFound();
        }

        $previews = CreateEdgePreviewSite::listForParent($found)->values();

        return EdgeSiteResource::collection($previews);
    }

    public function store(Request $request, string $site): JsonResponse
    {
        $found = $this->findEdgeSite($request, $site);
        if ($found === null || $found->isEdgePreview()) {
            return $this->notFound();
        }

        try {
            $data = $request->validate([
                'commit' => ['required', 'string', 'regex:/^[a-fA-F0-9]{7,40}$/'],
                'branch' => ['nullable', 'string', 'max:200'],
                'ref_kind' => ['nullable', 'string', 'in:branch,tag,commit'],
            ]);
        } catch (ValidationException $e) {
            return response()->json(['message' => $e->getMessage(), 'errors' => $e->errors()], 422);
        }

        $branch = isset($data['branch']) && $data['branch'] !== ''
            ? (string) $data['branch']
            : (string) ($found->edgeMeta()['source']['branch'] ?? 'main');
        $refKind = isset($data['ref_kind']) ? (string) $data['ref_kind'] : null;

        try {
            $preview = app(CreateEdgePreviewSite::class)->handleAdhoc(
                $found,
                $branch,
                (string) $data['commit'],
                $refKind,
            );
        } catch (\Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return (new EdgeSiteResource($preview))
            ->response()
            ->setStatusCode(202);
    }

    public function destroy(Request $request, string $site, string $preview): JsonResponse
    {
        $found = $this->findEdgeSite($request, $site);
        if ($found === null || $found->isEdgePreview()) {
            return $this->notFound();
        }

        $previewSite = Site::query()->find($preview);
        if ($previewSite === null
            || $previewSite->organization_id !== $found->organization_id
            || ($previewSite->edgeMeta()['preview_parent_site_id'] ?? null) !== $found->id) {
            return response()->json(['message' => 'Preview not found.'], 404);
        }

        TeardownEdgeSiteJob::dispatch($previewSite->id);

        return response()->json(['message' => 'Preview teardown queued.'], 202);
    }

    /**
     * Copy a preview's artifacts into a fresh parent prefix and flip
     * the parent's host map. The preview keeps running.
     */
    public function promote(Request $request, string $site, string $preview): EdgeDeploymentResource|JsonResponse
    {
        $found = $this->findEdgeSite($request, $site);
        if ($found === null || $found->isEdgePreview()) {
            return $this->notFound();
        }

        try {
            $deployment = app(PromoteEdgePreview::class)->handle($found, $preview);
        } catch (\Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return new EdgeDeploymentResource($deployment->refresh());
    }
}
