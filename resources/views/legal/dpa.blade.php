@php
    $entity = config('legal.entity');
    $state = config('legal.state');
    $supportEmail = config('dply.support_email');
    $securityEmail = config('dply.security_email');
    $auditDays = max(30, (int) config('audit.retention_days', 365));
    $requestLogDays = (int) config('edge.analytics.access_logs_days', 7);
    $keepDays = (int) config('subscription.standard.trial.keep_data_days', 30);

    // The same list as the compliance page (config legal.subprocessors).
    $subprocessors = config('legal.subprocessors');

    $section = 'border-b border-edge-line scroll-mt-16';
    $wrap = 'mx-auto max-w-6xl px-6 py-12 lg:px-10';
    $h2 = 'text-2xl font-bold tracking-[-0.02em]';
    $h3 = 'mt-8 text-base font-semibold text-edge-text';
    $p = 'mt-4 max-w-3xl text-sm leading-6 text-edge-mute';
    $list = 'mt-4 max-w-3xl list-disc space-y-2 pl-5 text-sm leading-6 text-edge-mute';
    $strong = 'font-semibold text-edge-text';
    $link = 'border-b border-edge-lime/50 pb-0.5 text-edge-text transition-colors hover:border-edge-lime hover:text-edge-lime';
    $th = 'px-5 py-3 font-normal';
    $td = 'px-5 py-3 align-top text-edge-mute';
@endphp

@include('legal.partials.layout-start', [
    'eyebrow' => __('Legal'),
    'title' => __('Data Processing Addendum'),
    'intro' => __('This addendum (DPA) governs how :entity processes personal data on behalf of its customers. It is part of the Terms of Service and applies automatically; no signature is needed. If you need a countersigned copy, email :email.', ['entity' => $entity, 'email' => $supportEmail]),
    'toc' => [
        ['roles', 'Roles and scope'],
        ['details', 'Details of the processing'],
        ['obligations', 'Processor obligations'],
        ['subprocessors', 'Sub-processors'],
        ['assistance', 'Assistance'],
        ['breach', 'Personal data breaches'],
        ['deletion', 'Deletion and return'],
        ['audits', 'Audits'],
        ['transfers', 'International transfers'],
        ['ccpa', 'US state privacy laws (CCPA)'],
        ['precedence', 'Precedence and liability'],
        ['annex-1', 'Annex I: Parties and description'],
        ['annex-2', 'Annex II: Security measures'],
        ['annex-3', 'Annex III: Sub-processors'],
    ],
])

<section id="roles" class="{{ $section }}"><div class="{{ $wrap }}">
    <h2 class="{{ $h2 }}">1. Roles and scope</h2>
    <p class="{{ $p }}">In this DPA, “Customer” is the party that agreed to the <a href="/terms" class="{{ $link }}">Terms of Service</a>, and “dply” is {{ $entity }}. “Customer Personal Data” is personal data in the content Customer or its users put into dply: code, files, databases, storage, environment variables, and the logs of Customer’s apps. Terms such as “controller”, “processor”, “personal data”, “processing” and “data subject” have the meanings given in the EU General Data Protection Regulation (GDPR).</p>
    <ul class="{{ $list }}">
        <li>Customer is the controller (or a processor acting for its own controller) of Customer Personal Data. dply is its processor (or sub-processor).</li>
        <li>dply is an independent controller for account, billing and usage data about Customer and its users. The <a href="/privacy" class="{{ $link }}">Privacy Policy</a> covers that data, not this DPA.</li>
        <li>This DPA meets the requirements of Article 28 of the GDPR and of the UK GDPR.</li>
    </ul>
</div></section>

<section id="details" class="{{ $section }}"><div class="{{ $wrap }}">
    <h2 class="{{ $h2 }}">2. Details of the processing</h2>
    <ul class="{{ $list }}">
        <li><span class="{{ $strong }}">Subject matter:</span> hosting and running Customer’s sites, apps and managed resources.</li>
        <li><span class="{{ $strong }}">Duration:</span> the term of the Terms of Service, plus the period until deletion under section 7.</li>
        <li><span class="{{ $strong }}">Nature:</span> storing, hosting, transmitting, building, serving, backing up, logging and deleting data.</li>
        <li><span class="{{ $strong }}">Purpose:</span> providing, securing and supporting dply for Customer under the Terms of Service.</li>
        <li><span class="{{ $strong }}">Categories of data and data subjects:</span> determined by Customer. dply does not choose what Customer stores. Typically they include Customer’s end users, visitors and staff, and their contact details, account data, content and technical data such as IP addresses and user agents in request logs.</li>
        <li><span class="{{ $strong }}">Special categories:</span> Customer should not process special category data or data regulated by HIPAA on dply unless it has decided dply’s measures (Annex II) are appropriate. dply has no security certifications and does not sign HIPAA business associate agreements.</li>
    </ul>
