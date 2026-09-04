<?php

declare(strict_types=1);

use App\Enums\QuotaSurface;
use App\Models\Server;
use App\Models\Site;

/*
 * The Edge ceiling must count exactly what the Edge index lists.
 *
 * Server::hostKind() is hardcoded to HOST_KIND_DPLY_EDGE, so every server
 * answers "edge" and cannot discriminate. Before the fix, QuotaSurface::forSite()
 * classified on that alone: four nginx/caddy/container rows counted as "3 of 3
 * Edge apps" on an org whose Apps page showed the empty state, blocking creation
 * with apps the user could neither see nor delete (observed 2026-08-30).
 */

/** A site on an Edge-kind server — the only kind of server there is. */
function siteOnEdgeHost(array $attributes): Site
{
    $site = new Site($attributes);
    $site->setRelation('server', new Server);

    return $site;
}

it('counts a real edge app against the edge ceiling', function () {
    $site = siteOnEdgeHost(['edge_backend' => 'org_cloudflare']);

    expect($site->countsAsEdgeApp())->toBeTrue()
        ->and(QuotaSurface::forSite($site))->toBe(QuotaSurface::Edge);
});

it('counts an edge_web runtime profile against the edge ceiling', function () {
    $site = siteOnEdgeHost(['meta' => ['runtime_profile' => 'edge_web']]);

    expect($site->countsAsEdgeApp())->toBeTrue()
        ->and(QuotaSurface::forSite($site))->toBe(QuotaSurface::Edge);
});

it('does not let a row the edge index cannot list consume the edge ceiling', function (array $attributes) {
    $site = siteOnEdgeHost($attributes);

    expect($site->countsAsEdgeApp())->toBeFalse()
        ->and(QuotaSurface::forSite($site))->not->toBe(QuotaSurface::Edge);
})->with([
    'vm site (nginx)' => [['status' => 'nginx_active']],
    'vm site (caddy)' => [['status' => 'caddy_active']],
    'container site' => [['status' => 'container_failed', 'container_backend' => 'docker']],
    'bare row' => [[]],
    'empty edge_backend' => [['edge_backend' => '']],
]);
