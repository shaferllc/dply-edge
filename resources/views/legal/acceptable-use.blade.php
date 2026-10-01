@php
    $entity = config('legal.entity');
    $supportEmail = config('dply.support_email');
    $agent = config('legal.dmca_agent');

    $section = 'border-b border-edge-line scroll-mt-16';
    $wrap = 'mx-auto max-w-6xl px-6 py-12 lg:px-10';
    $h2 = 'text-2xl font-bold tracking-[-0.02em]';
    $h3 = 'mt-8 text-base font-semibold text-edge-text';
    $p = 'mt-4 max-w-3xl text-sm leading-6 text-edge-mute';
    $list = 'mt-4 max-w-3xl list-disc space-y-2 pl-5 text-sm leading-6 text-edge-mute';
    $olist = 'mt-4 max-w-3xl list-decimal space-y-2 pl-5 text-sm leading-6 text-edge-mute';
    $strong = 'font-semibold text-edge-text';
    $link = 'border-b border-edge-lime/50 pb-0.5 text-edge-text transition-colors hover:border-edge-lime hover:text-edge-lime';
@endphp

@include('legal.partials.layout-start', [
    'eyebrow' => __('Legal'),
    'title' => __('Acceptable Use Policy'),
    'intro' => __('dply runs many customers’ apps on shared infrastructure. This policy sets out what you may not do with it. It is part of the Terms of Service and applies to everyone who uses dply, and to every app and resource hosted on it.'),
    'toc' => [
        ['prohibited-content', 'Prohibited content'],
        ['prohibited-activity', 'Prohibited activity'],
        ['security-testing', 'Security testing'],
        ['public-files', 'Public files and share links'],
        ['enforcement', 'Enforcement'],
        ['copyright', 'Copyright (DMCA)'],
        ['reporting', 'Reporting abuse'],
    ],
])

<section id="prohibited-content" class="{{ $section }}"><div class="{{ $wrap }}">
    <h2 class="{{ $h2 }}">1. Prohibited content</h2>
    <p class="{{ $p }}">Do not host, store, send or link to:</p>
    <ul class="{{ $list }}">
        <li>Anything illegal where you, your users or dply are, or that promotes illegal activity.</li>
        <li>Child sexual abuse material, or any content that sexualizes minors. We report it to the National Center for Missing &amp; Exploited Children (NCMEC) and to law enforcement.</li>
        <li>Malware, viruses, ransomware, spyware, or other harmful code, including command-and-control servers.</li>
        <li>Phishing pages, or sites that impersonate another person, brand or service to deceive people.</li>
        <li>Content that infringes someone else’s copyright, trademark, privacy or other rights.</li>
        <li>Harassment, threats, incitement to violence, or content that promotes terrorism.</li>
        <li>Personal data about others that you have no right to publish, such as leaked credentials or doxxing.</li>
    </ul>
</div></section>

<section id="prohibited-activity" class="{{ $section }}"><div class="{{ $wrap }}">
    <h2 class="{{ $h2 }}">2. Prohibited activity</h2>
    <p class="{{ $p }}">Do not use dply to:</p>
    <ul class="{{ $list }}">
        <li>Send spam or unsolicited bulk messages, or host sites advertised that way.</li>
        <li>Mine cryptocurrency.</li>
        <li>Attack other systems: denial-of-service, flooding, or generating abusive load on dply or anyone else.</li>
        <li>Scan, probe or test the security of systems you do not own or have permission to test.</li>
        <li>Get around plan limits, trial caps, fair-use limits, billing, or the isolation between customers, including by opening multiple accounts or trials.</li>
        <li>Access, or try to access, another customer’s data, apps or resources.</li>
        <li>Resell or sublicense dply as a hosting service without our written permission. Building and hosting apps for your own clients is fine.</li>
        <li>Run proxies, VPNs or open relays that let others hide abusive traffic.</li>
        <li>Break the policies of the infrastructure dply runs on, including Cloudflare’s <a href="https://www.cloudflare.com/website-terms/" class="{{ $link }}" rel="noopener">terms and acceptable use policies</a>.</li>
    </ul>
</div></section>

<section id="security-testing" class="{{ $section }}"><div class="{{ $wrap }}">
    <h2 class="{{ $h2 }}">3. Security testing</h2>
    <p class="{{ $p }}">You may test the security of your own apps and your own dply accounts. When you do, don’t access, change or delete data that isn’t yours, don’t degrade the service for others (no denial-of-service or load testing against shared infrastructure), and stop and tell us if you reach someone else’s data. Report vulnerabilities in dply itself under our <a href="/compliance#disclosure" class="{{ $link }}">responsible disclosure policy</a>.</p>
