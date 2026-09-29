<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\EdgeDeployment;
use App\Models\Site;
use App\Modules\Edge\Actions\CancelStuckEdgeDeployment;
use App\Modules\Edge\Actions\RedeployEdgeSite;
use App\Modules\Edge\Support\EdgeDeployProgress;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * The deploy pill's JSON (resources/js/deploy-pill.js): which deploys to
 * show, the last lines of an expanded one, and its Cancel / Redeploy.
 * Pushes on the org channel only move pills this endpoint has listed, so
 * this is where app roles are enforced.
 */
class EdgeDeployPillController extends Controller
{
    /**
     * In-flight deploys, plus any the page is still showing (`watch[]`) so a
     * pill that missed its finish push, with Reverb down, still resolves.
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $deploys = EdgeDeployProgress::inFlightFor($user);
        $listed = array_column($deploys, 'id');

        $watch = array_diff(array_slice(array_filter((array) $request->query('watch', []), 'is_string'), 0, 20), $listed);
        if ($watch !== []) {
            EdgeDeployment::query()->whereKey($watch)->with('site')->get()
                ->filter(fn (EdgeDeployment $d): bool => $d->site !== null && Gate::forUser($user)->allows('view', $d->site))
                ->each(function (EdgeDeployment $d) use (&$deploys): void {
                    $deploys[] = EdgeDeployProgress::payload($d);
                });
        }

        return response()->json(['deploys' => array_map(fn (array $d): array => $d + [
            'can_deploy' => ($site = Site::query()->find($d['site_id'])) !== null && Gate::forUser($user)->allows('deploy', $site),
        ], $deploys)]);
    }

    /**
     * New log lines since `offset`, for an expanded pill. A negative offset
     * means "just the end": a container build log runs to megabytes.
     */
    public function tail(Request $request, EdgeDeployment $deployment): JsonResponse
    {
        Gate::authorize('view', $deployment->site);

        $offset = (int) $request->query('offset', -1);
        if ($offset < 0) {
            $path = $deployment->resolveLocalBuildLogPath();
            $offset = $path !== null && is_readable($path) ? max(0, (int) filesize($path) - 4_000) : 0;
        }
        $read = $deployment->readLocalBuildLogSince($offset, 64_000);
        $lines = array_values(array_filter(
            array_map(static fn (string $line): string => rtrim((string) preg_replace('/\e\[[0-9;?]*[A-Za-z]/', '', $line)), preg_split('/\R/', $read['body']) ?: []),
            static fn (string $line): bool => $line !== '' && ! str_starts_with($line, '[dply:step]'),
        ));

        return response()->json(['lines' => array_slice($lines, -8), 'offset' => $read['offset']]);
    }

    public function cancel(EdgeDeployment $deployment): JsonResponse
    {
        Gate::authorize('deploy', $deployment->site);
        abort_unless(in_array($deployment->status, EdgeDeployProgress::IN_FLIGHT, true), 409, __('This deploy already finished.'));

        app(CancelStuckEdgeDeployment::class)->abandon($deployment->site, $deployment);

        return response()->json(['ok' => true]);
    }

    public function redeploy(EdgeDeployment $deployment): JsonResponse
    {
        Gate::authorize('deploy', $deployment->site);

        $next = (new RedeployEdgeSite)->handle($deployment->site, $deployment->git_commit);

        return response()->json(['deploy' => EdgeDeployProgress::payload($next) + ['can_deploy' => true]]);
    }
}
