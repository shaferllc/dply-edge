<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Edge\EdgeCloudflareDispatchNamespaceTest;

use App\Modules\Providers\Cloudflare\EdgeCloudflareClient;
use Illuminate\Support\Facades\Http;

/**
 * The list endpoint returns namespace_name/namespace_id, not name/id. Keying
 * off `name` made ensureDispatchNamespace() re-create an existing namespace,
 * which fails and caches a 6h "SSR unavailable" denial.
 */
test('dispatch namespace lookup matches the namespace_name the api returns', function () {
    config([
        'edge.cloudflare.account_id' => 'acct_test',
        'edge.cloudflare.api_token' => 'token_test',
    ]);

    Http::fake([
        'api.cloudflare.com/client/v4/accounts/acct_test/workers/dispatch/namespaces' => Http::response([
            'success' => true,
            'result' => [
                ['namespace_id' => 'ns-other', 'namespace_name' => 'dply-edge-live-ssr'],
                ['namespace_id' => 'ns-wanted', 'namespace_name' => 'dply-edge-ssr'],
            ],
        ], 200),
    ]);

    $client = EdgeCloudflareClient::fromConfig();

    expect($client->dispatchNamespaceIdByName('dply-edge-ssr'))->toBe('ns-wanted')
        ->and($client->ensureDispatchNamespace('dply-edge-ssr'))->toBe('ns-wanted');

    // Resolved from the list, so nothing was POSTed to create a duplicate.
    Http::assertSentCount(2);
});
