<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * A signed-in user who has not accepted the current Terms, Privacy Policy
 * and AUP (config legal.version) is sent to the accept page on the next page
 * load. OAuth sign-ups land here first; everyone else when the version
 * changes. Only full GET page loads redirect: form posts, Livewire updates,
 * JSON and the legal pages themselves pass, so nothing in flight breaks.
 */
class EnsureCurrentTermsAccepted
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if ($user === null || ! $request->isMethod('GET') || $request->expectsJson() || $request->hasHeader('X-Livewire')
            || $request->routeIs('legal.*', 'logout', 'verification.*', 'password.*', 'two-factor.*')
            || $user->acceptedCurrentTerms()) {
            return $next($request);
        }

        return redirect()->guest(route('legal.accept'));
    }
}
