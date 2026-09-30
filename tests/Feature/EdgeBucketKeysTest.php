<?php

declare(strict_types=1);

namespace Tests\Feature\EdgeBucketKeysTest;

use App\Enums\SiteType;
use App\Livewire\Sites\Edge\Workspace\Resources;
use App\Models\EdgeBucketKey;
use App\Models\Organization;
use App\Models\Server;
use App\Models\Site;
use App\Models\User;
use App\Modules\Edge\Services\Containers\EdgeContainerDeployer;
use App\Modules\Edge\Services\Storage\EdgeBucketKeys;
use App\Modules\Edge\Support\EdgeContainerConnections;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['edge.cloudflare.account_id' => 'acct', 'edge.cloudflare.api_token' => 'tok']);
});

/** A container app with the given buckets (disk name => label); returns [user, site]. */
function bucketApp(array $buckets): array
{
    $org = Organization::factory()->create();
    $user = User::factory()->create();
    $org->users()->attach($user->id, ['role' => 'owner']);
    $server = Server::factory()->create(['organization_id' => $org->id, 'user_id' => $user->id, 'meta' => ['host_kind' => Server::HOST_KIND_DPLY_EDGE]]);
    $site = Site::factory()->create([
        'organization_id' => $org->id, 'server_id' => $server->id, 'user_id' => $user->id, 'type' => SiteType::Static,
        'edge_backend' => 'dply_edge', 'status' => Site::STATUS_EDGE_ACTIVE, 'meta' => ['edge' => ['runtime_mode' => 'container']],
    ]);
    setBuckets($site, $buckets);

    return [$user, $site->fresh()];
}

function setBuckets(Site $site, array $buckets): void
{
    $prefix = EdgeContainerConnections::ownedPrefix($site->organization);
    $rows = [];
    foreach ($buckets as $name => $label) {
        $rows[] = ['kind' => 'object_storage', 'name' => strtoupper($name), 'host' => EdgeContainerConnections::resourceHost($site, $label), 'target' => $prefix.$label];
    }
    $site->mergeEdgeMeta(['connections' => $rows]);
    $site->save();
}

function fakeTokens(): void
{
    Http::fake([
        '*/tokens/permission_groups' => Http::response(['success' => true, 'result' => [
            ['id' => 'grp-write', 'name' => EdgeBucketKeys::WRITE_GROUP], ['id' => 'grp-read', 'name' => EdgeBucketKeys::READ_GROUP],
        ]]),
        '*/accounts/acct/tokens' => Http::response(['success' => true, 'result' => ['id' => 'tok-1', 'value' => 'raw-value']]),
        '*/accounts/acct/tokens/*' => Http::response(['success' => true, 'result' => []]),
    ]);
}

it('makes one app key over the app’s buckets, and calls Cloudflare again only when they change', function () {
    fakeTokens();
    [, $site] = bucketApp(['uploads' => 'uploads']);
    $prefix = EdgeContainerConnections::ownedPrefix($site->organization);

    $key = app(EdgeBucketKeys::class)->syncApp($site);
    expect($key->token_id)->toBe('tok-1')
        ->and($key->secret)->toBe(hash('sha256', 'raw-value'))
        ->and($key->buckets)->toBe([$prefix.'uploads']);
    Http::assertSent(fn (Request $r) => $r->method() === 'POST' && str_ends_with($r->url(), '/accounts/acct/tokens')
        && isset($r['policies'][0]['resources']['com.cloudflare.edge.r2.bucket.acct_default_'.$prefix.'uploads'])
        && $r['policies'][0]['permission_groups'][0]['id'] === 'grp-write');

    $sent = count(Http::recorded());
    app(EdgeBucketKeys::class)->syncApp($site->fresh());
    expect(Http::recorded())->toHaveCount($sent);

    setBuckets($site, ['uploads' => 'uploads', 'media' => 'media']);
    app(EdgeBucketKeys::class)->syncApp($site->fresh());
    Http::assertSent(fn (Request $r) => $r->method() === 'PUT' && str_ends_with($r->url(), '/tokens/tok-1') && count($r['policies'][0]['resources']) === 2);

    setBuckets($site, []);
    expect(app(EdgeBucketKeys::class)->syncApp($site->fresh()))->toBeNull()
        ->and(EdgeBucketKey::query()->count())->toBe(0);
    Http::assertSent(fn (Request $r) => $r->method() === 'DELETE' && str_ends_with($r->url(), '/tokens/tok-1'));
});

it('lets a deploy go on without S3 keys when Cloudflare refuses', function () {
    Http::fake(['*' => Http::response(['success' => false, 'errors' => [['code' => 9109, 'message' => 'Unauthorized to access requested resource']]], 403)]);
    [, $site] = bucketApp(['uploads' => 'uploads']);

    expect(app(EdgeBucketKeys::class)->syncApp($site))->toBeNull()
        ->and(EdgeBucketKeys::appEnv($site, []))->toBe([]);
});

