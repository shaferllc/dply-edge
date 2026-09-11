<?php

declare(strict_types=1);

namespace App\Providers;

use App\Models\DebugAllowedIp;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Lookout\Tracing\Laravel\Lookout;
use Throwable;

/**
 * Wires dply's security policy into the Lookout debug page ("Lookout's
 * Ignition"). The SDK owns rendering + the exception-handler decorator; dply
 * only decides WHO may see the rich page for a production 500 and supplies the
 * page's dply-specific chrome (app name, base path, reference, Lookout link).
 *
 * Guarded by class_exists so dply keeps booting even before the lookout/tracing
 * version that ships the debug page is installed. The page also stays dark until
 * LOOKOUT_DEBUG_PAGE=true — prod-only.
 */
class LookoutDebugPageServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        if (! class_exists(Lookout::class)) {
            return;
        }

        // WHO may see the rich page:
        //  - locally/dev (app.debug on) → everyone, the normal Ignition experience;
        //  - in production → an authenticated platform admin, OR an allow-listed
        //    client IP (the IP path is the fallback for 500s on guest routes or
        //    before auth resolves).
        // Guests without an allow-listed IP fail closed in production.
        // Lookout::viewerMaySeeDebugPage wraps this in its own try/catch.
        Lookout::showDebugPageUsing(static function (Request $request, Throwable $e): bool {
            return (bool) config('app.debug')
                || Gate::allows('viewPlatformAdmin')
                || DebugAllowedIp::allows($request->ip());
        });

        // dply-specific view chrome. Reference is the reported occurrence id so
        // the page's ref matches the event Lookout stored (step: reference
        // correlation); the "View in Lookout" link is layered in with the
        // read-side integration.
        Lookout::debugPageMetaUsing(static function (Request $request, Throwable $e): array {
            return array_filter([
                'app_name' => 'dply',
                'base_path' => base_path(),
                'reference' => \Lookout\Tracing\Reporting\ErrorReportClient::lastOccurrenceUuid(),
            ], static fn ($v) => $v !== null);
        });
    }
}
