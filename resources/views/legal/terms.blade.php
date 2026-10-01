@php
    $entity = config('legal.entity');
    $state = config('legal.state');
    $supportEmail = config('dply.support_email');
    $securityEmail = config('dply.security_email');
    $agent = config('legal.dmca_agent');
    $keepDays = (int) config('subscription.standard.trial.keep_data_days', 30);
    $trialDays = (int) config('subscription.standard.trial.days', 5);
    $trialCap = '$'.rtrim(rtrim(number_format(((int) config('subscription.standard.trial.spending_limit_cents', 200)) / 100, 2), '0'), '.');

    $section = 'border-b border-edge-line scroll-mt-16';
    $wrap = 'mx-auto max-w-6xl px-6 py-12 lg:px-10';
    $h2 = 'text-2xl font-bold tracking-[-0.02em]';
    $p = 'mt-4 max-w-3xl text-sm leading-6 text-edge-mute';
    $list = 'mt-4 max-w-3xl list-disc space-y-2 pl-5 text-sm leading-6 text-edge-mute';
    $strong = 'font-semibold text-edge-text';
    $link = 'border-b border-edge-lime/50 pb-0.5 text-edge-text transition-colors hover:border-edge-lime hover:text-edge-lime';
@endphp

@include('legal.partials.layout-start', [
    'eyebrow' => __('Legal'),
    'title' => __('Terms of Service'),
    'intro' => __('These terms are the agreement between you and :entity for your use of dply. Please read them. By creating an account or using dply, you agree to them.', ['entity' => $entity]),
    'toc' => [
        ['parties', 'Who we are'],
        ['accounts', 'Accounts and eligibility'],
        ['service', 'The service'],
        ['content', 'Your content'],
        ['responsibilities', 'Your responsibilities'],
        ['acceptable-use', 'Acceptable use'],
        ['fees', 'Fees and billing'],
        ['non-payment', 'Non-payment, pausing and deletion'],
        ['termination', 'Suspension and termination'],
        ['copyright', 'Copyright (DMCA)'],
        ['data-protection', 'Personal data'],
        ['security', 'Security'],
        ['confidentiality', 'Confidentiality'],
        ['third-parties', 'Third-party services'],
        ['warranties', 'Disclaimer of warranties'],
        ['liability', 'Limitation of liability'],
        ['indemnity', 'Indemnity'],
        ['law', 'Governing law'],
        ['changes', 'Changes to these terms'],
        ['misc', 'General'],
    ],
])

<section id="parties" class="{{ $section }}"><div class="{{ $wrap }}">
    <h2 class="{{ $h2 }}">1. Who we are</h2>
    <p class="{{ $p }}">dply is operated by {{ $entity }} (“dply”, “we”, “us”). “You” means the person who accepts these terms and, if you use dply for a company or other organization, that organization. If you accept for an organization, you confirm you have authority to bind it.</p>
</div></section>

<section id="accounts" class="{{ $section }}"><div class="{{ $wrap }}">
    <h2 class="{{ $h2 }}">2. Accounts and eligibility</h2>
    <ul class="{{ $list }}">
        <li>You must be at least 16 years old and able to form a binding contract.</li>
        <li>Give us accurate information and keep it current, including a working email address. We send important notices there.</li>
        <li>You are responsible for everything done under your account and your organizations, and for keeping your password, passkeys, two-factor codes and API tokens safe. Tell us at <a href="mailto:{{ $securityEmail }}" class="{{ $link }}">{{ $securityEmail }}</a> if you think someone has accessed your account without permission.</li>
        <li>Organization owners control their organization, including its members, roles, billing and data.</li>
    </ul>
</div></section>

<section id="service" class="{{ $section }}"><div class="{{ $wrap }}">
    <h2 class="{{ $h2 }}">3. The service</h2>
    <p class="{{ $p }}">dply builds and hosts websites and apps (static, server-rendered and container apps) and provides managed resources such as object storage, key-value stores, SQL databases, queues, realtime connections, and Postgres, MySQL, MongoDB and Valkey databases. dply runs on infrastructure from other providers, mainly Cloudflare and DigitalOcean.</p>
    <p class="{{ $p }}">We improve dply all the time. We may add, change or remove features. If we remove a feature you pay for, or make a change that materially reduces the service, we will tell you in advance by email where we reasonably can. We do not offer a service level agreement unless we agree one with you in writing.</p>
</div></section>

