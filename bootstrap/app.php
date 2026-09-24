<?php

use App\Console\Scheduling\DplySchedule;
use App\Http\Middleware\AuthenticateApiToken;
use App\Http\Middleware\EnforceMaintenanceMode;
use App\Http\Middleware\EnsureApiTokenAbility;
use App\Http\Middleware\RedirectGuestsToComingSoon;
use App\Http\Middleware\SetCurrentOrganization;
use App\Http\Middleware\StampDebugReference;
use App\Models\Site;
use App\Modules\Edge\Http\Middleware\ResolveEdgeCustomDomain;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use App\Support\Debug\DebugExceptionDetail;
use App\Support\DplyRuntime;
use App\Support\Http\ScannerProbePaths;
use App\Support\MachineCallbackPaths;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Laravel\Pennant\Middleware\EnsureFeaturesAreActive;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        channels: __DIR__.'/../routes/channels.php',
        health: '/up',
    )
    ->withSchedule(function (Schedule $schedule): void {
        if (! DplyRuntime::runsScheduler()) {
            return;
        }

        DplySchedule::register($schedule);
    })
    ->withMiddleware(function (Middleware $middleware): void {
        $trustedProxies = trim((string) env('TRUSTED_PROXIES', ''));
        if ($trustedProxies !== '') {
            $at = $trustedProxies === '*'
                ? '*'
                : array_values(array_filter(array_map('trim', explode(',', $trustedProxies))));
            $middleware->trustProxies(at: $at);
        }

        // Stamp X-Dply-Ref (the Lookout occurrence id) on 5xx responses so a
        // reference can be quoted by users and resolved by admins.
        $middleware->append(StampDebugReference::class);

        $middleware->alias([
            'org' => SetCurrentOrganization::class,
            'auth.api' => AuthenticateApiToken::class,
            'ability' => EnsureApiTokenAbility::class,
            'feature' => EnsureFeaturesAreActive::class,
        ]);
        // Machine/external callback paths come from the single canonical list
        // (App\Support\MachineCallbackPaths) the guest gates also use, so a new
        // webhook can't be CSRF-exempt-but-gate-blocked (or vice-versa). The `up`
        // health route in that list is a harmless extra here (GETs aren't CSRF
        // checked); webauthn is CSRF-specific so it's appended separately.
        $middleware->preventRequestForgery(except: array_merge(
            MachineCallbackPaths::PATTERNS,
            [
                // Passkey ceremony endpoints (cross-origin, token-auth'd).
                'webauthn/*',
            ],
        ));

        // Custom-domain short-circuit MUST run before the normal web stack
        // so a request to `api.acme.com/` doesn't fall through to the
        // marketing welcome view (which has no host constraint on /).
        $middleware->prependToGroup('web', [
            ResolveEdgeCustomDomain::class,
        ]);

        $middleware->appendToGroup('web', [
            EnforceMaintenanceMode::class,
            RedirectGuestsToComingSoon::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Stash a short exception summary for the branded 500 page's
        // collapsible "Technical details" panel (signed-in operators).
        // Runs on every render path (return null = keep default handling).
        $exceptions->render(function (Throwable $e) {
            DebugExceptionDetail::remember($e);

            return null;
        });

        // The workspace is still open when teardown deletes the app. The next
        // Livewire update looks the site up by id and would otherwise render
        // the exception page. Send that request to the dashboard.
        $exceptions->render(function (ModelNotFoundException $e, Request $request) {
            if ($e->getModel() !== Site::class) {
                return null;
            }

            if (! $request->is('projects/*') && ! $request->hasHeader('X-Livewire') && ! $request->hasHeader('X-Livewire-Navigate')) {
                return null;
            }

            return redirect()->route('dashboard');
        });

        // Friendly handler for cache/queue backend connection failures. With
        // CACHE_STORE=redis (or QUEUE_CONNECTION=redis) pointing at a managed
        // Redis box, an outage means every page render touches a dead Redis
        // connection. config/database.php sets a 2s timeout so this surfaces
        // FAST as a RedisException — without this render handler the operator
        // sees a raw stack trace; with it they get a diagnostic page that
        // names which env vars to inspect.
        $exceptions->render(function (RedisException $e, Request $request) {
            $payload = [
                'error' => 'redis_unreachable',
                'message' => $e->getMessage(),
                'host' => (string) env('REDIS_HOST', '127.0.0.1'),
                'port' => (string) env('REDIS_PORT', '6379'),
                'cacheStore' => (string) env('CACHE_STORE', 'database'),
                'queueConnection' => (string) env('QUEUE_CONNECTION', 'sync'),
                'timeout' => (string) env('REDIS_TIMEOUT', '2.0'),
            ];

            // True API callers (Accept: application/json, no X-Livewire) get
            // the raw payload so they can act programmatically. Everything
            // else — GET pages, plain POSTs, AND Livewire updates — gets the
            // rendered HTML diagnostic. Livewire's POST returning HTML 503
            // surfaces in the browser as a navigation to the response body,
            // which is what we want here: a self-contained error page the
            // operator can read regardless of how the request originated.
            $isApiClient = $request->expectsJson() && ! $request->hasHeader('X-Livewire');

            if ($isApiClient) {
                return response()->json($payload, 503);
            }

            return response()->view('errors.redis-unreachable', $payload, 503);
        });

        // A 404 raised inside a Livewire request must NOT render the full-page
        // errors.layout: that view carries <x-site-header />, so when Livewire
        // morphs (wire:navigate) or injects (update overlay) it into a page that
        // already has the header, you get a duplicated header and a broken-looking
        // nested 404. Serve a chrome-less variant to Livewire requests instead —
        // it morphs in cleanly and offers a Refresh, since the usual cause is a
        // stale snapshot pointing at a route/resource that has since moved.
        // (API callers still fall through to Laravel's JSON 404.)
        $exceptions->render(function (NotFoundHttpException $e, Request $request) {
            $previous = $e->getPrevious();
            if ($previous instanceof ModelNotFoundException && $previous->getModel() === Site::class) {
                return redirect()->route('dashboard');
            }

            // Known scanner/bot probes (wp-*, *.env, /actuator, leaked-secret
            // fishing, …) flood Lookout's RequestHandled 404 reporter with noise.
            // That reporter fires ONLY on status === 404, so we answer probes with
            // a 410 Gone — the bot can't tell the difference, but Lookout skips it.
            // Genuine 404s on real routes still return 404 and are still reported.
            if (ScannerProbePaths::matches($request)) {
                return response('', 410);
            }

            // X-Livewire = component update (POST); X-Livewire-Navigate = the
            // wire:navigate SPA fetch (GET) — the latter is what morphed the
            // duplicated header in. Catch both.
            if ($request->hasHeader('X-Livewire') || $request->hasHeader('X-Livewire-Navigate')) {
                return response()->view('errors.livewire-404', [], 404);
            }
        });
    })->create();
