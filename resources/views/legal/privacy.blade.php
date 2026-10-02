@php
    $entity = config('legal.entity');
    $state = config('legal.state');
    $supportEmail = config('dply.support_email');
    $securityEmail = config('dply.security_email');
    $auditDays = max(30, (int) config('audit.retention_days', 365));
    $requestLogDays = (int) config('edge.analytics.access_logs_days', 7);
    $keepDays = (int) config('subscription.standard.trial.keep_data_days', 30);

    $section = 'border-b border-edge-line scroll-mt-16';
    $wrap = 'mx-auto max-w-6xl px-6 py-12 lg:px-10';
    $h2 = 'text-2xl font-bold tracking-[-0.02em]';
    $p = 'mt-4 max-w-3xl text-sm leading-6 text-edge-mute';
    $list = 'mt-4 max-w-3xl list-disc space-y-2 pl-5 text-sm leading-6 text-edge-mute';
    $strong = 'font-semibold text-edge-text';
    $link = 'border-b border-edge-lime/50 pb-0.5 text-edge-text transition-colors hover:border-edge-lime hover:text-edge-lime';
    $th = 'px-5 py-3 font-normal';
    $td = 'px-5 py-3 align-top text-edge-mute';

    $bases = [
        ['Create and run your account, organizations and apps', 'Account, organization, Git provider, usage and log data', 'Performing our contract with you'],
        ['Bill you and keep financial records', 'Billing and usage data', 'Contract; legal obligation (tax and accounting)'],
        ['Keep dply secure, and prevent fraud and abuse', 'Sign-in, security, audit and log data', 'Legitimate interests (protecting you, other customers and dply)'],
        ['Send service, billing and security emails', 'Account and organization data', 'Contract; legitimate interests'],
        ['Answer support requests', 'Whatever you send us, plus account data', 'Contract; legitimate interests'],
        ['Improve and fix dply, using aggregated usage', 'Usage and log data', 'Legitimate interests'],
        ['Comply with the law and enforce our terms', 'Any of the above, as needed', 'Legal obligation; legitimate interests'],
    ];
@endphp

@include('legal.partials.layout-start', [
    'eyebrow' => __('Legal'),
    'title' => __('Privacy Policy'),
    'intro' => __('This policy explains what personal data :entity collects about dply customers and visitors, why, who we share it with, and the choices and rights you have.', ['entity' => $entity]),
    'toc' => [
        ['scope', 'Scope and who we are'],
        ['customer-apps', 'Data inside your apps'],
        ['collect', 'What we collect'],
        ['cookies', 'Cookies and similar technology'],
        ['purposes', 'How we use it and why'],
        ['sharing', 'Who we share it with'],
        ['transfers', 'International transfers'],
        ['retention', 'How long we keep it'],
        ['security', 'Security'],
        ['rights', 'Your rights'],
        ['exercise', 'How to exercise your rights'],
        ['children', 'Children'],
        ['changes', 'Changes to this policy'],
        ['contact', 'Contact'],
    ],
])

<section id="scope" class="{{ $section }}"><div class="{{ $wrap }}">
    <h2 class="{{ $h2 }}">1. Scope and who we are</h2>
    <p class="{{ $p }}">dply is operated by {{ $entity }}, a company in {{ $state }}, USA (“dply”, “we”, “us”). We are the controller of the personal data described in this policy: data about the people who sign up for dply, belong to an organization on dply, pay for it, contact us, or visit dply’s own website.</p>
</div></section>

<section id="customer-apps" class="{{ $section }}"><div class="{{ $wrap }}">
    <h2 class="{{ $h2 }}">2. Data inside your apps</h2>
    <p class="{{ $p }}">This policy does not cover the data that our customers put into the sites, apps, databases and storage they host on dply, or data about the people who visit those sites. For that data, the customer is the controller and dply is the processor. We handle it only on the customer’s instructions, under the <a href="/dpa" class="{{ $link }}">Data Processing Addendum</a>. If you are a user of a site hosted on dply and have a question about your data, contact the site’s owner.</p>
</div></section>

<section id="collect" class="{{ $section }}"><div class="{{ $wrap }}">
    <h2 class="{{ $h2 }}">3. What we collect</h2>
    <ul class="{{ $list }}">
        <li><span class="{{ $strong }}">Account data.</span> Your name, email address, password (stored hashed), passkeys, two-factor settings, and profile settings. If you sign in with GitHub, GitLab or Bitbucket, the profile details that provider shares with us, such as your username and email.</li>
        <li><span class="{{ $strong }}">Organization data.</span> Organization names, members, roles, invitations, and the settings of your apps and resources.</li>
        <li><span class="{{ $strong }}">Billing data.</span> Your plan, subscription status, invoices, and billing contact details. Payments are handled by Stripe. We do not receive or store your card number; we receive a customer reference and limited card details such as brand and last four digits.</li>
        <li><span class="{{ $strong }}">Git provider tokens.</span> When you connect GitHub, GitLab or Bitbucket, we store access tokens so we can read your repositories and deploy them. They are encrypted.</li>
        <li><span class="{{ $strong }}">Usage data.</span> What your apps use, such as requests, bandwidth, storage, build time and compute, which we measure to bill you and to show you usage.</li>
        <li><span class="{{ $strong }}">Logs and security data.</span> IP addresses, browser user agent, sign-in and session records, the organization activity (audit) log, API token use, and request logs for your apps.</li>
        <li><span class="{{ $strong }}">Communications.</span> What you send us by email or support request.</li>
    </ul>
    <p class="{{ $p }}">We get this data from you, from the Git provider you sign in with, from Stripe, and from running the service. We do not buy personal data.</p>
