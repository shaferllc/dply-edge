<?php

declare(strict_types=1);

use App\Models\EdgeDeployment;
use App\Modules\Edge\Services\EdgeMiddlewareBundleUploader;
use App\Modules\Edge\Services\EdgeSsrBundleUploader;
use App\Modules\Edge\Support\EdgeDeliveryContext;

test('customer SSR and middleware scripts never get the platform\'s shared KV or bucket', function (string $uploader) {
    config(['edge.cloudflare.kv_namespace_id' => 'platform-host-map', 'edge.r2.bucket' => 'platform-artifacts']);
    $deployment = new EdgeDeployment;
    $deployment->forceFill(['id' => '01DEPLOYDEPLOYDEPLOYDEPLOY', 'site_id' => '01SITESITESITESITESITESITE', 'storage_prefix' => 'edge/o/s/d']);

    $method = new ReflectionMethod($uploader, 'bindingsFor');
    $names = array_column($method->invoke(app($uploader), $deployment, EdgeDeliveryContext::platform()), 'name');

    expect($names)->toContain('DEPLOYMENT_ID', 'SITE_ID', 'STORAGE_PREFIX')
        ->not->toContain('HOST_MAP')
        ->not->toContain('ASSETS')
        ->not->toContain('EDGE_CACHE');
})->with([EdgeSsrBundleUploader::class, EdgeMiddlewareBundleUploader::class]);