<section id="content" class="{{ $section }}"><div class="{{ $wrap }}">
    <h2 class="{{ $h2 }}">4. Your content</h2>
    <p class="{{ $p }}">“Your content” means everything you or your users put into dply: code, builds, files, databases, environment variables, logs and other data.</p>
    <ul class="{{ $list }}">
        <li><span class="{{ $strong }}">You own it.</span> These terms do not give us any ownership of your content.</li>
        <li><span class="{{ $strong }}">You give us a limited license</span> to host, copy, store, transmit, process and display your content only as needed to provide, secure and support dply for you, and as the law requires.</li>
        <li><span class="{{ $strong }}">You confirm you have the rights</span> to your content and to let us handle it this way.</li>
    </ul>
    <p class="{{ $p }}">We do not look at your content except to provide the service, to support you when you ask, to investigate abuse or security issues, or when the law requires it.</p>
</div></section>

<section id="responsibilities" class="{{ $section }}"><div class="{{ $wrap }}">
    <h2 class="{{ $h2 }}">5. Your responsibilities</h2>
    <p class="{{ $p }}">You control how your apps and resources are configured. That means you are responsible for:</p>
    <ul class="{{ $list }}">
        <li><span class="{{ $strong }}">What you make public.</span> Anything you serve publicly, such as a site, an app, or an object storage bucket served on a public path, can be read by anyone. Choose carefully what you publish.</li>
        <li><span class="{{ $strong }}">Credentials you create and share.</span> This includes S3 access keys, share links, API tokens, database connection strings and environment variables. Anyone who has one can use it until you revoke it or it expires.</li>
        <li><span class="{{ $strong }}">Your own end users.</span> You are responsible for your apps’ users and their data, and for complying with the laws that apply to your apps, including privacy, consumer protection and export laws. You must give your users any notices and get any consents the law requires.</li>
        <li><span class="{{ $strong }}">Your backups.</span> Keep your own copies of anything important. dply database backups roll off after 7 days.</li>
        <li><span class="{{ $strong }}">Sensitive data.</span> dply has no SOC 2, ISO 27001 or similar certification and does not sign HIPAA business associate agreements. Do not store health data regulated by HIPAA, payment card data in scope for PCI DSS, or other data that requires those controls.</li>
    </ul>
</div></section>

<section id="acceptable-use" class="{{ $section }}"><div class="{{ $wrap }}">
    <h2 class="{{ $h2 }}">6. Acceptable use</h2>
    <p class="{{ $p }}">You must follow the <a href="/acceptable-use" class="{{ $link }}">Acceptable Use Policy</a>. It is part of these terms. You are responsible for your members’ and your apps’ compliance with it.</p>
</div></section>

<section id="fees" class="{{ $section }}"><div class="{{ $wrap }}">
    <h2 class="{{ $h2 }}">7. Fees and billing</h2>
    <ul class="{{ $list }}">
        <li><span class="{{ $strong }}">Plans.</span> dply is sold on monthly plans. Each plan has a monthly fee and includes a usage credit. Current prices are on the <a href="/pricing" class="{{ $link }}">pricing page</a>.</li>
        <li><span class="{{ $strong }}">Usage.</span> Usage such as requests, bandwidth, storage, build time and compute is billed after each billing period at the published rates. The plan’s included usage credit is applied first.</li>
        <li><span class="{{ $strong }}">Trial.</span> A new organization can try a plan for {{ $trialDays }} days. A card is required to start. The trial is not charged, and its usage is capped at {{ $trialCap }}; if it reaches the cap, the organization is paused until the trial converts. Unless you cancel before the trial ends, the plan starts and is charged to your card.</li>
        <li><span class="{{ $strong }}">Payment.</span> Payments are processed by Stripe. You authorize us to charge your card for fees and usage when due. We do not receive or store your card number.</li>
        <li><span class="{{ $strong }}">Taxes.</span> Prices do not include taxes. You are responsible for any taxes that apply, other than taxes on our income.</li>
        <li><span class="{{ $strong }}">Cancellation.</span> You can cancel at any time from the Billing page. Your plan runs until the end of the period you paid for. Except where the law requires, fees already paid are not refunded.</li>
        <li><span class="{{ $strong }}">Price changes.</span> We will email organization owners at least 30 days before a price increase applies to you.</li>
    </ul>
</div></section>

<section id="non-payment" class="{{ $section }}"><div class="{{ $wrap }}">
    <h2 class="{{ $h2 }}">8. Non-payment, pausing and deletion</h2>
    <p class="{{ $p }}">If your organization has no paid plan (for example, the trial ended without a plan, a subscription was canceled and its period ended, or a payment failed after Stripe’s retries), we pause the organization. While paused, your sites serve a paused page, apps and workers stop, and your data is kept.</p>
    <ul class="{{ $list }}">
        <li>A paused organization’s apps, resources and data are deleted {{ $keepDays }} days after the pause.</li>
        <li>We email the organization’s owners 7 days and 1 day before deletion. Deletion does not happen without both warnings; if a warning is late, the deletion date moves back.</li>
        <li>Choosing a plan before the deletion date resumes the organization and cancels the deletion.</li>
        <li>Deleted data cannot be recovered. Export anything you need before the deletion date.</li>
    </ul>
    <p class="{{ $p }}">See <a href="/docs/paused-accounts" class="{{ $link }}">Paused accounts</a> for details.</p>