</div></section>

<section id="cookies" class="{{ $section }}"><div class="{{ $wrap }}">
    <h2 class="{{ $h2 }}">4. Cookies and similar technology</h2>
    <p class="{{ $p }}">dply uses only what it needs to work:</p>
    <ul class="{{ $list }}">
        <li><span class="{{ $strong }}">Session cookie</span> to keep you signed in, and a <span class="{{ $strong }}">“remember me” cookie</span> if you choose it.</li>
        <li><span class="{{ $strong }}">Security cookie</span> (XSRF-TOKEN) that protects forms against cross-site request forgery.</li>
        <li><span class="{{ $strong }}">Browser storage</span> for interface preferences, such as whether the sidebar is collapsed. It stays in your browser.</li>
    </ul>
    <p class="{{ $p }}">dply does not use advertising cookies or third-party analytics or tracking on its website or dashboard. Our web fonts are loaded from Bunny Fonts (fonts.bunny.net), which receives your IP address when your browser fetches them.</p>
</div></section>

<section id="purposes" class="{{ $section }}"><div class="{{ $wrap }}">
    <h2 class="{{ $h2 }}">5. How we use it and why</h2>
    <p class="{{ $p }}">If you are in the EU, UK or Switzerland, the law requires a legal basis for each use. Ours are:</p>
    <div class="mt-6 max-w-4xl overflow-x-auto border border-edge-line">
        <table class="min-w-full text-left text-sm">
            <thead class="font-terminal border-b border-edge-line bg-edge-panel text-[11px] uppercase tracking-[0.16em] text-edge-faint">
                <tr><th class="{{ $th }}">Purpose</th><th class="{{ $th }}">Data</th><th class="{{ $th }}">Legal basis</th></tr>
            </thead>
            <tbody class="divide-y divide-edge-line">
                @foreach ($bases as [$purpose, $data, $basis])
                    <tr><td class="{{ $td }} font-medium text-edge-text">{{ $purpose }}</td><td class="{{ $td }}">{{ $data }}</td><td class="{{ $td }}">{{ $basis }}</td></tr>
                @endforeach
            </tbody>
        </table>
    </div>
    <p class="{{ $p }}">We do not use your data for advertising, we do not sell it, and we do not make decisions about you based solely on automated processing that have legal or similarly significant effects.</p>
</div></section>

<section id="sharing" class="{{ $section }}"><div class="{{ $wrap }}">
    <h2 class="{{ $h2 }}">6. Who we share it with</h2>
    <ul class="{{ $list }}">
        <li><span class="{{ $strong }}">Service providers</span> that run dply for us: Cloudflare (hosting, storage, delivery, DNS and email), DigitalOcean (databases), Hetzner (uptime checks), Stripe (payments), and GitHub, GitLab or Bitbucket if you connect them. The current list is on the <a href="/compliance#subprocessors" class="{{ $link }}">Security &amp; compliance</a> page. They may use the data only to provide their service to us.</li>
        <li><span class="{{ $strong }}">Your organization.</span> Other members of an organization you belong to can see your name, email, role and the changes you make there.</li>
        <li><span class="{{ $strong }}">Legal and safety.</span> Authorities or others when the law requires it, or when needed to protect the rights, safety or property of our customers, the public or dply.</li>
        <li><span class="{{ $strong }}">Business transfer.</span> A buyer or successor if dply is merged, acquired or sold, under this policy.</li>
    </ul>
    <p class="{{ $p }}"><span class="{{ $strong }}">We do not sell your personal information, and we do not share it for cross-context behavioral advertising</span>, as those terms are defined in California law.</p>
</div></section>

<section id="transfers" class="{{ $section }}"><div class="{{ $wrap }}">
    <h2 class="{{ $h2 }}">7. International transfers</h2>
    <p class="{{ $p }}">dply is based in the United States, and your account data is processed in the US. Some providers process data elsewhere, such as Cloudflare’s global network and Hetzner in Germany. When we transfer personal data from the EU, UK or Switzerland to a country without an adequacy decision, we rely on the European Commission’s Standard Contractual Clauses, with the UK Addendum and Swiss adjustments where they apply, or another lawful transfer mechanism.</p>
</div></section>

