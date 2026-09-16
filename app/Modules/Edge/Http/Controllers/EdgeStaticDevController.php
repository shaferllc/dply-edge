<?php

declare(strict_types=1);

namespace App\Modules\Edge\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\EdgeDeployment;
use App\Modules\Edge\Services\FakeEdgeBackend;
use App\Modules\Edge\Support\FakeEdgeProvision;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Serves FakeEdgeBackend artifacts in local/testing when production
 * Cloudflare Worker delivery is unavailable.
 */
class EdgeStaticDevController extends Controller
{
    public function __invoke(Request $request, string $slug, ?string $path = null): Response|BinaryFileResponse
    {
        if (! FakeEdgeProvision::enabled()) {
            abort(404);
        }

        /** @var array<string, mixed>|null $routing */
        $routing = $request->attributes->get('edge.routing');
        if (! is_array($routing)) {
            abort(404);
        }

        $storagePrefix = (string) ($routing['storage_prefix'] ?? '');
        if ($storagePrefix === '') {
            abort(404);
        }

        $deploymentId = (string) ($routing['deployment_id'] ?? '');
        $deployment = EdgeDeployment::query()->find($deploymentId);
        if ($deployment === null) {
            abort(404);
        }

        $relativePath = $this->normalizePath($path);
        $backend = new FakeEdgeBackend;
        $file = $backend->localFilePath($deployment, $relativePath);

        $spaFallback = (bool) ($routing['spa_fallback'] ?? true);
        if ($file === null && $spaFallback && $relativePath !== 'index.html') {
            $file = $backend->localFilePath($deployment, 'index.html');
            $relativePath = 'index.html';
        }

        if ($file === null || ! is_file($file)) {
            abort(404);
        }

        $headers = [
            'Cache-Control' => $this->cacheControlFor($relativePath),
            'X-Dply-Deployment-Id' => $deployment->id,
        ];

        $extra = is_array($routing['headers'] ?? null) ? $routing['headers'] : [];
        foreach ($extra as $name => $value) {
            if (is_string($name) && is_string($value)) {
                $headers[$name] = $value;
            }
        }

        return response()->file($file, $headers);
    }

    private function normalizePath(?string $path): string
    {
        $path = trim((string) $path, '/');
        if ($path === '') {
            return 'index.html';
        }

        if (str_contains($path, '..')) {
            abort(404);
        }

        return $path;
    }

    private function cacheControlFor(string $path): string
    {
        if ($path === 'index.html' || str_ends_with($path, '/index.html')) {
            return 'public, max-age=0, must-revalidate';
        }
        if (preg_match('/\.[a-f0-9]{8,}\./', $path) === 1) {
            return 'public, max-age=31536000, immutable';
        }

        return 'public, max-age=3600';
    }
}
