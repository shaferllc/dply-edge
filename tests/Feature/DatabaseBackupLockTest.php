<?php

declare(strict_types=1);

namespace Tests\Feature\DatabaseBackupLockTest;

use App\Models\Organization;
use App\Models\Server;
use App\Models\Site;
use Aws\Api\DateTimeResult;
use Aws\CommandInterface;
use Aws\Result;
use Aws\S3\S3Client;
use GuzzleHttp\Promise\Create;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(function () {
    config([
        'edge.cloudflare.account_id' => 'acct', 'edge.cloudflare.api_token' => 'tok',
        'edge.r2' => ['bucket' => 'live', 'endpoint' => 'https://acct.r2.test', 'region' => 'auto', 'key' => 'k', 'secret' => 's'],
        'edge.valkey.api_url' => 'https://gw.test', 'edge.valkey.token' => 'gw-token',
        'edge.valkey.regions' => [['key' => 'nyc3']],
    ]);
});

it('adds the database backup rules and keeps the bucket’s other lock rules', function () {
    $other = ['id' => 'someone-elses', 'enabled' => true, 'prefix' => 'audit/', 'condition' => ['type' => 'Indefinite']];
    Http::fake(['*/r2/buckets/live/lock' => fn (Request $r) => Http::response(['success' => true, 'result' => $r->method() === 'GET'
        ? ['rules' => [$other, ['id' => 'dply-db-backups-pg', 'prefix' => 'tenants/pg-', 'condition' => ['type' => 'Age', 'maxAgeSeconds' => 1]]]]
        : []])]);

    $this->artisan('dply:databases:lock-backups')->assertSuccessful();

    Http::assertSent(function (Request $r) use ($other): bool {
        if ($r->method() !== 'PUT') {
            return false;
        }
        $rules = $r['rules'];

        return $rules[0] === $other
            && array_column($rules, 'id') === ['someone-elses', 'dply-db-backups-pg', 'dply-db-backups-my', 'dply-db-backups-mg']
            && $rules[1]['condition'] === ['type' => 'Age', 'maxAgeSeconds' => 5 * 86400];
    });
});

it('sweeps only backups of databases that are gone everywhere and past the lock', function () {
    $org = Organization::factory()->create();
    Site::factory()->create([
        'organization_id' => $org->id,
        'server_id' => Server::factory()->create(['organization_id' => $org->id])->id,
        'meta' => ['edge' => ['database' => ['remote_id' => 'pg-live']]],
    ]);
    // pg-live: an app has it. pg-known: the gateway still has it. pg-err: the
    // gateway errored. pg-fresh: written inside the lock. pg-gone: swept.
    Http::fake([
        'gw.test/tenants/pg-known' => Http::response(['id' => 'pg-known']),
        'gw.test/tenants/pg-err' => Http::response('', 500),
        'gw.test/tenants/*' => Http::response('', 404),
    ]);
    $old = Carbon::now()->subDays(10);
    $deleted = [];
    $handler = function (CommandInterface $cmd) use ($old, &$deleted): Result {
        if ($cmd->getName() === 'DeleteObjects') {
            array_push($deleted, ...array_column($cmd['Delete']['Objects'], 'Key'));

            return new Result([]);
        }
        if (($cmd['Delimiter'] ?? null) === '/') {
            return new Result(['IsTruncated' => false, 'CommonPrefixes' => $cmd['Prefix'] === 'tenants/pg-'
                ? array_map(fn (string $id): array => ['Prefix' => "tenants/{$id}/"], ['pg-live', 'pg-known', 'pg-err', 'pg-fresh', 'pg-gone'])
                : []]);
        }
        $at = str_contains($cmd['Prefix'], 'pg-fresh') ? Carbon::now()->subDays(2) : $old;

        return new Result(['IsTruncated' => false, 'Contents' => [['Key' => $cmd['Prefix'].'basebackups/x', 'LastModified' => new DateTimeResult($at->toIso8601String())]]]);
    };
    $s3 = new S3Client(['version' => 'latest', 'region' => 'auto', 'credentials' => ['key' => 'k', 'secret' => 's'], 'handler' => fn (CommandInterface $cmd) => Create::promiseFor($handler($cmd))]);
    app()->bind(S3Client::class, fn () => $s3);

    $this->artisan('dply:databases:sweep-backups')->assertSuccessful();

    expect($deleted)->toBe(['tenants/pg-gone/basebackups/x']);
});