</div></section>

<section id="obligations" class="{{ $section }}"><div class="{{ $wrap }}">
    <h2 class="{{ $h2 }}">3. Processor obligations</h2>
    <ul class="{{ $list }}">
        <li><span class="{{ $strong }}">Instructions.</span> dply processes Customer Personal Data only on Customer’s documented instructions. The Terms of Service, this DPA and Customer’s configuration and use of dply are those instructions. If the law requires other processing, dply will tell Customer first unless the law forbids it. dply will tell Customer if it believes an instruction breaks data protection law.</li>
        <li><span class="{{ $strong }}">Confidentiality.</span> Everyone dply authorizes to process Customer Personal Data is bound by confidentiality.</li>
        <li><span class="{{ $strong }}">Security.</span> dply implements the technical and organizational measures in Annex II, as required by Article 32 of the GDPR. dply may update them, but will not reduce the overall level of protection.</li>
        <li><span class="{{ $strong }}">Customer’s part.</span> Customer is responsible for the lawfulness of its instructions and of the data it provides, for the notices and consents its data subjects need, and for the settings under its control, such as what it makes public, the credentials and share links it creates, and access within its organization.</li>
    </ul>
</div></section>

<section id="subprocessors" class="{{ $section }}"><div class="{{ $wrap }}">
    <h2 class="{{ $h2 }}">4. Sub-processors</h2>
    <ul class="{{ $list }}">
        <li><span class="{{ $strong }}">General authorization.</span> Customer authorizes dply to use sub-processors. The current list is in Annex III and on the <a href="/compliance#subprocessors" class="{{ $link }}">Security &amp; compliance</a> page.</li>
        <li><span class="{{ $strong }}">Contracts.</span> dply binds each sub-processor to data protection terms that protect Customer Personal Data at least as much as this DPA, and remains responsible for their performance.</li>
        <li><span class="{{ $strong }}">Notice of changes.</span> dply emails Customer’s organization owners at least 30 days before a new sub-processor starts processing Customer Personal Data.</li>
        <li><span class="{{ $strong }}">Objection.</span> Customer may object on reasonable data protection grounds by emailing <a href="mailto:{{ $supportEmail }}" class="{{ $link }}">{{ $supportEmail }}</a> within that period. We will discuss it in good faith. If we cannot resolve the objection, Customer may terminate the affected service, and dply will refund any prepaid fees for it covering the period after termination.</li>
    </ul>
</div></section>

<section id="assistance" class="{{ $section }}"><div class="{{ $wrap }}">
    <h2 class="{{ $h2 }}">5. Assistance</h2>
    <ul class="{{ $list }}">
        <li><span class="{{ $strong }}">Data subject requests.</span> Customer can access, correct, export and delete its data through dply. Where Customer cannot do that itself, dply will provide reasonable help. If dply receives a request from one of Customer’s data subjects, it will pass it to Customer and not answer it without Customer’s instruction, unless the law requires.</li>
        <li><span class="{{ $strong }}">DPIAs and consultation.</span> dply will give reasonable information to help Customer with data protection impact assessments and consultations with supervisory authorities about its processing on dply.</li>
    </ul>
</div></section>

<section id="breach" class="{{ $section }}"><div class="{{ $wrap }}">
    <h2 class="{{ $h2 }}">6. Personal data breaches</h2>
    <p class="{{ $p }}">dply will notify Customer’s organization owners by email without undue delay, and within 48 hours of becoming aware of a personal data breach affecting Customer Personal Data. The notice will describe what happened, the data and data subjects affected as far as known, the likely consequences, and what dply is doing about it, and will be updated as more is learned. dply will take reasonable steps to contain the breach and help Customer meet its own notification duties. A notice is not an admission of fault.</p>
</div></section>

<section id="deletion" class="{{ $section }}"><div class="{{ $wrap }}">
    <h2 class="{{ $h2 }}">7. Deletion and return</h2>
    <ul class="{{ $list }}">
        <li>Customer can export its data at any time while its organization is active or paused, and can delete it itself.</li>
        <li>Data Customer deletes is removed from the live service. Database backups roll off within 7 days, and request logs are kept for {{ $requestLogDays }} days.</li>
        <li>If an organization is paused for non-payment, its data is deleted {{ $keepDays }} days after the pause, after emailed warnings 7 days and 1 day before, as described in the Terms of Service.</li>
        <li>dply may keep data where the law requires it, and will keep protecting it under this DPA while it does.</li>
    </ul>
</div></section>

