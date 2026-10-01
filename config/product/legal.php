<?php

/*
|--------------------------------------------------------------------------
| Legal documents
|--------------------------------------------------------------------------
| The Terms, Privacy Policy, Acceptable Use Policy and DPA (resources/views/
| legal/*) read these. Bump `version` whenever the text of the Terms, the
| Privacy Policy or the AUP changes: sign-up records the version each user
| accepted (users.terms_version), and users on an older version are asked to
| accept the new one.
*/
return [
    'version' => env('DPLY_LEGAL_VERSION', '2026-09-30'),
    'entity' => 'Shafer LLC',
    'state' => 'Arizona',
    // Subprocessors: the compliance page (#subprocessors) and DPA Annex III both
    // read this list. Adding one commits to emailing organization owners at
    // least 30 days first (DPA). [name, purpose, location]
    'subprocessors' => [
        ['Cloudflare', 'Hosting, delivery, storage and compute for your apps (Workers, R2, KV, D1, Queues, Durable Objects, Containers); DNS and certificates for custom domains; account email', 'Global network'],
        ['DigitalOcean', 'dply databases (Postgres, MySQL, MongoDB) and Valkey stores', 'New York, USA'],
        ['Stripe', 'Payments, subscriptions and invoices. dply never receives or stores your card number.', 'Stripe'],
        ['GitHub, GitLab, Bitbucket', 'Source code access and sign-in, only for the providers you connect', 'The provider'],
        ['Bunny Fonts (BunnyWay d.o.o.)', 'Web fonts for the dply website and dashboard. The font request carries the visitor’s IP address; Bunny does not log it or set cookies.', 'EU (Slovenia)'],
    ],
    // Where DMCA notices go. Register this agent with the US Copyright Office
    // (dmca.copyright.gov) so the §512 safe harbor applies.
    'dmca_agent' => [
        'name' => env('DPLY_DMCA_AGENT_NAME', 'Copyright Agent, Shafer LLC'),
        'email' => env('DPLY_DMCA_AGENT_EMAIL', 'dmca@dply.io'),
        'address' => env('DPLY_DMCA_AGENT_ADDRESS', ''),
    ],
];
