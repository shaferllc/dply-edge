<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ApiToken;
use App\Models\Organization;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AccountApiController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        /** @var ApiToken $token */
        $token = $request->attributes->get('api_token');
        /** @var User $user */
        $user = $request->user();
        /** @var Organization $organization */
        $organization = $request->attributes->get('api_organization');

        return response()->json([
            'data' => [
                'user' => $this->userPayload($user),
                'organization' => $this->organizationPayload($organization, $user, true, true),
                'token' => $this->tokenPayload($token, true),
            ],
        ]);
    }

    public function projects(Request $request): JsonResponse
    {
        /** @var Organization $organization */
        $organization = $request->attributes->get('api_organization');
        /** @var User $user */
        $user = $request->user();

        $query = $organization->workspaces()
            ->withCount(['servers', 'sites'])
            ->orderBy('name');

        if (! $organization->hasAdminAccess($user)) {
            $query->whereHas('members', fn ($members) => $members->where('user_id', $user->id));
        }

        $rows = $query->get()->map(fn (Workspace $workspace): array => [
            'id' => (string) $workspace->id,
            'name' => (string) $workspace->name,
            'slug' => (string) $workspace->slug,
            'servers_count' => (int) $workspace->servers_count,
            'sites_count' => (int) $workspace->sites_count,
            'role' => $workspace->memberRole($user),
        ])->values()->all();

        return response()->json(['data' => $rows]);
    }

    public function organizations(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        /** @var Organization $current */
        $current = $request->attributes->get('api_organization');

        $rows = $user->organizations()
            ->orderBy('name')
            ->get()
            ->map(fn (Organization $org): array => $this->organizationPayload(
                $org,
                $user,
                $org->id === $current->id,
            ))
            ->values()
            ->all();

        return response()->json(['data' => $rows]);
    }

    public function sessions(Request $request): JsonResponse
    {
        /** @var ApiToken $currentToken */
        $currentToken = $request->attributes->get('api_token');
        /** @var User $user */
        $user = $request->user();
        /** @var Organization $organization */
        $organization = $request->attributes->get('api_organization');

        $rows = $this->cliSessionQuery($organization, $user)
            ->orderByDesc('last_used_at')
            ->orderByDesc('created_at')
            ->get()
            ->map(fn (ApiToken $session): array => $this->tokenPayload(
                $session,
                $session->id === $currentToken->id,
            ))
            ->values()
            ->all();

        return response()->json(['data' => $rows]);
    }

    public function destroySession(Request $request, string $apiToken): JsonResponse
    {
        /** @var ApiToken $currentToken */
        $currentToken = $request->attributes->get('api_token');
        /** @var User $user */
        $user = $request->user();
        /** @var Organization $organization */
        $organization = $request->attributes->get('api_organization');

        $session = ApiToken::query()
            ->whereKey($apiToken)
            ->where('organization_id', $organization->id)
            ->where('name', (string) config('cli.token_name', 'dply CLI'))
            ->first();

        if ($session === null) {
            return response()->json(['message' => 'CLI session not found.'], 404);
        }

        if (! $organization->hasAdminAccess($user) && $session->user_id !== $user->id) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $revokedCurrent = $session->id === $currentToken->id;
        $session->delete();

        return response()->json([
            'message' => $revokedCurrent
                ? 'CLI session revoked. This token no longer works.'
                : 'CLI session revoked.',
            'revoked_current' => $revokedCurrent,
        ]);
    }

    /**
     * @return array{id: string, name: string, email: string}
     */
    protected function userPayload(User $user): array
    {
        return [
            'id' => (string) $user->id,
            'name' => (string) $user->name,
            'email' => (string) $user->email,
        ];
    }

    /**
     * @return array{id: string, name: string, role: string|null, is_current?: bool, projects_count?: int}
     */
    protected function organizationPayload(Organization $organization, User $user, bool $isCurrent = false, bool $includeProjectsCount = false): array
    {
        $payload = [
            'id' => (string) $organization->id,
            'name' => (string) $organization->name,
            'role' => $this->organizationRole($organization, $user),
            'is_current' => $isCurrent,
        ];

        if ($includeProjectsCount) {
            $query = $organization->workspaces();
            if (! $organization->hasAdminAccess($user)) {
                $query->whereHas('members', fn ($members) => $members->where('user_id', $user->id));
            }
            $payload['projects_count'] = $query->count();
        }

        return $payload;
    }

    /**
     * @return array<string, mixed>
     */
    protected function tokenPayload(ApiToken $token, bool $isCurrent = false): array
    {
        $token->loadMissing('user:id,name,email');

        return [
            'id' => (string) $token->id,
            'name' => (string) $token->name,
            'prefix' => (string) $token->token_prefix,
            'masked' => $token->masked_display,
            'abilities' => $token->abilities ?? [],
            'user' => $token->user ? $this->userPayload($token->user) : null,
            'last_used_at' => $token->last_used_at?->toIso8601String(),
            'expires_at' => $token->expires_at?->toIso8601String(),
            'created_at' => $token->created_at->toIso8601String(),
            'is_cli' => $token->name === (string) config('cli.token_name', 'dply CLI'),
            'is_current' => $isCurrent,
        ];
    }

    /**
     * @return Builder<ApiToken>
     */
    protected function cliSessionQuery(Organization $organization, User $user)
    {
        $query = ApiToken::query()
            ->where('organization_id', $organization->id)
            ->where('name', (string) config('cli.token_name', 'dply CLI'))
            ->whereNull('revoked_at')
            ->with('user:id,name,email');

        if (! $organization->hasAdminAccess($user)) {
            $query->where('user_id', $user->id);
        }

        return $query;
    }

    protected function organizationRole(Organization $organization, User $user): ?string
    {
        $membership = $user->organizations()
            ->whereKey($organization->id)
            ->first();

        $role = data_get($membership?->pivot, 'role');

        return is_string($role) && $role !== '' ? $role : null;
    }
}