it('sets the standard AWS env for the default bucket, all or nothing', function () {
    fakeTokens();
    [, $site] = bucketApp(['uploads' => 'uploads', 'media' => 'media']);
    $prefix = EdgeContainerConnections::ownedPrefix($site->organization);
    app(EdgeBucketKeys::class)->syncApp($site);

    $env = EdgeBucketKeys::appEnv($site, []);
    expect($env)->toMatchArray([
        'AWS_ACCESS_KEY_ID' => 'tok-1',
        'AWS_SECRET_ACCESS_KEY' => hash('sha256', 'raw-value'),
        'AWS_DEFAULT_REGION' => 'auto',
        'AWS_REGION' => 'auto',
        'AWS_BUCKET' => $prefix.'uploads',
        'AWS_ENDPOINT' => 'https://acct.r2.cloudflarestorage.com',
        'AWS_ENDPOINT_URL_S3' => 'https://acct.r2.cloudflarestorage.com',
        'AWS_USE_PATH_STYLE_ENDPOINT' => 'true',
        'DPLY_STORAGE_BUCKETS' => 'uploads='.$prefix.'uploads,media='.$prefix.'media',
    ])->and($env)->not->toHaveKey('AWS_ENDPOINT_URL');

    // The app's own key wins, and then none of ours is set.
    expect(EdgeBucketKeys::appEnv($site, ['AWS_ACCESS_KEY_ID' => 'AKIA-own']))->toBe([]);

    $secrets = (new EdgeContainerDeployer)->secrets($site, ['AWS_ACCESS_KEY_ID' => 'AKIA-own'], [], false, false, true);
    expect($secrets['AWS_ACCESS_KEY_ID'])->toBe('AKIA-own')->and($secrets)->not->toHaveKey('AWS_ENDPOINT_URL_S3');
    expect((new EdgeContainerDeployer)->secrets($site, [], [], false, false, true)['AWS_BUCKET'])->toBe($prefix.'uploads')
        ->and((new EdgeContainerDeployer)->secrets($site, [], [], false))->not->toHaveKey('AWS_ACCESS_KEY_ID');
});

it('revokes keys with their bucket and switches them off while the organization is paused', function () {
    fakeTokens();
    [$user, $site] = bucketApp(['uploads' => 'uploads', 'media' => 'media']);
    $prefix = EdgeContainerConnections::ownedPrefix($site->organization);
    app(EdgeBucketKeys::class)->syncApp($site);
    EdgeBucketKey::query()->create(['organization_id' => $site->organization_id, 'label' => 'laptop', 'buckets' => [$prefix.'media'], 'access' => 'read', 'token_id' => 'tok-ext', 'secret_last4' => 'abcd']);

    app(EdgeBucketKeys::class)->setOrganizationEnabled($site->organization, false);
    expect(EdgeBucketKey::query()->where('status', 'disabled')->count())->toBe(2);
    Http::assertSent(fn (Request $r) => $r->method() === 'PUT' && str_ends_with($r->url(), '/tokens/tok-ext') && $r['status'] === 'disabled');

    EdgeContainerConnections::destroy('object_storage', $prefix.'media', $site->organization);
    expect(EdgeBucketKey::query()->where('token_id', 'tok-ext')->exists())->toBeFalse()
        ->and(EdgeBucketKey::query()->where('token_id', 'tok-1')->first()->buckets)->toBe([$prefix.'uploads']);
});

it('creates an external key for the open bucket and shows its secret only once', function () {
    fakeTokens();
    [$user, $site] = bucketApp(['uploads' => 'uploads']);
    $host = EdgeContainerConnections::for($site)[0]['host'];
    $component = Livewire::actingAs($user)->test(Resources::class, ['server' => $site->server, 'site' => $site])->set('objectHost', $host);

    $made = $component->instance()->createObjectKey('Backups laptop', 'read');
    expect($made)->toBe(['id' => 'tok-1', 'secret' => hash('sha256', 'raw-value')]);
    $key = EdgeBucketKey::query()->whereNull('site_id')->first();
    expect($key->secret)->toBeNull()->and($key->access)->toBe('read')->and($key->secret_last4)->toBe(substr(hash('sha256', 'raw-value'), -4));
    Http::assertSent(fn (Request $r) => $r->method() === 'POST' && ($r['policies'][0]['permission_groups'][0]['id'] ?? '') === 'grp-read');

    $component->call('revokeObjectKey', $key->id);
    expect(EdgeBucketKey::query()->count())->toBe(0);
});
