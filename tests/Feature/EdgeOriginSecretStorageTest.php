<?php

declare(strict_types=1);

namespace Tests\Feature\EdgeOriginSecretStorageTest;

use App\Models\Site;
use App\Modules\Edge\Support\EdgeEffectiveOrigin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/**
 * Replays the two statements from
 * 2026_09_17_000000_move_edge_origin_secrets_out_of_site_meta.
 *
 * The migration itself has already run by the time a test executes, so the
 * only way to cover its data path is to re-create a pre-migration row and run
 * the same SQL against it. Keep this in step with the migration.
 */
function runBackfill(): void
{
    DB::table('sites')->select('id', 'meta')
        ->whereRaw("meta->'edge'->'origin'->>'auth_secret' IS NOT NULL")
        ->whereRaw("meta->'edge'->'origin'->>'auth_secret' <> ''")
        ->orderBy('id')
        ->chunk(200, function ($rows): void {
            foreach ($rows as $row) {
                $meta = json_decode((string) $row->meta, true);
                $secret = $meta['edge']['origin']['auth_secret'] ?? null;
                if (! is_string($secret) || $secret === '') {
                    continue;
                }
                DB::table('sites')->where('id', $row->id)->update([
                    'edge_origin_secrets' => Crypt::encryptString((string) json_encode(['auth_secret' => $secret])),
                ]);
            }
        });

    DB::statement(<<<'SQL'
        UPDATE sites
        SET meta = jsonb_set(
            meta::jsonb,
            '{edge,origin}',
            (meta->'edge'->'origin')::jsonb - 'auth_secret'
        )::json
        WHERE meta->'edge'->'origin'->>'auth_secret' IS NOT NULL
    SQL);
}

function legacySite(): Site
{
    return Site::factory()->create([
        'meta' => ['edge' => ['runtime_mode' => 'hybrid', 'origin' => [
            'url' => 'https://origin.example.com',
            'routes' => ['/api/*'],
            'healthcheck_path' => '/',
            'auth_secret' => 'LEGACY-PLAINTEXT-123',
        ]]],
    ]);
}

test('backfill moves the secret into the encrypted column and out of meta', function () {
    $site = legacySite();

    runBackfill();
    $site->refresh();

    expect($site->edgeOriginSecret('auth_secret'))->toBe('LEGACY-PLAINTEXT-123');
    expect($site->edgeMeta()['origin'])->not->toHaveKey('auth_secret');
});

test('backfill preserves the rest of the origin config', function () {
    $site = legacySite();

    runBackfill();
    $site->refresh();

    expect($site->edgeMeta()['origin']['url'])->toBe('https://origin.example.com');
    expect($site->edgeMeta()['origin']['routes'])->toBe(['/api/*']);
    expect($site->edgeMeta()['origin']['healthcheck_path'])->toBe('/');
    expect($site->edgeMeta()['runtime_mode'])->toBe('hybrid');
});

test('the column holds ciphertext, not the secret', function () {
    $site = legacySite();

    runBackfill();

    $raw = (string) DB::table('sites')->where('id', $site->id)->value('edge_origin_secrets');
    expect($raw)->not->toBeEmpty();
    expect($raw)->not->toContain('LEGACY-PLAINTEXT-123');
});

test('a site with no origin secret is left alone', function () {
    $site = Site::factory()->create([
        'meta' => ['edge' => ['runtime_mode' => 'static']],
    ]);

    runBackfill();
    $site->refresh();

    expect($site->edge_origin_secrets)->toBeNull();
    expect($site->edgeMeta()['runtime_mode'])->toBe('static');
});

test('EdgeEffectiveOrigin reads credentials from the column', function () {
    $site = Site::factory()->create([
        'meta' => ['edge' => ['runtime_mode' => 'hybrid', 'origin' => [
            'url' => 'https://origin.example.com',
            'routes' => ['/api/*'],
        ]]],
    ]);
    $site->mergeEdgeOriginSecrets([
        'auth_secret' => 'shared-secret',
        'access_client_id' => 'abc.access',
        'access_client_secret' => 'shhh',
    ]);
    $site->save();

    $effective = EdgeEffectiveOrigin::for($site->fresh(), null);

    expect($effective['auth_secret'])->toBe('shared-secret');
    expect($effective['access_client_id'])->toBe('abc.access');
    expect($effective['access_client_secret'])->toBe('shhh');
    expect($effective['url'])->toBe('https://origin.example.com');
});

test('a blank value clears its key rather than storing an empty string', function () {
    $site = Site::factory()->create();
    $site->mergeEdgeOriginSecrets(['auth_secret' => 'keep', 'access_client_id' => 'drop-me']);
    $site->save();

    $site->mergeEdgeOriginSecrets(['access_client_id' => '']);
    $site->save();
    $site->refresh();

    expect($site->edge_origin_secrets)->toBe(['auth_secret' => 'keep']);
    expect($site->edgeOriginSecret('access_client_id'))->toBeNull();
});
