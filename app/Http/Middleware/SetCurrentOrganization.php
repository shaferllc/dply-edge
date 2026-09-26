<?php

namespace App\Http\Middleware;

use App\Models\Team;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SetCurrentOrganization
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->user()) {
            return $next($request);
        }

        $user = $request->user();

        if (! session()->has('current_organization_id')) {
            $first = $user->organizations()->first();
            if ($first) {
                session(['current_organization_id' => $first->id]);
                $user->rememberCurrentOrganization($first);
            }
        } else {
            // Resolve through the user's organizations() relation so a
            // stale session id returns null instead of a separate exists()
            // round-trip plus a second membership lookup.
            $org = $user->currentOrganization();
            if (! $org) {
                session()->forget('current_organization_id');
                session()->forget('current_team_id');

                $first = $user->organizations()->first();
                if ($first) {
                    session(['current_organization_id' => $first->id]);
                    $user->flushCurrentOrganizationCache();
                    $user->rememberCurrentOrganization($first);
                }
            }
        }

        $orgId = session('current_organization_id');
        if (! $orgId) {
            session()->forget('current_team_id');

            return $next($request);
        }

        // Memo hit for downstream callers; membership is implicit because
        // the org was resolved through organizations().
        $org = $user->currentOrganization();
        if (! $org) {
            session()->forget('current_team_id');

            return $next($request);
        }

        $teamId = session('current_team_id');
        if ($teamId) {
            $valid = Team::query()
                ->whereKey($teamId)
                ->where('organization_id', $org->id)
                ->exists();
            if (! $valid) {
                session()->forget('current_team_id');
            }
        }

        return $next($request);
    }
}