<section id="audits" class="{{ $section }}"><div class="{{ $wrap }}">
    <h2 class="{{ $h2 }}">8. Audits</h2>
    <p class="{{ $p }}">dply will make available the information reasonably needed to show it meets this DPA, by answering Customer’s written questions and security questionnaires. dply has no third-party audit reports, such as SOC 2 or ISO 27001, today. If that information is not enough, or a supervisory authority requires it, Customer may audit dply once a year, with at least 30 days’ notice, during business hours, at its own cost, under confidentiality, and in a way that does not put other customers’ data or the service at risk.</p>
</div></section>

<section id="transfers" class="{{ $section }}"><div class="{{ $wrap }}">
    <h2 class="{{ $h2 }}">9. International transfers</h2>
    <p class="{{ $p }}">dply is in the United States. Where Customer Personal Data is transferred from the EEA, the UK or Switzerland to a country without an adequacy decision, the following are incorporated into this DPA by reference:</p>
    <ul class="{{ $list }}">
        <li><span class="{{ $strong }}">EEA:</span> the Standard Contractual Clauses adopted by Commission Implementing Decision (EU) 2021/914 (“SCCs”), Module 2 (controller to processor), and Module 3 (processor to processor) where Customer is a processor. For them: clause 7 (docking) applies; under clause 9, option 2 (general authorization) applies with the notice period in section 4; the option in clause 11 does not apply; under clauses 17 and 18, the law and courts of Ireland apply; Annexes I, II and III of this DPA complete the SCC annexes; and the supervisory authority is the one competent for Customer.</li>
        <li><span class="{{ $strong }}">UK:</span> the International Data Transfer Addendum to the SCCs issued by the UK Information Commissioner (version B1.0), completed with the information in this DPA. Either party may end it as allowed by its section 19.</li>
        <li><span class="{{ $strong }}">Switzerland:</span> the SCCs apply with these changes: the Swiss Federal Data Protection and Information Commissioner is the competent authority for transfers under the Swiss FADP; references to the GDPR include the FADP; and “Member State” includes Switzerland so Swiss data subjects can enforce their rights there.</li>
    </ul>
    <p class="{{ $p }}">If the SCCs conflict with this DPA, the SCCs win.</p>
</div></section>

<section id="ccpa" class="{{ $section }}"><div class="{{ $wrap }}">
    <h2 class="{{ $h2 }}">10. US state privacy laws (CCPA)</h2>
    <p class="{{ $p }}">Where the California Consumer Privacy Act, as amended by the CPRA, or a similar US state law applies, dply is Customer’s service provider (or processor), and dply will not:</p>
    <ul class="{{ $list }}">
        <li>sell or share Customer Personal Data, including for cross-context behavioral advertising;</li>
        <li>retain, use or disclose it for any purpose other than providing dply to Customer (the business purpose), or outside the direct business relationship with Customer;</li>
        <li>combine it with personal data dply receives from others or collects itself, except as the law permits.</li>
    </ul>
    <p class="{{ $p }}">dply will comply with those laws, give the same level of protection they require, and tell Customer if it can no longer meet them. Customer may take reasonable steps to stop and fix unauthorized use.</p>
</div></section>

<section id="precedence" class="{{ $section }}"><div class="{{ $wrap }}">
    <h2 class="{{ $h2 }}">11. Precedence and liability</h2>
    <p class="{{ $p }}">For the processing of Customer Personal Data, this DPA takes precedence over the Terms of Service if they conflict. Each party’s liability under this DPA is subject to the limits in the Terms of Service, except where the law or the SCCs do not allow it. This DPA ends when dply stops processing Customer Personal Data.</p>
</div></section>

<section id="annex-1" class="{{ $section }}"><div class="{{ $wrap }}">
    <h2 class="{{ $h2 }}">Annex I: Parties and description</h2>
    <h3 class="{{ $h3 }}">A. Parties</h3>
    <ul class="{{ $list }}">
        <li><span class="{{ $strong }}">Data exporter:</span> Customer, as identified in its dply account. Contact: the organization’s owners. Role: controller (or processor). Activities: using dply to host its sites, apps and data. Signature and date: by accepting the Terms of Service.</li>
        <li><span class="{{ $strong }}">Data importer:</span> {{ $entity }}, {{ $state }}, USA. Contact: <a href="mailto:{{ $supportEmail }}" class="{{ $link }}">{{ $supportEmail }}</a>. Role: processor (or sub-processor). Activities: providing dply. Signature and date: by making dply available under the Terms of Service.</li>
    </ul>
    <h3 class="{{ $h3 }}">B. Description of transfer</h3>
    <ul class="{{ $list }}">
        <li><span class="{{ $strong }}">Data subjects, categories of data, and sensitive data:</span> as in section 2, determined by Customer.</li>
        <li><span class="{{ $strong }}">Frequency:</span> continuous, while Customer uses dply.</li>
        <li><span class="{{ $strong }}">Nature and purpose:</span> as in section 2.</li>
        <li><span class="{{ $strong }}">Retention:</span> as in section 7.</li>
        <li><span class="{{ $strong }}">Transfers to sub-processors:</span> as in Annex III, for the same purposes and duration.</li>
    </ul>
    <h3 class="{{ $h3 }}">C. Competent supervisory authority</h3>
    <p class="{{ $p }}">The supervisory authority competent for the data exporter under clause 13 of the SCCs.</p>
