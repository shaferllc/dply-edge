<?php

declare(strict_types=1);

namespace Tests\Unit\PubliclyRoutableUrlTest;

use App\Rules\PubliclyRoutableUrl;
use Illuminate\Translation\PotentiallyTranslatedString;

/**
 * Cases stay on literal IPs and blocked-suffix hostnames on purpose — the rule
 * never resolves DNS, so the suite must not depend on a network lookup either.
 */
function failureFor(string $url): ?string
{
    $message = null;
    (new PubliclyRoutableUrl)->validate(
        'origin',
        $url,
        function (string $m) use (&$message): PotentiallyTranslatedString {
            $message = $m;

            return new PotentiallyTranslatedString($m, app('translator'));
        },
    );

    return $message;
}

test('rejects literal private and loopback addresses', function (string $url) {
    expect(failureFor($url))->not->toBeNull();
})->with([
    'http://10.0.0.5:3000',
    'http://192.168.1.10',
    'http://172.16.4.4',
    'http://127.0.0.1:8080',
    'http://169.254.169.254/latest/meta-data/',
    'http://[::1]:9000',
]);

test('rejects internal-only hostnames', function (string $url) {
    expect(failureFor($url))->not->toBeNull();
})->with([
    'http://localhost:3000',
    'https://db.internal',
    'https://api.corp',
    'https://metadata.google.internal',
    'https://kubernetes.default',
]);

test('allows a public hostname', function () {
    expect(failureFor('https://api.example.com/v1'))->toBeNull();
});

test('allows a public literal IP', function () {
    expect(failureFor('https://1.1.1.1'))->toBeNull();
});

test('names the offending host so the error is actionable', function () {
    expect(failureFor('http://10.0.0.5:3000'))
        ->toContain('10.0.0.5')
        ->toContain('private address');
});

test('defers empty and malformed values to the other rules', function (string $url) {
    expect(failureFor($url))->toBeNull();
})->with(['', '   ', 'not a url']);