</div></section>

<section id="termination" class="{{ $section }}"><div class="{{ $wrap }}">
    <h2 class="{{ $h2 }}">9. Suspension and termination</h2>
    <ul class="{{ $list }}">
        <li><span class="{{ $strong }}">By you.</span> You can stop using dply at any time. You can delete apps, resources, organizations and your account yourself.</li>
        <li><span class="{{ $strong }}">By us.</span> We may suspend or disable an app, a resource or an organization, or end these terms for you, if you materially breach these terms or the Acceptable Use Policy, if you do not pay, if your use puts dply, other customers or the public at risk, or if the law requires it. Where it is safe and lawful, we will tell you first and give you a chance to fix the problem.</li>
        <li><span class="{{ $strong }}">Your data after termination.</span> Unless the law or safety prevents it, you will have a chance to export your content before we delete it. For non-payment, the timeline in section 8 applies.</li>
        <li>Sections that by their nature should survive termination do, including 4 (license ends except as needed to delete), 7 (unpaid fees), 15, 16, 17 and 18.</li>
    </ul>
</div></section>

<section id="copyright" class="{{ $section }}"><div class="{{ $wrap }}">
    <h2 class="{{ $h2 }}">10. Copyright (DMCA)</h2>
    <p class="{{ $p }}">We respond to notices of copyright infringement under the US Digital Millennium Copyright Act (DMCA).</p>
    <ul class="{{ $list }}">
        <li><span class="{{ $strong }}">Notice and takedown.</span> If you believe content hosted on dply infringes your copyright, send a notice to our designated agent. When we receive a valid notice, we remove or disable access to the content and tell the customer.</li>
        <li><span class="{{ $strong }}">Counter-notice.</span> If your content was removed and you believe it was a mistake or misidentification, you can send a counter-notice. We forward it to the person who sent the notice. Unless they tell us within 10 to 14 business days that they have filed a court action, we may restore the content.</li>
        <li><span class="{{ $strong }}">Repeat infringers.</span> We terminate, in appropriate circumstances, the accounts and organizations of customers who repeatedly infringe.</li>
    </ul>
    <p class="{{ $p }}">What a notice and counter-notice must contain is set out in the <a href="/acceptable-use#copyright" class="{{ $link }}">Acceptable Use Policy</a>. Our designated agent:</p>
    <p class="{{ $p }}">
        <span class="{{ $strong }}">{{ $agent['name'] }}</span><br>
        {{ $entity }}<br>
        @if (filled($agent['address'] ?? null))
            {{ $agent['address'] }}<br>
        @endif
        <a href="mailto:{{ $agent['email'] }}" class="{{ $link }}">{{ $agent['email'] }}</a>
    </p>
</div></section>

<section id="data-protection" class="{{ $section }}"><div class="{{ $wrap }}">
    <h2 class="{{ $h2 }}">11. Personal data</h2>
    <p class="{{ $p }}">When your content includes personal data, we process it on your behalf under the <a href="/dpa" class="{{ $link }}">Data Processing Addendum</a>, which is part of these terms. How we handle personal data about you as our customer is described in the <a href="/privacy" class="{{ $link }}">Privacy Policy</a>.</p>
</div></section>

<section id="security" class="{{ $section }}"><div class="{{ $wrap }}">
    <h2 class="{{ $h2 }}">12. Security</h2>
    <p class="{{ $p }}">We use reasonable technical and organizational measures to protect dply and your content. They are described on the <a href="/compliance" class="{{ $link }}">Security &amp; compliance</a> page. dply has no third-party security certifications or audit reports today, such as SOC 2 or ISO 27001. No system is perfectly secure, and we cannot guarantee that your content will never be accessed, lost or damaged. Report vulnerabilities to <a href="mailto:{{ $securityEmail }}" class="{{ $link }}">{{ $securityEmail }}</a>.</p>
</div></section>

<section id="confidentiality" class="{{ $section }}"><div class="{{ $wrap }}">
    <h2 class="{{ $h2 }}">13. Confidentiality</h2>
    <p class="{{ $p }}">Each of us will keep the other’s non-public information that is marked confidential, or that a reasonable person would see as confidential, private. We will use it only to perform under these terms and share it only with people who need it and are bound to keep it confidential. This does not cover information that is public through no fault of the receiver, that the receiver already had or developed on its own, or that the law requires to be disclosed (with notice to the other party where lawful). Your content is always treated as your confidential information.</p>
</div></section>

