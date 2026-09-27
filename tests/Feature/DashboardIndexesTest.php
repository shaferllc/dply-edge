<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

test('dashboard and deploy-history lookups are indexed', function () {
    expect(Schema::hasIndex('sites', ['organization_id']))->toBeTrue()
        ->and(Schema::hasIndex('edge_deployments', ['site_id', 'created_at']))->toBeTrue();
});

test('lookups every workspace page makes are indexed', function () {
    expect(Schema::hasIndex('site_domains', ['site_id', 'is_primary']))->toBeTrue()
        ->and(Schema::hasIndex('organization_user', ['user_id']))->toBeTrue();
});
