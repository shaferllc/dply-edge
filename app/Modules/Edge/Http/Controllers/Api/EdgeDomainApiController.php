<?php

declare(strict_types=1);

namespace App\Modules\Edge\Http\Controllers\Api;

use App\Modules\Edge\Services\EdgeCustomDomainProvisioner;
use App\Modules\Edge\Services\EdgeRouter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class EdgeDomainApiController extends EdgeApiController
{
    public function index(Request $request, string $site): JsonResponse
    {
        $found = $this->findEdgeSite($request, $site);
        if ($found === null) {
            return $this->notFound();
        }

        $routing = is_array($found->edgeMeta()['routing'] ?? null) ? $found->edgeMeta()['routing'] : [];
        $domains = is_array($routing['custom_domains'] ?? null) ? $routing['custom_domains'] : [];

        $payload = [];
        foreach ($domains as $hostname => $info) {
            if (! is_string($hostname) || $hostname === '') {
                continue;
            }
            $info = is_array($info) ? $info : [];
            $payload[] = [
                'hostname' => strtolower($hostname),
                'mode' => $info['mode'] ?? 'manual',
                'dns_status' => $info['dns_status'] ?? 'unknown',
                'ssl_status' => $info['ssl_status'] ?? null,
                'cname_target' => $info['cname_target'] ?? $found->edgeHostname(),
                'cf_custom_hostname_id' => $info['cf_custom_hostname_id'] ?? null,
                'ownership_verification' => $info['ownership_verification'] ?? null,
                'dply_verification' => $info['dply_verification'] ?? null,
                'analytics_zone' => $info['analytics_zone'] ?? null,
                'attached_at' => $info['attached_at'] ?? null,
                'verified_at' => $info['verified_at'] ?? null,
                'error' => $info['error'] ?? null,
                'ssl_error' => $info['ssl_error'] ?? null,
            ];
        }

        return response()->json(['data' => $payload]);
    }

    public function store(Request $request, string $site): JsonResponse
    {
        $found = $this->findEdgeSite($request, $site);
        if ($found === null) {
            return $this->notFound();
        }

        try {
            $data = $request->validate([
                'hostname' => ['required', 'string', 'max:253', 'regex:/^[A-Za-z0-9](?:[A-Za-z0-9-]*[A-Za-z0-9])?(?:\.[A-Za-z0-9](?:[A-Za-z0-9-]*[A-Za-z0-9])?)+$/'],
            ]);
        } catch (ValidationException $e) {
            return response()->json(['message' => $e->getMessage(), 'errors' => $e->errors()], 422);
        }

        $hostname = strtolower(trim((string) $data['hostname']));
        $backend = EdgeRouter::backendFor($found);
        if ($backend === null) {
            return response()->json(['message' => 'No edge backend available for this site.'], 422);
        }

        try {
            $entries = $backend->attachDomain($found->fresh(), $hostname);
        } catch (\Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['data' => $entries], 202);
    }

    public function verify(Request $request, string $site, string $hostname): JsonResponse
    {
        $found = $this->findEdgeSite($request, $site);
        if ($found === null) {
            return $this->notFound();
        }

        $entry = app(EdgeCustomDomainProvisioner::class)->verify($found->fresh(), $hostname);

        return response()->json(['data' => $entry]);
    }

    public function destroy(Request $request, string $site, string $hostname): JsonResponse
    {
        $found = $this->findEdgeSite($request, $site);
        if ($found === null) {
            return $this->notFound();
        }

        try {
            app(EdgeCustomDomainProvisioner::class)->remove($found->fresh(), $hostname);
        } catch (\Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['message' => 'Custom domain removed.'], 200);
    }
}