<section id="third-parties" class="{{ $section }}"><div class="{{ $wrap }}">
    <h2 class="{{ $h2 }}">14. Third-party services</h2>
    <p class="{{ $p }}">dply works with services run by other companies, such as Cloudflare, DigitalOcean, Stripe, and GitHub, GitLab and Bitbucket. When you connect or use one directly (for example, signing in with GitHub or paying through Stripe), that provider’s own terms also apply to you. We are not responsible for services we do not control. The companies that process data for dply are listed on the <a href="/compliance#subprocessors" class="{{ $link }}">Security &amp; compliance</a> page.</p>
</div></section>

<section id="warranties" class="{{ $section }}"><div class="{{ $wrap }}">
    <h2 class="{{ $h2 }}">15. Disclaimer of warranties</h2>
    <p class="{{ $p }} uppercase">To the fullest extent the law allows, dply is provided “as is” and “as available”, without warranties of any kind, express or implied, including warranties of merchantability, fitness for a particular purpose, title and non-infringement. We do not promise that dply will be uninterrupted, error-free or free of harmful components, or that your content will not be lost.</p>
</div></section>

<section id="liability" class="{{ $section }}"><div class="{{ $wrap }}">
    <h2 class="{{ $h2 }}">16. Limitation of liability</h2>
    <p class="{{ $p }} uppercase">To the fullest extent the law allows: (a) neither party is liable for indirect, incidental, special, consequential or punitive damages, or for lost profits, revenue, goodwill or data, even if told they were possible; and (b) each party’s total liability arising out of or relating to these terms is limited to the fees you paid dply in the 12 months before the event that gave rise to the claim.</p>
    <p class="{{ $p }}">These limits do not apply to your payment obligations, your indemnity obligations in section 17, or liability that cannot be limited by law.</p>
</div></section>

<section id="indemnity" class="{{ $section }}"><div class="{{ $wrap }}">
    <h2 class="{{ $h2 }}">17. Indemnity</h2>
    <p class="{{ $p }}">You will defend and indemnify {{ $entity }} and its members, employees and agents against third-party claims, and the resulting losses, damages and reasonable legal costs, arising from your content, your apps, or your breach of these terms or the Acceptable Use Policy. We will tell you promptly about the claim, let you control the defense, and give reasonable help at your cost. You may not settle a claim that imposes obligations on us without our written consent.</p>
</div></section>

<section id="law" class="{{ $section }}"><div class="{{ $wrap }}">
    <h2 class="{{ $h2 }}">18. Governing law</h2>
    <p class="{{ $p }}">The laws of the State of {{ $state }}, USA, govern these terms, without regard to conflict-of-law rules. The state and federal courts located in Maricopa County, {{ $state }}, have exclusive jurisdiction over any dispute arising out of or relating to these terms, and both parties consent to that venue. Either party may seek urgent injunctive relief in any competent court. If you are a consumer, this does not take away rights you have under the mandatory laws of the place you live.</p>
</div></section>

<section id="changes" class="{{ $section }}"><div class="{{ $wrap }}">
    <h2 class="{{ $h2 }}">19. Changes to these terms</h2>
    <p class="{{ $p }}">We may update these terms. The date at the top shows the current version. For material changes, we email account holders before they take effect, and we ask you to accept the new version the next time you sign in. If you continue to use dply after a change takes effect, you accept the updated terms. If you do not agree, stop using dply and close your account.</p>
</div></section>

<section id="misc" class="{{ $section }}"><div class="{{ $wrap }}">
    <h2 class="{{ $h2 }}">20. General</h2>
    <ul class="{{ $list }}">
        <li><span class="{{ $strong }}">Entire agreement.</span> These terms, the Acceptable Use Policy, the Data Processing Addendum and any order or plan terms you accept in dply are the whole agreement between us about dply, and replace any earlier agreement on the same subject.</li>
        <li><span class="{{ $strong }}">Assignment.</span> You may not transfer these terms without our written consent. We may transfer them to an affiliate or to a successor in a merger, acquisition or sale of the business.</li>
        <li><span class="{{ $strong }}">Severability.</span> If a court finds part of these terms unenforceable, the rest stays in effect, and the unenforceable part is enforced as far as allowed.</li>
        <li><span class="{{ $strong }}">No waiver.</span> Not enforcing a right is not a waiver of it.</li>
        <li><span class="{{ $strong }}">Notices.</span> We send notices to the email address on your account, or to organization owners. You send notices to <a href="mailto:{{ $supportEmail }}" class="{{ $link }}">{{ $supportEmail }}</a>. Email notices are received when sent.</li>
        <li><span class="{{ $strong }}">Force majeure.</span> Neither party is liable for delays or failures caused by events beyond its reasonable control, including failures of the infrastructure providers dply relies on.</li>
        <li><span class="{{ $strong }}">Independent parties.</span> We are independent contractors. These terms create no partnership, agency or employment relationship, and no third-party beneficiaries.</li>
    </ul>
</div></section>

@include('legal.partials.layout-end')
