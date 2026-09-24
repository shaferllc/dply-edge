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

test('rendered build output hides the vendor name', function () {
    $html = AnsiHtml::toHtml("Waiting for Cloudflare to roll the container out.\n");

    expect($html)->toContain('Dply Edge')
        ->and($html)->not->toContain('Cloudflare');
});