</div></section>

<section id="annex-2" class="{{ $section }}"><div class="{{ $wrap }}">
    <h2 class="{{ $h2 }}">Annex II: Technical and organizational measures</h2>
    <ul class="{{ $list }}">
        <li><span class="{{ $strong }}">Encryption in transit:</span> every site is served over HTTPS; custom-domain certificates are issued by Cloudflare; dply databases and Valkey accept TLS connections only.</li>
        <li><span class="{{ $strong }}">Encryption at rest:</span> Cloudflare encrypts R2, KV and D1 data at rest; dply databases and Valkey run on DigitalOcean volumes encrypted at rest. Environment variables, organization secrets, Git provider tokens, Cloudflare credentials, notification settings and resource connection strings are encrypted in dply’s database with AES-256. Passwords are hashed.</li>
        <li><span class="{{ $strong }}">Tenant isolation:</span> resources belong to the organization that created them, and bindings resolve only inside that organization. A hostname can belong to only one app. SSR apps run as their own Cloudflare Worker scripts in Cloudflare’s isolate sandbox; container apps run in their own containers.</li>
        <li><span class="{{ $strong }}">Build sandboxing:</span> each build runs in its own container as a non-root user, with all Linux capabilities dropped, privilege escalation blocked, memory, CPU and process limits, and a network where builds can’t reach each other. Package caches are per organization. Container images are built in an isolated builder with no deploy credentials present.</li>
        <li><span class="{{ $strong }}">Outbound request protection:</span> when dply calls a customer-supplied URL, it refuses private, loopback, link-local and cloud-metadata addresses.</li>
        <li><span class="{{ $strong }}">Access control:</span> Owner, Admin, Member, Deployer and Viewer roles, plus per-app roles; passkeys and authenticator-app two-factor authentication; API tokens limited to granted abilities and their owner’s role, revoked when the owner leaves.</li>
        <li><span class="{{ $strong }}">Logging and accountability:</span> an organization activity log records who changed what, kept for {{ $auditDays }} days; request logs are kept for {{ $requestLogDays }} days.</li>
        <li><span class="{{ $strong }}">Availability and recovery:</span> dply Postgres, MySQL and MongoDB stream changes to storage and take a full backup daily, kept for 7 days, with point-in-time restore. The platform’s own keys and database backups are escrowed daily off-site with age public-key encryption, and a daily drill checks that the newest backup decrypts.</li>
        <li><span class="{{ $strong }}">Incident response and disclosure:</span> affected organization owners are notified of incidents (section 6). Vulnerabilities can be reported to <a href="mailto:{{ $securityEmail }}" class="{{ $link }}">{{ $securityEmail }}</a>.</li>
        <li><span class="{{ $strong }}">Data regions:</span> object storage buckets can be created in Cloudflare’s EU jurisdiction and container apps can be restricted to EU regions. dply databases and Valkey are in New York, USA.</li>
    </ul>
</div></section>

<section id="annex-3" class="{{ $section }}"><div class="{{ $wrap }}">
    <h2 class="{{ $h2 }}">Annex III: Sub-processors</h2>
    <div class="mt-6 max-w-4xl overflow-x-auto border border-edge-line">
        <table class="min-w-full text-left text-sm">
            <thead class="font-terminal border-b border-edge-line bg-edge-panel text-[11px] uppercase tracking-[0.16em] text-edge-faint">
                <tr><th class="{{ $th }}">Sub-processor</th><th class="{{ $th }}">Purpose</th><th class="{{ $th }}">Location</th></tr>
            </thead>
            <tbody class="divide-y divide-edge-line">
                @foreach ($subprocessors as [$name, $purpose, $where])
                    <tr><td class="{{ $td }} font-medium text-edge-text">{{ $name }}</td><td class="{{ $td }}">{{ $purpose }}</td><td class="{{ $td }}">{{ $where }}</td></tr>
                @endforeach
            </tbody>
        </table>
    </div>
</div></section>

@include('legal.partials.layout-end')
