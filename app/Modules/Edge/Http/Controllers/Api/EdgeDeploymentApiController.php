<?php

declare(strict_types=1);

namespace App\Modules\Edge\Http\Controllers\Api;

use App\Models\EdgeDeployment;
use App\Modules\Edge\Actions\DeployEdgeCommit;
use App\Modules\Edge\Actions\RedeployEdgeSite;
use App\Modules\Edge\Actions\RollbackEdgeDeployment;
use App\Modules\Edge\Http\Resources\EdgeDeploymentResource;
use App\Modules\SourceControl\Services\DplyGit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\ValidationException;

class EdgeDeploymentApiController extends EdgeApiController
{
    protected const WRITE_ABILITY = 'deploy';

    public function index(Request $request, string $site): AnonymousResourceCollection|JsonResponse
    {
        $found = $this->findEdgeSite($request, $site);
        if ($found === null) {
            return $this->notFound();
        }

        $limit = min(100, max(1, (int) $request->query('limit', 20)));

        $rows = EdgeDeployment::query()
            ->where('site_id', $found->id)
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get();

        return EdgeDeploymentResource::collection($rows);
    }

    public function show(Request $request, string $site, string $deployment): EdgeDeploymentResource|JsonResponse
    {
        $found = $this->findEdgeSite($request, $site);
        if ($found === null) {
            return $this->notFound();
        }

        $row = EdgeDeployment::query()
            ->where('site_id', $found->id)
            ->find($deployment);

        if ($row === null) {
            return response()->json(['message' => 'Deployment not found.'], 404);
        }

        return new EdgeDeploymentResource($row);
    }

    /**
     * Trigger a deploy. Two flavors via request body:
     *   - { "commit": "abc123" }           → DeployEdgeCommit (re-flips if known)
     *   - { } or { "branch_tip": true }    → RedeployEdgeSite (rebuild from branch HEAD)
     */
    /**
     * A dply Git token for this app (`dply git token`): pushing deploys, so
     * it takes the deploy ability. Write by default; `scope=read` to clone.
     */
    public function gitToken(Request $request, string $site): JsonResponse
    {
        $found = $this->findEdgeSite($request, $site);
        if ($found === null) {
            return $this->notFound();
        }
        if (! DplyGit::siteUses($found)) {
            return response()->json(['message' => __('This app is not on dply Git. Move it under Deploy triggers first.')], 422);
        }

        try {
            $data = $request->validate([
                'scope' => ['nullable', 'in:read,write'],
                'ttl' => ['nullable', 'integer', 'between:60,31536000'],
            ]);
        } catch (ValidationException $e) {
            return response()->json(['message' => $e->getMessage(), 'errors' => $e->errors()], 422);
        }

        try {
            $token = app(DplyGit::class)->tokenForSite($found, (string) ($data['scope'] ?? 'write'), isset($data['ttl']) ? (int) $data['ttl'] : null);
        } catch (\Throwable $e) {
            report($e);

            return response()->json(['message' => $e->getMessage()], 502);
        }

        return response()->json(['data' => $token + ['branch' => (string) ($found->edgeMeta()['source']['branch'] ?? 'main')]], 201);
    }

    public function store(Request $request, string $site): JsonResponse
    {
        $found = $this->findEdgeSite($request, $site);
        if ($found === null) {
            return $this->notFound();
        }

        try {
            $data = $request->validate([
                'commit' => ['nullable', 'string', 'regex:/^[a-fA-F0-9]{7,40}$/'],
                'branch' => ['nullable', 'string', 'max:200'],
                'branch_tip' => ['nullable', 'boolean'],
            ]);
        } catch (ValidationException $e) {
            return response()->json(['message' => $e->getMessage(), 'errors' => $e->errors()], 422);
        }

        try {
            if (! empty($data['commit'])) {
                $branch = isset($data['branch']) && $data['branch'] !== '' ? (string) $data['branch'] : null;
                $deployment = app(DeployEdgeCommit::class)->handle($found, (string) $data['commit'], $branch);
            } else {
                $deployment = app(RedeployEdgeSite::class)->handle($found);
            }
        } catch (\Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return (new EdgeDeploymentResource($deployment->refresh()))
            ->response()
            ->setStatusCode(202);
    }

    /**
     * Re-point production at an existing deployment. The deployment
     * must still have artifacts (not pruned).
     */
    public function rollback(Request $request, string $site, string $deployment): EdgeDeploymentResource|JsonResponse
    {
        $found = $this->findEdgeSite($request, $site);
        if ($found === null) {
            return $this->notFound();
        }

        try {
            $result = app(RollbackEdgeDeployment::class)->handle($found, $deployment);
        } catch (\Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return new EdgeDeploymentResource($result->refresh());
    }
}