</div></section>

<section id="public-files" class="{{ $section }}"><div class="{{ $wrap }}">
    <h2 class="{{ $h2 }}">4. Public files and share links</h2>
    <p class="{{ $p }}">Files you serve publicly, such as a bucket served on a public path, and anything reachable through a share link, access key or token you create, are your responsibility under this policy, the same as content on your site. Do not use them to distribute content this policy prohibits. Revoke links and keys you no longer need.</p>
</div></section>

<section id="enforcement" class="{{ $section }}"><div class="{{ $wrap }}">
    <h2 class="{{ $h2 }}">5. Enforcement</h2>
    <p class="{{ $p }}">If we find or are told about a violation, we may, depending on how serious it is:</p>
    <ul class="{{ $list }}">
        <li>remove or disable the content, site, app or resource;</li>
        <li>suspend the organization or account;</li>
        <li>terminate the account, for serious or repeated violations;</li>
        <li>report illegal activity to the authorities.</li>
    </ul>
    <p class="{{ $p }}">We tell the owner what was reported and what we did, unless doing so would put someone at risk, hamper an investigation, or break the law. If you think we made a mistake, reply to the email you received.</p>
</div></section>

<section id="copyright" class="{{ $section }}"><div class="{{ $wrap }}">
    <h2 class="{{ $h2 }}">6. Copyright (DMCA)</h2>
    <p class="{{ $p }}">We respond to copyright notices under the US Digital Millennium Copyright Act, 17 U.S.C. §512.</p>

    <h3 class="{{ $h3 }}">Sending a notice</h3>
    <p class="{{ $p }}">Under §512(c)(3), your notice must include:</p>
    <ol class="{{ $olist }}">
        <li>Your physical or electronic signature, as the copyright owner or a person authorized to act for the owner.</li>
        <li>Identification of the copyrighted work you say is infringed (or a representative list, if several works are covered by one notice).</li>
        <li>Identification of the infringing material and enough information for us to find it, such as the full URL.</li>
        <li>Information reasonably sufficient for us to contact you, such as your address, telephone number and email address.</li>
        <li>A statement that you have a good faith belief that the use is not authorized by the copyright owner, its agent or the law.</li>
        <li>A statement that the information in the notice is accurate and, under penalty of perjury, that you are the owner or authorized to act for the owner.</li>
    </ol>

    <h3 class="{{ $h3 }}">Sending a counter-notice</h3>
    <p class="{{ $p }}">If your content was removed and you believe that was a mistake or misidentification, send a counter-notice. Under §512(g)(3), it must include:</p>
    <ol class="{{ $olist }}">
        <li>Your physical or electronic signature.</li>
        <li>Identification of the material that was removed and where it appeared before removal.</li>
        <li>A statement under penalty of perjury that you have a good faith belief the material was removed or disabled as a result of mistake or misidentification.</li>
        <li>Your name, address and telephone number, and a statement that you consent to the jurisdiction of the federal district court for your address (or, if you are outside the US, any judicial district in which {{ $entity }} may be found), and that you will accept service of process from the person who sent the original notice or their agent.</li>
    </ol>
    <p class="{{ $p }}">We forward the counter-notice to the person who sent the notice. Unless they tell us within 10 to 14 business days that they have filed a court action to restrain the activity, we may restore the material.</p>

    <h3 class="{{ $h3 }}">Designated agent</h3>
    <p class="{{ $p }}">
        <span class="{{ $strong }}">{{ $agent['name'] }}</span><br>
        {{ $entity }}<br>
        @if (filled($agent['address'] ?? null))
            {{ $agent['address'] }}<br>
        @endif
        <a href="mailto:{{ $agent['email'] }}" class="{{ $link }}">{{ $agent['email'] }}</a>
    </p>
    <p class="{{ $p }}">Sending a false notice or counter-notice can make you liable for damages under §512(f).</p>

    <h3 class="{{ $h3 }}">Repeat infringers</h3>
    <p class="{{ $p }}">We terminate, in appropriate circumstances, the accounts and organizations of customers who are the subject of repeated valid infringement notices.</p>
</div></section>

<section id="reporting" class="{{ $section }}"><div class="{{ $wrap }}">
    <h2 class="{{ $h2 }}">7. Reporting abuse</h2>
    <p class="{{ $p }}">To report a site on dply for phishing, malware, spam or other abuse, email <a href="mailto:{{ $supportEmail }}" class="{{ $link }}">{{ $supportEmail }}</a> with “Abuse” in the subject line. <a href="/docs/abuse" class="{{ $link }}">Report abuse</a> explains what to include and what happens next.</p>
</div></section>

@include('legal.partials.layout-end')
