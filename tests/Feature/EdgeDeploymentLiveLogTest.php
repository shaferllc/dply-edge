<?php

declare(strict_types=1);

use App\Models\EdgeDeployment;
use App\Models\Site;
use App\Modules\Edge\Support\EdgeLiveBuildLog;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('a build in flight on a builder shows the live log mirror', function () {
    $site = Site::factory()->create();
    $deployment = EdgeDeployment::query()->create(['site_id' => $site->id, 'organization_id' => $site->organization_id, 'status' => 'building']);

    expect($deployment->readBuildLog($site))->toBeNull();

    EdgeLiveBuildLog::append((string) $deployment->id, "Cloning repository\nInstalling dependencies\n");

    expect($deployment->readBuildLog($site))->toContain('Installing dependencies');
});
