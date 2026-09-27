<?php

namespace App\Http\Middleware;

use App\Models\ApiToken;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\IpUtils;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateApiToken
{
    public function handle(Request $request, Closure $next): Response
    {
        $plaintext = $this->getTokenFromRequest($request);

        if (! $plaintext) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        $token = ApiToken::findTokenByPlaintext($plaintext);

        if (! $token) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        if (! $token->isValid()) {
            return response()->json(['message' => 'Token expired or invalid'], 401);
        }

        $allowed = $token->allowed_ips;
        if (is_array($allowed) && $allowed !== []) {
            $ip = (string) $request->ip();
            // The Dply\Core\Net\IpAllowList helper this used to call was
            // never extracted into a real package. Symfony ships an
            // equivalent helper that handles both exact IPs and CIDR.
            if (! IpUtils::checkIp($ip, $allowed)) {
                return response()->json(['message' => 'Forbidden'], 403);
            }
        }

        $token->touchLastUsed();

        $user = $token->user;
        if ($user === null) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        // A token never outlives its user's membership of the token's org.
        if (! $token->organization?->hasMember($user)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        auth()->setUser($user);
        $request->setUserResolver(fn () => $user);
        $request->attributes->set('api_token', $token);
        $request->attributes->set('api_organization', $token->organization);

        return $next($request);
    }

    protected function getTokenFromRequest(Request $request): ?string
    {
        $header = $request->header('Authorization');
        if (preg_match('/^Bearer\s+(.+)$/i', $header, $m)) {
            return $m[1];
        }

        return $request->header('X-API-Key');
    }
}
