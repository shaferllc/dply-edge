<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Laravel\Pennant\Feature;

/**
 * What this dply instance can actually do — read by `dply init` before it
 * shows anything.
 *
 * The CLI deliberately hardcodes none of this. dply is self-hostable and one
 * installed CLI can address several instances (`--base-url`, `DPLY_BASE_URL`),
 * so surfaces, regions and limits are properties of the instance being talked
 * to, not of the binary. An instance with `surface.edge` off simply does not
 * offer Edge, and the region list has exactly one home in config.
 *
 * A 404 here means an instance older than this endpoint. The CLI treats that
 * as "no CLI create on this instance" and says so by name, rather than failing
 * obscurely — every other command keeps working against it.
 *
 * @see docs/adr/cli-init-and-site-creation.md
 */
class CapabilitiesApiController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        return response()->json(['data' => [
            'instance' => [
                'url' => config('app.url'),
                'name' => config('app.name'),
            ],
            // Per-kind so the menu can list every kind this instance has,
            // marking the ones the CLI cannot create yet — those open the web
            // wizard instead. dply-edge ships exactly one kind.
            'kinds' => [
                'edge' => [
                    'enabled' => Feature::active('surface.edge'),
                    'cli_create_supported' => false,
                    'cli_create' => false,
                    'create_url' => url('/edge/create'),
                    'requires_git' => true,
                ],
            ],
        ]]);
    }
}