<section id="retention" class="{{ $section }}"><div class="{{ $wrap }}">
    <h2 class="{{ $h2 }}">8. How long we keep it</h2>
    <ul class="{{ $list }}">
        <li><span class="{{ $strong }}">Account and organization data:</span> while your account is open. You can delete your account from your profile.</li>
        <li><span class="{{ $strong }}">Paused organizations:</span> if an organization is paused because it has no paid plan, its apps, resources and data are deleted {{ $keepDays }} days after the pause, after emailed warnings 7 days and 1 day before. The organization, its members and billing history remain.</li>
        <li><span class="{{ $strong }}">Request logs:</span> {{ $requestLogDays }} days.</li>
        <li><span class="{{ $strong }}">Audit log:</span> {{ $auditDays }} days.</li>
        <li><span class="{{ $strong }}">Database backups:</span> 7 days on Starter, 14 on Pro, 30 on Team; a deleted database’s backups within a week.</li>
        <li><span class="{{ $strong }}">Billing records:</span> as long as tax and accounting laws require.</li>
        <li><span class="{{ $strong }}">Support email:</span> as long as needed to handle your request and keep a record of it.</li>
    </ul>
    <p class="{{ $p }}">We may keep data longer when the law requires it or to resolve a dispute or investigate abuse.</p>
</div></section>

<section id="security" class="{{ $section }}"><div class="{{ $wrap }}">
    <h2 class="{{ $h2 }}">9. Security</h2>
    <p class="{{ $p }}">All traffic uses HTTPS. Secrets, Git provider tokens and connection strings are encrypted in our database with AES-256, and passwords are hashed. Access inside organizations is controlled by roles, and two-factor authentication is available on every account. The <a href="/compliance" class="{{ $link }}">Security &amp; compliance</a> page has the details. Report a vulnerability to <a href="mailto:{{ $securityEmail }}" class="{{ $link }}">{{ $securityEmail }}</a>.</p>
</div></section>

<section id="rights" class="{{ $section }}"><div class="{{ $wrap }}">
    <h2 class="{{ $h2 }}">10. Your rights</h2>
    <p class="{{ $p }}">Depending on where you live, including under the EU GDPR, the UK GDPR and California’s CCPA as amended by the CPRA, you have the right to:</p>
    <ul class="{{ $list }}">
        <li><span class="{{ $strong }}">Access</span> the personal data we hold about you, and know how we use it.</li>
        <li><span class="{{ $strong }}">Correct</span> data that is wrong or incomplete.</li>
        <li><span class="{{ $strong }}">Delete</span> your data.</li>
        <li><span class="{{ $strong }}">Portability:</span> get a copy in a common, machine-readable format.</li>
        <li><span class="{{ $strong }}">Object to</span> or ask us to restrict processing based on legitimate interests.</li>
        <li><span class="{{ $strong }}">Opt out</span> of the sale or sharing of personal information. We do neither, so there is nothing to opt out of.</li>
        <li><span class="{{ $strong }}">Withdraw consent</span> where we rely on it.</li>
        <li><span class="{{ $strong }}">Not be discriminated against</span> for using these rights.</li>
        <li><span class="{{ $strong }}">Complain</span> to your data protection authority. We would like the chance to help first.</li>
    </ul>
    <p class="{{ $p }}">Much of this you can do yourself: edit your profile, export or delete your apps and resources, and delete your account.</p>
</div></section>

<section id="exercise" class="{{ $section }}"><div class="{{ $wrap }}">
    <h2 class="{{ $h2 }}">11. How to exercise your rights</h2>
    <p class="{{ $p }}">Email <a href="mailto:{{ $supportEmail }}" class="{{ $link }}">{{ $supportEmail }}</a> from the address on your account. We may need to confirm your identity before acting. You can use an authorized agent; we may ask for proof that they act for you.</p>
    <p class="{{ $p }}">We respond within one month under the GDPR and UK GDPR, and within 45 days under California law. If a request is complex, we may extend that as the law allows (by up to two more months under the GDPR, or 45 more days under California law) and will tell you why.</p>
</div></section>

<section id="children" class="{{ $section }}"><div class="{{ $wrap }}">
    <h2 class="{{ $h2 }}">12. Children</h2>
    <p class="{{ $p }}">dply is not for anyone under 16. We do not knowingly collect personal data from children under 16. If you believe a child has given us data, contact us and we will delete it.</p>
</div></section>

<section id="changes" class="{{ $section }}"><div class="{{ $wrap }}">
    <h2 class="{{ $h2 }}">13. Changes to this policy</h2>
    <p class="{{ $p }}">We may update this policy. The date at the top shows the current version. For material changes, we email account holders before they take effect.</p>
</div></section>

<section id="contact" class="{{ $section }}"><div class="{{ $wrap }}">
    <h2 class="{{ $h2 }}">14. Contact</h2>
    <p class="{{ $p }}">{{ $entity }}, {{ $state }}, USA. Email <a href="mailto:{{ $supportEmail }}" class="{{ $link }}">{{ $supportEmail }}</a> for privacy questions and requests.</p>
</div></section>

@include('legal.partials.layout-end')
