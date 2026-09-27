<?php

declare(strict_types=1);

namespace App\Mcp\Concerns;

use App\Mcp\Exceptions\DplyMcpException;
use App\Models\ApiToken;
use App\Models\Organization;
use App\Models\Site;
use App\Models\User;
use App\Support\Sites\SiteApiAccess;

/**
 * Shared auth/org/site context for dply MCP tools AND resources.
 *
 * The `/mcp` route runs behind `auth.api` (App\Http\Middleware\AuthenticateApiToken),
 * which authenticates the dply API token and puts `api_token` + `api_organization`
 * on the underlying HTTP request. The MCP web transport handler runs on that same
 * request, so the global `request()` exposes both here.
 */
trait ResolvesDplyContext
{
    /**
     * The API token authenticated by `auth.api` on the underlying HTTP request.
     */
    protected function token(): ApiToken
    {
        $token = request()->attributes->get('api_token');

        if (! $token instanceof ApiToken) {
            throw new DplyMcpException('Unauthenticated: a valid dply API token is required.');
        }

        return $token;
    }

    /**
     * The organization the token is scoped to. Prefers the request attribute set
     * by `auth.api`; falls back to the token's own relation.
     */
    protected function organization(?ApiToken $token = null): Organization
    {
        $token ??= $this->token();

        $organization = request()->attributes->get('api_organization') ?? $token->organization;

        if (! $organization instanceof Organization) {
            throw new DplyMcpException('No organization is associated with this token.');
        }

        return $organization;
    }

    /**
     * Load a site by id (or slug) and assert it belongs to the token's org.
     */
    protected function resolveSite(string $siteId, ?Organization $organization = null): Site
    {
        $organization ??= $this->organization();

        $site = Site::query()->with('server')->find($siteId)
            ?? Site::query()->with('server')->where('slug', $siteId)->first();

        if (! $site || $site->server?->organization_id !== $organization->id) {
            throw new DplyMcpException("Site \"{$siteId}\" was not found in this organization.");
        }

        $user = $this->token()->user;
        if (! $user instanceof User || ! SiteApiAccess::userCanView($user, $site, $organization)) {
            throw new DplyMcpException("Site \"{$siteId}\" was not found in this organization.");
        }

        return $site;
    }

    /**
     * Resources share the tools' ability gate (tools enforce it in AbstractDplyTool).
     */
    protected function requireAbility(string $ability): void
    {
        if (! $this->token()->allows($ability)) {
            throw new DplyMcpException("This API token lacks the required \"{$ability}\" ability.");
        }
    }
}
