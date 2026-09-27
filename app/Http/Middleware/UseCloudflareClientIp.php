<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Support\DplyRuntime;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * In a container the only way in is the app's Worker, which forwards the
 * visitor's request headers. Cloudflare's edge sets CF-Connecting-IP (and
 * overwrites any a client sent), so it is the visitor's address. Make it the
 * one X-Forwarded-For entry; with every proxy trusted (TRUSTED_PROXIES=*,
 * the container default) $request->ip() then returns it for throttles,
 * the coming-soon allow-list and audit logs.
 */
class UseCloudflareClientIp
{
    public function handle(Request $request, Closure $next): Response
    {
        $ip = $request->headers->get('CF-Connecting-IP');
        if (DplyRuntime::isContainer() && is_string($ip) && filter_var($ip, FILTER_VALIDATE_IP) !== false) {
            $request->headers->set('X-Forwarded-For', $ip);
        }

        return $next($request);
    }
}
