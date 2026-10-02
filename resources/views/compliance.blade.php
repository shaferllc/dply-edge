<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="dark">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">

    @include('partials.theme-head')

    @head
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @livewireStyles
</head>
<body class="bg-edge-void font-display text-edge-text antialiased">
@include('partials.skip-link')
    @php
        /*
         | Every number here is read from the config that enforces it, so the
         | page cannot drift from the product. Keep docs/site/compliance.md in
         | step with this page.
         */
        $securityEmail = config('dply.security_email');
        $supportEmail = config('dply.support_email');
        $auditDays = max(30, (int) config('audit.retention_days', 365));
        $requestLogDays = (int) config('edge.analytics.access_logs_days', 7);
        $keepDays = (int) config('subscription.standard.trial.keep_data_days', 30);
        $purgeOn = (bool) config('subscription.standard.trial.purge_enabled', true);

        // One list for this page and DPA Annex III (config legal.subprocessors).
        $subprocessors = array_map(fn (array $row): array => [$row[0], __($row[1]), __($row[2])], config('legal.subprocessors'));

        $h2 = 'text-2xl font-bold tracking-[-0.02em]';
        $lead = 'mt-2 max-w-3xl text-sm leading-6 text-edge-mute';
        $list = 'mt-6 max-w-3xl space-y-3 text-sm leading-6 text-edge-mute';
        $strong = 'font-semibold text-edge-text';
        $link = 'border-b border-edge-lime/50 pb-0.5 text-edge-text transition-colors hover:border-edge-lime hover:text-edge-lime';
    @endphp

    <x-edge-marketing-header />

    <main id="main-content" tabindex="-1">
        {{-- ============================== HERO ============================== --}}
        <section class="border-b border-edge-line">
            <div class="mx-auto max-w-6xl px-6 py-16 lg:px-10 lg:py-20">
                <p class="font-terminal text-[11px] uppercase tracking-[0.2em] text-edge-lime">{{ __('Security & compliance') }}</p>
                <h1 class="mt-4 max-w-3xl text-4xl font-bold leading-[1.05] tracking-[-0.03em] sm:text-5xl">
                    {{ __('How dply protects your code and data.') }}
                </h1>
                <p class="mt-5 max-w-2xl text-base leading-7 text-edge-mute">
                    {{ __('dply runs many customers’ apps on shared infrastructure, so it treats every build as untrusted and scopes every resource to the organization that owns it. This page describes the platform as it runs today, for teams completing a security review.') }}
                </p>
                <div class="mt-8 flex flex-wrap items-center gap-4">
                    <a href="#disclosure" class="font-terminal inline-flex items-center gap-2 bg-edge-lime px-5 py-3 text-sm font-bold text-edge-void transition-colors hover:bg-edge-lime-bright">
                        {{ __('Report a vulnerability') }} <span aria-hidden="true">→</span>
                    </a>
                    <a href="{{ route('docs.show', 'platform-security') }}" class="font-terminal border-b border-edge-lime pb-0.5 text-sm text-edge-text transition-colors hover:text-edge-lime">
                        {{ __('platform security docs') }}
                    </a>
                </div>
            </div>
        </section>

        {{-- ========================== SUBPROCESSORS ========================= --}}
        <section id="subprocessors" class="border-b border-edge-line">
            <div class="mx-auto max-w-6xl px-6 py-14 lg:px-10">
                <h2 class="{{ $h2 }}">{{ __('Infrastructure and subprocessors') }}</h2>
                <p class="{{ $lead }}">{{ __('Your apps run on Cloudflare’s network. Builds and your account, organization and billing records run on dply’s build and control-plane servers. These companies process customer data for dply:') }}</p>

                <div class="mt-8 overflow-x-auto border border-edge-line">
                    <table class="min-w-full text-left text-sm">
                        <thead class="font-terminal border-b border-edge-line bg-edge-panel text-[11px] uppercase tracking-[0.16em] text-edge-faint">
                            <tr>
                                <th class="px-5 py-3 font-normal">{{ __('Subprocessor') }}</th>
                                <th class="px-5 py-3 font-normal">{{ __('Purpose') }}</th>
                                <th class="px-5 py-3 font-normal">{{ __('Location') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-edge-line">
                            @foreach ($subprocessors as [$name, $purpose, $where])
                                <tr>
                                    <td class="px-5 py-3 font-medium text-edge-text">{{ $name }}</td>
                                    <td class="px-5 py-3 text-edge-mute">{{ $purpose }}</td>
                                    <td class="px-5 py-3 text-edge-mute">{{ $where }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                {{-- Owner to confirm: account email is sent through Cloudflare Email Service (config/mail.php defaults to the `cloudflare` mailer; production MAIL_MAILER is not in the repo). --}}
            </div>
        </section>

        {{-- ============================= REGIONS ============================ --}}
        <section id="regions" class="border-b border-edge-line">
            <div class="mx-auto max-w-6xl px-6 py-14 lg:px-10">
                <h2 class="{{ $h2 }}">{{ __('Data locations and regions') }}</h2>
                <ul class="{{ $list }}">
                    <li><span class="{{ $strong }}">{{ __('Built files, routing and edge code') }}</span> — {{ __('stored in Cloudflare R2 and replicated across Cloudflare’s network; Worker SSR and middleware run at the location nearest each visitor.') }}</li>
                    <li><span class="{{ $strong }}">{{ __('Container apps') }}</span> — {{ __('run on Cloudflare Containers. You can restrict an app to EU-only or US FedRAMP-only regions, or pick regions yourself.') }}</li>
                    <li><span class="{{ $strong }}">{{ __('dply databases and Valkey') }}</span> — {{ __('hosted on DigitalOcean in New York. They can’t be moved to another region yet.') }}</li>
                    <li><span class="{{ $strong }}">{{ __('Object storage, Edge SQL, key-value and queues') }}</span> — {{ __('on Cloudflare. Object storage buckets can be created in the EU jurisdiction; the others take a location hint.') }}</li>
                    <li><span class="{{ $strong }}">{{ __('Your account and organization records') }}</span> — {{ __('on dply’s control plane. You can’t choose where they are stored yet.') }}</li>
                </ul>
                <p class="mt-6 text-sm text-edge-mute"><a href="{{ route('docs.show', 'data-regions') }}" class="{{ $link }}">{{ __('Data regions in the docs') }}</a></p>
            </div>
        </section>

        {{-- ============================ ENCRYPTION ========================== --}}
        <section id="encryption" class="border-b border-edge-line">
            <div class="mx-auto max-w-6xl px-6 py-14 lg:px-10">
                <h2 class="{{ $h2 }}">{{ __('Encryption') }}</h2>
                <ul class="{{ $list }}">
                    <li><span class="{{ $strong }}">{{ __('In transit') }}</span> — {{ __('every site is served over HTTPS. Certificates for custom domains are issued by Cloudflare once the domain is verified. dply databases and Valkey accept TLS connections only.') }}</li>
                    <li><span class="{{ $strong }}">{{ __('On Cloudflare') }}</span> — {{ __('Cloudflare encrypts R2, KV and D1 data at rest.') }}</li>
                    <li><span class="{{ $strong }}">{{ __('dply databases and Valkey') }}</span> — {{ __('stored on DigitalOcean block storage volumes, which DigitalOcean encrypts at rest. Database backups are written to Cloudflare R2.') }}</li>
                    <li><span class="{{ $strong }}">{{ __('Secrets in dply') }}</span> — {{ __('environment variables, organization secrets, Git provider tokens, Cloudflare credentials, notification channel settings and resource connection strings are encrypted in dply’s database with AES-256. Access-control passwords are stored hashed.') }}</li>
                    <li><span class="{{ $strong }}">{{ __('Off-site key escrow') }}</span> — {{ __('the platform’s own encryption keys and database backups are escrowed daily to off-site storage with age public-key encryption, so a copy can be restored without the running servers. A daily drill proves the newest backup still decrypts.') }}</li>
                </ul>
            </div>
        </section>

        {{-- ============================ ISOLATION =========================== --}}
        <section id="isolation" class="border-b border-edge-line">
            <div class="mx-auto max-w-6xl px-6 py-14 lg:px-10">
                <h2 class="{{ $h2 }}">{{ __('Tenant isolation') }}</h2>
                <ul class="{{ $list }}">
                    <li><span class="{{ $strong }}">{{ __('Organization-scoped resources') }}</span> — {{ __('databases, queues, key-value stores, object storage and secrets belong to the organization that created them. Bindings declared in your repository resolve only inside your organization, so a binding name can’t reach another customer’s resource or dply’s own storage.') }}</li>
                    <li><span class="{{ $strong }}">{{ __('One hostname, one app') }}</span> — {{ __('a hostname can belong to only one app across all of dply, checked when you attach a domain and again when you verify it.') }}</li>
                    <li><span class="{{ $strong }}">{{ __('Sandboxed builds') }}</span> — {{ __('every build runs in its own container as a non-root user, with all Linux capabilities dropped, privilege escalation blocked, memory, CPU and process limits, and a network where one build can’t reach another. Only your checkout and your organization’s own caches are mounted.') }}</li>
                    <li><span class="{{ $strong }}">{{ __('Per-organization build caches') }}</span> — {{ __('npm, pnpm and Yarn caches are kept per organization, so one organization’s build can’t poison another’s.') }}</li>
                    <li><span class="{{ $strong }}">{{ __('Isolated image builds') }}</span> — {{ __('container app images are built in an isolated BuildKit builder with the same kind of limits, per-organization cache mounts, and no deploy credentials present.') }}</li>
                    <li><span class="{{ $strong }}">{{ __('Separate runtimes') }}</span> — {{ __('SSR apps and middleware run as their own Cloudflare Worker scripts in Cloudflare’s isolate sandbox; container apps run in their own containers.') }}</li>
                    <li><span class="{{ $strong }}">{{ __('Outbound requests') }}</span> — {{ __('when dply calls a URL you provide (webhooks, origin checks, external Redis), it refuses private, loopback, link-local and cloud-metadata addresses and pins the request to the checked address.') }}</li>
                </ul>
            </div>
        </section>

        {{-- ========================== ACCESS CONTROL ======================== --}}
        <section id="access" class="border-b border-edge-line">
            <div class="mx-auto max-w-6xl px-6 py-14 lg:px-10">
                <h2 class="{{ $h2 }}">{{ __('Access control') }}</h2>
                <ul class="{{ $list }}">
                    <li><span class="{{ $strong }}">{{ __('Roles') }}</span> — {{ __('Owner, Admin, Member, Deployer and Viewer, plus per-app roles. A Deployer can ship code and read logs but can’t change environment variables, domains, resources, security settings, members or billing. A Viewer changes nothing.') }}</li>
                    <li><span class="{{ $strong }}">{{ __('Sign-in') }}</span> — {{ __('passwords, passkeys, and GitHub, GitLab or Bitbucket sign-in. Authenticator-app two-factor authentication is available on every account and is required on password and OAuth sign-in once enabled.') }}</li>
                    <li><span class="{{ $strong }}">{{ __('API tokens') }}</span> — {{ __('limited to the abilities you grant and to their owner’s role, and stop working when the owner leaves the organization.') }}</li>
                    <li><span class="{{ $strong }}">{{ __('Audit log') }}</span> — {{ __('the organization activity log records who changed what. The full audit log and compliance export are on the Team plan, and entries are kept for :days days.', ['days' => $auditDays]) }}</li>
                </ul>
                <p class="mt-6 text-sm text-edge-mute"><a href="{{ route('docs.show', 'roles-and-permissions') }}" class="{{ $link }}">{{ __('Roles & permissions') }}</a> · <a href="{{ route('docs.show', 'account-security') }}" class="{{ $link }}">{{ __('Account security') }}</a></p>
            </div>
        </section>

        {{-- ============================ RETENTION =========================== --}}
        <section id="retention" class="border-b border-edge-line">
            <div class="mx-auto max-w-6xl px-6 py-14 lg:px-10">
                <h2 class="{{ $h2 }}">{{ __('Data retention and deletion') }}</h2>
                <ul class="{{ $list }}">
                    <li><span class="{{ $strong }}">{{ __('Your data') }}</span> — {{ __('stays until you delete it. Deleting an app, resource or organization removes it; you can also delete your account from your profile.') }}</li>
                    @if ($purgeOn)
                        <li><span class="{{ $strong }}">{{ __('Paused organizations') }}</span> — {{ __('if an organization is paused because its trial or subscription ended, its apps, resources and data are deleted :days days after the pause. Owners are emailed 7 days and 1 day before, and deletion never happens without both warnings.', ['days' => $keepDays]) }}</li>
                    @endif
                    <li><span class="{{ $strong }}">{{ __('Database backups') }}</span> — {{ __('dply Postgres, MySQL and MongoDB stream every change to storage and take a full backup daily. Backups are kept for 7, 14 or 30 days by plan, and you can restore to any point in that window. A weekly check restores each Postgres database from its backups. Deleting a database deletes its backups.') }}</li>
                    <li><span class="{{ $strong }}">{{ __('Request logs') }}</span> — {{ __('kept for :days days.', ['days' => $requestLogDays]) }}</li>
                </ul>
            </div>
        </section>

        {{-- ============================ INCIDENTS =========================== --}}
        <section id="incidents" class="border-b border-edge-line">
            <div class="mx-auto max-w-6xl px-6 py-14 lg:px-10">
                <h2 class="{{ $h2 }}">{{ __('Incident response and status') }}</h2>
                {{-- Owner to confirm: the commitment to email affected owners, and the absence of a public platform status page. --}}
                <ul class="{{ $list }}">
                    <li>{{ __('If a security incident affects your organization’s data, we email the organization’s owners with what happened and what we are doing about it.') }}</li>
                    <li>{{ __('dply doesn’t publish a status page for the platform itself yet. You can publish your own status pages and uptime monitors for your apps.') }}</li>
                    <li>{{ __('Security fixes that change how the platform behaves are listed in the changelog.') }}</li>
                </ul>
                <p class="mt-6 text-sm text-edge-mute"><a href="{{ route('docs.show', 'status-pages') }}" class="{{ $link }}">{{ __('Status pages') }}</a> · <a href="{{ route('docs.show', 'changelog') }}" class="{{ $link }}">{{ __('Changelog') }}</a></p>
            </div>
        </section>

        {{-- ============================ DISCLOSURE ========================== --}}
        <section id="disclosure" class="border-b border-edge-line scroll-mt-16">
            <div class="mx-auto max-w-6xl px-6 py-14 lg:px-10">
                <h2 class="{{ $h2 }}">{{ __('Responsible disclosure') }}</h2>
                <p class="{{ $lead }}">
                    {{ __('If you find a vulnerability in dply, email') }}
                    <a href="mailto:{{ $securityEmail }}" class="{{ $link }}">{{ $securityEmail }}</a>.
                    {{ __('Please include:') }}
                </p>
                <ul class="{{ $list }} list-disc pl-5">
                    <li>{{ __('the affected URL, feature or API endpoint;') }}</li>
                    <li>{{ __('steps to reproduce, and a proof of concept if you have one;') }}</li>
                    <li>{{ __('what an attacker could do with it;') }}</li>
                    <li>{{ __('how to reach you, and whether you’d like to be credited.') }}</li>
                </ul>
                {{-- Owner to confirm: safe-harbor wording. --}}
                <p class="mt-6 max-w-3xl text-sm leading-6 text-edge-mute">
                    {{ __('We won’t pursue legal action against research done in good faith under these rules: test only against accounts and data you own, don’t access, change or delete other customers’ data, don’t degrade the service (no denial-of-service or spam), and give us reasonable time to fix the issue before disclosing it publicly. If you reach someone else’s data by accident, stop and tell us.') }}
                </p>
                <p class="mt-4 max-w-3xl text-sm leading-6 text-edge-mute">
                    {{ __('To report abuse hosted on dply, or for anything else, email') }}
                    <a href="mailto:{{ $supportEmail }}" class="{{ $link }}">{{ $supportEmail }}</a>.
                </p>
            </div>
        </section>

        {{-- ============================ COMPLIANCE ========================== --}}
        <section id="status" class="border-b border-edge-line">
            <div class="mx-auto max-w-6xl px-6 py-14 lg:px-10">
                <h2 class="{{ $h2 }}">{{ __('Compliance status') }}</h2>
                <ul class="{{ $list }}">
                    <li><span class="{{ $strong }}">{{ __('Certifications') }}</span> — {{ __('dply has no third-party security certifications or audit reports yet, such as SOC 2 or ISO 27001, and doesn’t offer a HIPAA business associate agreement. Don’t host workloads that require them.') }}</li>
                    <li><span class="{{ $strong }}">{{ __('GDPR') }}</span> — {{ __('dply processes your apps’ data on your behalf under the Data Processing Addendum, uses the subprocessors listed above, and lets you export or delete your data. dply databases and Valkey are in the US, and object storage location is a placement hint, not a guarantee: dply doesn’t offer EU-only storage yet, so keep data that must stay in the EU with a provider that guarantees it.') }}</li>
                    <li><span class="{{ $strong }}">{{ __('Data processing agreement') }}</span> — {{ __('the') }} <a href="{{ route('legal.dpa') }}" class="{{ $link }}">{{ __('Data Processing Addendum') }}</a> {{ __('is part of the Terms, with the EU standard contractual clauses. Organization owners are emailed at least 30 days before a new subprocessor is added, and can object. For a countersigned copy, email') }} <a href="mailto:{{ $supportEmail }}" class="{{ $link }}">{{ $supportEmail }}</a>.</li>
                    <li><span class="{{ $strong }}">{{ __('Not available yet') }}</span> — {{ __('single sign-on (SAML or OIDC), SCIM provisioning, and choosing where your account data is stored.') }}</li>
                </ul>
                <p class="mt-6 text-sm text-edge-mute"><a href="{{ route('docs.show', 'compliance') }}" class="{{ $link }}">{{ __('Compliance & security in the docs') }}</a></p>
            </div>
        </section>
    </main>

    <x-edge-marketing-footer />
    @livewireScripts
</body>
</html>
