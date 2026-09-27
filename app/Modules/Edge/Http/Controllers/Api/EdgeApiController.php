<?php

declare(strict_types=1);

namespace App\Modules\Edge\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Organization;
use App\Models\Site;
use App\Models\User;
use Illuminate\Contracts\Auth\Access\Gate as GateContract;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Shared lookup helpers for the public /api/v1/edge/* surface. Every
 * Edge API controller extends this so org scoping + the Edge-specific
 * 404 ("site exists but is not an Edge site") stay consistent.
 *
 * Payload shaping lives in {@see \App\Modules\Edge\Http\Resources\*Resource}
 * classes — the base no longer carries inline `toArray()` builders.
 */
abstract class EdgeApiController extends Controller
{
    /** The site ability a write through this controller needs: 'update' (configure) or 'deploy'. */
    protected const WRITE_ABILITY = 'update';

    protected function organization(Request $request): Organization
    {
        $organization = $request->attributes->get('api_organization');
        if (! $organization instanceof Organization) {
            abort(401);
        }

        return $organization;
    }

    /**
     * Look up an Edge site by ID within the request's organization.
     * Returns null when the site does not exist, belongs to another
     * org, or is not an Edge site — callers turn that into a 404.
     */
    protected function findEdgeSite(Request $request, string $siteId): ?Site
    {
        $organization = $this->organization($request);

        $site = Site::query()
            ->where('organization_id', $organization->id)
            ->find($siteId);

        if ($site === null || ! $site->usesEdgeRuntime()) {
            return null;
        }

        self::authorizeTokenUser($request, $site, static::WRITE_ABILITY);

        return $site;
    }

    /**
     * Token abilities say what the token may do; the site policy says what its
     * user may do. Both must allow it, so a token never outranks its user —
     * reads need 'view', writes $writeAbility ('update', or 'deploy' for the
     * deployment/preview/cache endpoints). Policies resolve against the user's
     * current organization, so it is pinned to the token's.
     */
    public static function authorizeTokenUser(Request $request, Site $site, string $writeAbility = 'update'): void
    {
        $gate = self::tokenUserGate($request);
        $ability = $request->isMethodSafe() ? 'view' : $writeAbility;

        if ($gate === null || $gate->denies($ability, $site)) {
            abort(403, 'Your role does not allow this on this site.');
        }
    }

    /** The gate for the token's user (AuthenticateApiToken already checked org membership). */
    protected static function tokenUserGate(Request $request): ?GateContract
    {
        $user = $request->user();
        $organization = $request->attributes->get('api_organization');
        if (! $user instanceof User || ! $organization instanceof Organization) {
            return null;
        }

        $user->rememberCurrentOrganization($organization);

        return Gate::forUser($user);
    }

    /** Org-level writes (D1 queries, queue messages) need org admin, as in the dashboard. */
    protected function authorizeOrganizationWrite(Request $request): void
    {
        $gate = self::tokenUserGate($request);
        if ($gate === null || $gate->denies('update', $this->organization($request))) {
            abort(403, 'Your role does not allow this in this organization.');
        }
    }

    protected function notFound(string $message = 'Edge site not found.'): JsonResponse
    {
        return response()->json(['message' => $message], 404);
    }
}
