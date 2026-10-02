<?php

declare(strict_types=1);

use App\Models\Organization;
use App\Models\Server;
use App\Models\Site;
use App\Modules\Edge\Jobs\VerifyDatabaseBackupJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Process;

uses(RefreshDatabase::class);

function verifySite(): Site
{
    $org = Organization::factory()->create();

    return Site::factory()->create([
        'organization_id' => $org->id, 'server_id' => Server::factory()->create(['organization_id' => $org->id])->id, 'edge_backend' => 'dply_edge',
        'meta' => ['edge' => ['database' => ['engine' => 'postgres', 'provider' => 'dply', 'remote_id' => 'pg-abc', 'disk_gb' => 5]]],
    ]);
}

test('the restore check never archives and only reads the backups', function () {
    $script = VerifyDatabaseBackupJob::script();

    expect($script)->toContain('archive_mode=off')->toContain('wal-g backup-fetch')->toContain("restore_command='wal-g wal-fetch")
        ->not->toContain('wal-push')->not->toContain('backup-push')->not->toContain('delete');
});

test('a restore check that cannot run is recorded as failed and notifies', function () {
    config(['edge.r2' => ['bucket' => 'b', 'endpoint' => 'https://r2', 'key' => 'k', 'secret' => 's', 'region' => 'auto'], 'edge.build.work_root' => sys_get_temp_dir().'/dply-verify-'.bin2hex(random_bytes(3))]);
    // A download that fails its pinned checksum stops before Docker runs.
    \Illuminate\Support\Facades\Http::fake(['*' => \Illuminate\Support\Facades\Http::response('not wal-g')]);
    Process::fake();
    $site = verifySite();

    (new VerifyDatabaseBackupJob('pg-abc', 'shop', (string) $site->id))->handle();

    $verify = $site->fresh()->edgeMeta()['database']['backup']['verify'];
    expect($verify['ok'])->toBeFalse()->and($verify['error'])->toContain('checksum');
    Process::assertNothingRan();
});

test('the weekly command chains one restore check per dply Postgres database, skipping big volumes', function () {
    Bus::fake();
    verifySite();
    $big = verifySite();
    $big->mergeEdgeMeta(['database' => ['engine' => 'postgres', 'provider' => 'dply', 'remote_id' => 'pg-big', 'disk_gb' => 100]]);
    $big->save();

    $this->artisan('dply:databases:verify-backups')->expectsOutputToContain('skipped')->assertOk();

    Bus::assertChained([VerifyDatabaseBackupJob::class]);
});
