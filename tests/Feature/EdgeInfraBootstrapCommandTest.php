<?php

declare(strict_types=1);

namespace Tests\Feature\EdgeInfraBootstrapCommandTest;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

test('bootstrap dry run prints planned resources', function () {
    config([
        'edge.cloudflare.account_id' => 'acct123',
        'edge.cloudflare.api_token' => 'token123',
        'edge.r2.bucket' => '',
    ]);

    $this->artisan('dply:edge:infra:bootstrap', ['--dry-run' => true])
        ->expectsOutputToContain('DPLY_EDGE_R2_BUCKET=dply-edge-artifacts')
        ->expectsOutputToContain('DPLY_EDGE_CF_ACCOUNT_ID=acct123')
        ->assertSuccessful();
});

test('bootstrap creates bucket and kv namespace', function () {
    config([
        'edge.cloudflare.account_id' => 'acct123',
        'edge.cloudflare.api_token' => 'token123',
        'edge.cloudflare.kv_namespace_id' => '',
        'edge.cloudflare.cache_kv_namespace_id' => '',
        'edge.r2.bucket' => '',
    ]);

    Http::fake(function (Request $request) {
        $url = $request->url();
        $method = $request->method();

        if (str_contains($url, '/tokens/verify')) {
            return Http::response(['success' => true, 'result' => ['status' => 'active']]);
        }
        if (str_contains($url, '/r2/buckets') && $method === 'GET') {
            return Http::response(['success' => true, 'result' => ['buckets' => []]]);
        }
        if (str_contains($url, '/r2/buckets') && $method === 'POST') {
            return Http::response(['success' => true, 'result' => null]);
        }
        if (str_contains($url, '/storage/kv/namespaces') && $method === 'GET') {
            return Http::response(['success' => true, 'result' => []]);
        }
        if (str_contains($url, '/storage/kv/namespaces') && $method === 'POST') {
            $body = json_decode($request->body(), true);
            $title = is_array($body) ? (string) ($body['title'] ?? '') : '';
            $id = $title === 'dply-edge-cache' ? 'cache-kv-id' : 'kv999';

            return Http::response(['success' => true, 'result' => ['id' => $id, 'title' => $title]]);
        }

        return Http::response(['success' => false, 'errors' => [['message' => 'unexpected '.$method.' '.$url]]], 500);
    });

    $this->artisan('dply:edge:infra:bootstrap', [
        '--bucket' => 'dply-edge-artifacts',
        '--kv-title' => 'dply-edge-host-map',
    ])
        ->expectsOutputToContain('Created R2 bucket')
        ->expectsOutputToContain('Created KV namespace: dply-edge-host-map (kv999)')
        ->expectsOutputToContain('Created cache KV namespace: dply-edge-cache (cache-kv-id)')
        ->expectsOutputToContain('DPLY_EDGE_CF_KV_NAMESPACE_ID=kv999')
        ->expectsOutputToContain('DPLY_EDGE_CF_CACHE_KV_NAMESPACE_ID=cache-kv-id')
        ->assertSuccessful();
});

test('bootstrap looks up the account, derives R2 keys from the token and writes .env', function () {
    config([
        'edge.cloudflare.account_id' => '',
        'edge.cloudflare.api_token' => '',
        'edge.cloudflare.kv_namespace_id' => 'kv1',
        'edge.cloudflare.cache_kv_namespace_id' => 'kv2',
        'edge.r2.bucket' => '',
        'edge.r2.endpoint' => '',
        'edge.r2.key' => '',
        'edge.r2.secret' => '',
    ]);

    $dir = sys_get_temp_dir().'/edge-bootstrap-'.uniqid();
    mkdir($dir);
    file_put_contents($dir.'/.env', "APP_NAME=dply\nDPLY_EDGE_R2_BUCKET=old\n");
    // Pin the file too — under APP_ENV=testing Laravel targets .env.testing.
    app()->useEnvironmentPath($dir)->loadEnvironmentFrom('.env');

    Http::fake(function (Request $request) {
        $url = $request->url();
        $method = $request->method();

        if (str_ends_with($url, '/accounts')) {
            return Http::response(['success' => true, 'result' => [['id' => 'acct9', 'name' => 'Tom']]]);
        }
        if (str_contains($url, '/tokens/verify')) {
            return Http::response(['success' => true, 'result' => ['id' => 'tok-id', 'status' => 'active']]);
        }
        if (str_contains($url, '/accounts/acct9/r2/buckets') && $method === 'GET') {
            return Http::response(['success' => true, 'result' => ['buckets' => []]]);
        }
        if (str_contains($url, '/accounts/acct9/r2/buckets') && $method === 'POST') {
            return Http::response(['success' => true, 'result' => null]);
        }

        return Http::response(['success' => false, 'errors' => [['message' => 'unexpected '.$method.' '.$url]]], 500);
    });

    $this->artisan('dply:edge:infra:bootstrap', ['--token' => 'cf-token', '--skip-dispatch' => true, '--write' => true])
        ->expectsOutputToContain('DPLY_EDGE_CF_ACCOUNT_ID=acct9')
        ->expectsOutputToContain('DPLY_EDGE_R2_ACCESS_KEY=tok-id')
        ->assertSuccessful();

    $env = (string) file_get_contents($dir.'/.env');
    expect($env)->toContain('APP_NAME=dply')
        ->toContain('DPLY_EDGE_R2_BUCKET=dply-edge-artifacts')
        ->not->toContain('DPLY_EDGE_R2_BUCKET=old')
        ->toContain('DPLY_EDGE_R2_SECRET='.hash('sha256', 'cf-token'))
        ->toContain('DPLY_EDGE_R2_ENDPOINT=https://acct9.r2.cloudflarestorage.com')
        ->and(file_get_contents($dir.'/.env.bak'))->toContain('DPLY_EDGE_R2_BUCKET=old');
});

test('bootstrap fails without cloudflare credentials', function () {
    config([
        'edge.cloudflare.account_id' => '',
        'edge.cloudflare.api_token' => '',
    ]);

    $this->artisan('dply:edge:infra:bootstrap')
        ->assertFailed();
});
