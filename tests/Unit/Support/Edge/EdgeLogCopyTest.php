<?php

declare(strict_types=1);

namespace Tests\Unit\Support\Edge;

use App\Modules\Edge\Support\AnsiHtml;
use App\Modules\Edge\Support\EdgeLogCopy;

test('customer logs drop the upstream vendor name', function () {
    $raw = <<<'LOG'
"image": "registry.cloudflare.com/acct/dply-ctr-app@sha256:abc",
Cloudflare collects anonymous telemetry about your usage of Wrangler. Learn more at https://github.com/cloudflare/workers-sdk
Image pushed. Waiting for Cloudflare to roll the container out.
LOG;

    $clean = EdgeLogCopy::forCustomer($raw);

    expect($clean)->not->toContain('cloudflare')
        ->and($clean)->not->toContain('Cloudflare')
        ->and($clean)->toContain('dply-edge/acct/dply-ctr-app')
        ->and($clean)->toContain('Waiting for Dply Edge to roll the container out.')
        ->and($clean)->not->toContain('telemetry');
});

test('customer logs keep npm package specifiers', function () {
    $clean = EdgeLogCopy::forCustomer("Could not resolve \"@cloudflare/puppeteer\"\n");

    expect($clean)->toContain('@cloudflare/puppeteer');
});

test('customer logs drop the docker credential-store warning', function () {
    $raw = <<<'LOG'
WARNING! Your password will be stored unencrypted in /root/.docker/config.json.
Configure a credential helper to remove this warning. See
https://docs.docker.com/engine/reference/commandline/login/#credential-stores
Image pushed.
LOG;

    $clean = EdgeLogCopy::forCustomer($raw);

    expect($clean)->toBe('Image pushed.')
        ->and($clean)->not->toContain('password')
        ->and($clean)->not->toContain('config.json');
});

test('rendered build output hides the vendor name', function () {
    $html = AnsiHtml::toHtml("Waiting for Cloudflare to roll the container out.\n");

    expect($html)->toContain('Dply Edge')
        ->and($html)->not->toContain('Cloudflare');
});
