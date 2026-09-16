<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Dply-owned testing / preview hostnames
|--------------------------------------------------------------------------
|
| Every managed preview URL is minted on one of these zones. The list lives
| here — not in .env — so adding a zone is a code change, not a deploy-time
| secret. DNS for VM testing hostnames is Cloudflare (platform token:
| DPLY_TESTING_CF_API_TOKEN, else DPLY_EDGE_CF_API_TOKEN) with Namecheap as
| fallback. Edge and Serverless keep their own apexes.
|
| Tests (APP_ENV=testing) use local *.test apexes so the suite never talks
| to a public zone.
*/

$testing = env('APP_ENV') === 'testing';

return [

    'provider' => 'cloudflare',

    // CLOUDFLARE_KEY comes after CLOUDFLARE_API_KEY: dply's Cloudflare mail
    // binding sets CLOUDFLARE_KEY to an Email Sending token, which has no DNS
    // access. It stays as a last resort for boxes where it is still the DNS token.
    'cloudflare_api_token' => env(
        'DPLY_TESTING_CF_API_TOKEN',
        env('CLOUDFLARE_API_KEY', env('CLOUDFLARE_KEY', env('DPLY_EDGE_CF_API_TOKEN'))),
    ),

    'vm_apex' => $testing ? 'dply.test' : 'on-dply.cc',
    'edge_apex' => $testing ? 'edge.test' : 'on-dply.live',
    'serverless_apex' => $testing ? 'dply.test' : 'dply-serverless.cloud',

    'vm' => $testing ? ['dply.test'] : [
        'on-dply.cc',
        'on-dply.app',
        'on-dply.cloud',
        'on-dply.site',
        'on-dply.com',
        'on-dply.live',
        'on-dply.online',
        'dply.app',
        'dply.cc',
        'dply.host',
        'dply.ink',
        'dply.us',
        'dply.online',
        'dply.site',
        'dply.space',
    ],

    'edge' => $testing ? ['edge.test'] : [
        'on-dply.live',
    ],

    'serverless' => $testing ? ['dply.test'] : [
        'dply-serverless.cloud',
    ],

];
