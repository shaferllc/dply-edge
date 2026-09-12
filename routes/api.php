<?php

use App\Http\Controllers\Api\AccountApiController;
use App\Http\Controllers\Api\Auth\DeviceAuthorizationController;
use App\Http\Controllers\Api\CapabilitiesApiController;
use App\Http\Controllers\Api\NotificationApiController;
use App\Modules\Billing\Http\Controllers\Api\BillingApiController;
use App\Modules\Edge\Http\Controllers\Api\EdgeAccessApiController;
use App\Modules\Edge\Http\Controllers\Api\EdgeAliasApiController;
use App\Modules\Edge\Http\Controllers\Api\EdgeCacheApiController;
use App\Modules\Edge\Http\Controllers\Api\EdgeDeploymentApiController;
use App\Modules\Edge\Http\Controllers\Api\EdgeDomainApiController;
use App\Modules\Edge\Http\Controllers\Api\EdgeEnvController;
use App\Modules\Edge\Http\Controllers\Api\EdgeLintApiController;
use App\Modules\Edge\Http\Controllers\Api\EdgeLogApiController;
use App\Modules\Edge\Http\Controllers\Api\EdgePreviewApiController;
use App\Modules\Edge\Http\Controllers\Api\EdgeSiteApiController;
use App\Modules\Edge\Http\Controllers\Api\EdgeUsageApiController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function (): void {
    // OAuth-style device-flow login for the dply CLI. The CLI calls
    // /auth/device/start (unauthenticated) to mint a code pair, points
    // the user at /auth/device on the web to approve, then polls
    // /auth/device/poll until the token is ready. Throttled tightly so
    // a runaway CLI loop can't hammer the DB.
    Route::post('/auth/device/start', [DeviceAuthorizationController::class, 'start'])
        ->middleware(['throttle:30,1']);
    Route::post('/auth/device/poll', [DeviceAuthorizationController::class, 'poll'])
        ->middleware(['throttle:60,1']);

    $apiAbilities = config('api_token_permissions.http_route_abilities', []);

    Route::middleware(['auth.api', 'throttle:api'])->group(function () use ($apiAbilities): void {
        Route::get('/account', [AccountApiController::class, 'show'])
            ->middleware('ability:'.$apiAbilities['account.show']);
        Route::get('/account/organizations', [AccountApiController::class, 'organizations'])
            ->middleware('ability:'.$apiAbilities['account.organizations']);

        // What this instance offers — surfaces, creatable kinds and upload
        // limits. `dply init` reads it before showing anything, so the CLI
        // hardcodes none of it and an older instance (404 here) degrades to a
        // named message instead of a mystery.
        Route::get('/capabilities', [CapabilitiesApiController::class, 'show'])
            ->middleware('ability:'.$apiAbilities['account.organizations']);
        Route::get('/account/sessions', [AccountApiController::class, 'sessions'])
            ->middleware('ability:'.$apiAbilities['account.sessions']);
        Route::delete('/account/sessions/{apiToken}', [AccountApiController::class, 'destroySession'])
            ->middleware('ability:'.$apiAbilities['account.sessions_destroy']);

        Route::get('/billing', [BillingApiController::class, 'show'])
            ->middleware('ability:'.$apiAbilities['billing.show']);
        Route::get('/billing/breakdown', [BillingApiController::class, 'breakdown'])
            ->middleware('ability:'.$apiAbilities['billing.breakdown']);
        Route::get('/billing/invoices', [BillingApiController::class, 'invoices'])
            ->middleware('ability:'.$apiAbilities['billing.invoices']);

        // Notification routing — channels are org-level, subscriptions hang off
        // a site. Same matrix the workspace tabs write.
        Route::get('/notifications/channels', [NotificationApiController::class, 'channels'])->middleware('ability:'.$apiAbilities['notifications.channels']);
        Route::get('/notifications/events', [NotificationApiController::class, 'events'])->middleware('ability:'.$apiAbilities['notifications.events']);
        Route::post('/notifications/channels/{channel}/test', [NotificationApiController::class, 'test'])->middleware('ability:'.$apiAbilities['notifications.test']);
        Route::get('/sites/{site}/notifications', [NotificationApiController::class, 'siteIndex'])->middleware('ability:'.$apiAbilities['notifications.site_index']);
        Route::post('/sites/{site}/notifications', [NotificationApiController::class, 'siteUpdate'])->middleware('ability:'.$apiAbilities['notifications.site_update']);

        // Edge surface runs under a higher per-token throttle than
        // the rest of v1 (log tail + CI deploys are chatty by design).
        // See edge-api RateLimiter in AppServiceProvider.
        Route::prefix('edge')->middleware('throttle:edge-api')->group(function () use ($apiAbilities): void {
            Route::get('/sites', [EdgeSiteApiController::class, 'index'])
                ->middleware('ability:'.$apiAbilities['edge.sites.index']);
            Route::get('/sites/{site}', [EdgeSiteApiController::class, 'show'])
                ->middleware('ability:'.$apiAbilities['edge.sites.show']);

            Route::get('/sites/{site}/deployments', [EdgeDeploymentApiController::class, 'index'])
                ->middleware('ability:'.$apiAbilities['edge.deployments.index']);
            Route::post('/sites/{site}/deployments', [EdgeDeploymentApiController::class, 'store'])
                ->middleware('ability:'.$apiAbilities['edge.deployments.store']);
            Route::get('/sites/{site}/deployments/{deployment}', [EdgeDeploymentApiController::class, 'show'])
                ->middleware('ability:'.$apiAbilities['edge.deployments.show']);
            Route::post('/sites/{site}/deployments/{deployment}/rollback', [EdgeDeploymentApiController::class, 'rollback'])
                ->middleware('ability:'.$apiAbilities['edge.deployments.rollback']);

            Route::get('/sites/{site}/previews', [EdgePreviewApiController::class, 'index'])
                ->middleware('ability:'.$apiAbilities['edge.previews.index']);
            Route::post('/sites/{site}/previews', [EdgePreviewApiController::class, 'store'])
                ->middleware('ability:'.$apiAbilities['edge.previews.store']);
            Route::delete('/sites/{site}/previews/{preview}', [EdgePreviewApiController::class, 'destroy'])
                ->middleware('ability:'.$apiAbilities['edge.previews.destroy']);
            Route::post('/sites/{site}/previews/{preview}/promote', [EdgePreviewApiController::class, 'promote'])
                ->middleware('ability:'.$apiAbilities['edge.previews.promote']);

            Route::get('/sites/{site}/domains', [EdgeDomainApiController::class, 'index'])
                ->middleware('ability:'.$apiAbilities['edge.domains.index']);
            Route::post('/sites/{site}/domains', [EdgeDomainApiController::class, 'store'])
                ->middleware('ability:'.$apiAbilities['edge.domains.store']);
            Route::post('/sites/{site}/domains/{hostname}/verify', [EdgeDomainApiController::class, 'verify'])
                ->middleware('ability:'.$apiAbilities['edge.domains.verify'])
                ->where('hostname', '[A-Za-z0-9.-]+');
            Route::delete('/sites/{site}/domains/{hostname}', [EdgeDomainApiController::class, 'destroy'])
                ->middleware('ability:'.$apiAbilities['edge.domains.destroy'])
                ->where('hostname', '[A-Za-z0-9.-]+');

            Route::get('/sites/{site}/aliases', [EdgeAliasApiController::class, 'index'])
                ->middleware('ability:'.$apiAbilities['edge.aliases.index']);

            Route::get('/sites/{site}/access', [EdgeAccessApiController::class, 'show'])
                ->middleware('ability:'.$apiAbilities['edge.access.show']);
            Route::patch('/sites/{site}/access', [EdgeAccessApiController::class, 'update'])
                ->middleware('ability:'.$apiAbilities['edge.access.update']);

            Route::post('/sites/{site}/cache/purge', [EdgeCacheApiController::class, 'purge'])
                ->middleware('ability:'.$apiAbilities['edge.cache.purge']);

            Route::get('/sites/{site}/usage', [EdgeUsageApiController::class, 'show'])
                ->middleware('ability:'.$apiAbilities['edge.usage.show']);

            Route::get('/sites/{site}/logs', [EdgeLogApiController::class, 'index'])
                ->middleware('ability:'.$apiAbilities['edge.logs.index']);

            Route::post('/lint', [EdgeLintApiController::class, 'store'])
                ->middleware('ability:'.$apiAbilities['edge.lint.store']);

            // P-env: per-site environment variables. Values are
            // encrypted at rest + never returned by GET — list shows
            // keys + updated_at only.
            Route::get('/sites/{site}/env', [EdgeEnvController::class, 'index'])
                ->middleware('ability:'.$apiAbilities['edge.env.index']);
            Route::put('/sites/{site}/env', [EdgeEnvController::class, 'bulkUpdate'])
                ->middleware('ability:'.$apiAbilities['edge.env.update']);
            Route::patch('/sites/{site}/env/{key}', [EdgeEnvController::class, 'upsert'])
                ->middleware('ability:'.$apiAbilities['edge.env.upsert'])
                ->where('key', '[A-Z][A-Z0-9_]{0,127}');
            Route::delete('/sites/{site}/env/{key}', [EdgeEnvController::class, 'destroy'])
                ->middleware('ability:'.$apiAbilities['edge.env.destroy'])
                ->where('key', '[A-Z][A-Z0-9_]{0,127}');
        });
    });
});
