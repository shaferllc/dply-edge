<?php

declare(strict_types=1);

namespace App\Modules\Providers\Cloudflare;

use App\Models\ProviderCredential;
use App\Models\Site;
use App\Support\Mail\Guided\GuidedMailGate;
use App\Support\Mail\Guided\GuidedMailProvider;
use App\Support\Mail\Guided\GuidedMailRecordStatus;
use App\Support\Mail\Guided\GuidedMailStep;
use App\Support\Mail\Guided\GuidedMailVerifyResult;
use App\Support\Mail\MailPlaceholderResolver;

/**
 * Guided + verified email setup on a customer's own domain through their own
 * Cloudflare account. dply holds a DNS-edit token (control plane only) to verify
 * the zone; a separate Email-Sending token — supplied by the user — is what
 * actually sends and is what ships to the deployed app's .env.
 *
 * The domain-onboarding step itself is dashboard-only on Cloudflare's side (no
 * REST endpoint), so {@see onboardingSteps()} is non-empty: dply guides, then
 * verifies with a real send.
 */
class CloudflareGuidedMailProvider implements GuidedMailProvider
{
    public function key(): string
    {
        return 'cloudflare';
    }

    public function gate(Site $site): GuidedMailGate
    {
        if ($this->dnsCredentialFor($site) === null) {
            return GuidedMailGate::ineligible(
                'Connect a Cloudflare DNS credential for this domain first — guided email setup verifies your records through it.'
            );
        }

        $domains = $site->domains
            ->pluck('hostname')
            ->map(static fn ($h): string => strtolower(trim((string) $h)))
            ->filter(static fn (string $h): bool => $h !== '')
            ->unique()
            ->values()
            ->all();

        if ($domains === []) {
            return GuidedMailGate::ineligible('Add a domain to this site before setting up email.');
        }

        return new GuidedMailGate(true, null, $domains);
    }

    public function onboardingSteps(string $domain): array
    {
        return [
            new GuidedMailStep(
                'Enable Email Sending in Cloudflare',
                "In the Cloudflare dashboard, open your account → Email → Email Service, and enable Email Sending. This step is dashboard-only — Cloudflare doesn't expose it over the API yet."
            ),
            new GuidedMailStep(
                'Add '.$domain.' as a sending domain',
                "Add {$domain} as a sending domain. Because the zone is on Cloudflare DNS, Cloudflare will auto-create the SPF, DKIM, and DMARC records for you — no manual DNS entry needed."
            ),
            new GuidedMailStep(
                'Create an Email Sending token',
                'Create an API token scoped to “Email Sending: Edit” (only that permission). Paste it below as the sending key — keep it separate from your DNS token.'
            ),
            new GuidedMailStep(
                'Verify',
                'Once the dashboard shows the domain as verified, run Verify below — dply sends a real test message through Cloudflare to confirm the whole chain works.'
            ),
        ];
    }

    public function pollRecords(Site $site, string $domain): GuidedMailRecordStatus
    {
        $domain = strtolower(trim($domain));
        $credential = $this->dnsCredentialFor($site);
        if ($credential === null) {
            return GuidedMailRecordStatus::unreadable('No Cloudflare DNS credential is connected for this site.');
        }

        try {
            $dns = new CloudflareDnsService($credential);
            $zone = $this->resolveZone($dns, $domain);
            if ($zone === null) {
                return GuidedMailRecordStatus::unreadable(
                    "Couldn't find {$domain} as a zone in the connected Cloudflare account."
                );
            }

            $spf = $this->anyTxtMatches($dns->listDnsRecords($zone, 'TXT', $domain), 'v=spf1');
            $dmarc = $this->anyTxtMatches($dns->listDnsRecords($zone, 'TXT', '_dmarc.'.$domain), 'v=dmarc1');
            $dkim = $this->anyRecordNameContains($dns->listDnsRecords($zone, 'TXT'), '_domainkey');

            return new GuidedMailRecordStatus($spf, $dkim, $dmarc);
        } catch (\Throwable $e) {
            return GuidedMailRecordStatus::unreadable($e->getMessage());
        }
    }

    public function verify(Site $site, string $domain, array $credentials, string $recipient): GuidedMailVerifyResult
    {
        $accountId = trim((string) ($credentials['account_id'] ?? ''));
        $sendingToken = trim((string) ($credentials['key'] ?? ''));
        if ($accountId === '' || $sendingToken === '') {
            return GuidedMailVerifyResult::fail('Enter your Cloudflare account ID and an Email Sending token first.');
        }

        $from = trim((string) ($credentials['from_address'] ?? ''));
        if ($from === '') {
            $from = 'hello@'.strtolower(trim($domain));
        }

        // Resolve ${APP_NAME}-style placeholders so Cloudflare registers a real
        // sender name rather than a literal "${APP_NAME}" — this is the control
        // plane, not the deployed app, so phpdotenv isn't in play.
        $fromName = MailPlaceholderResolver::resolve($site, trim((string) ($credentials['from_name'] ?? '')));

        try {
            $email = new CloudflareEmailService($sendingToken);
        } catch (\InvalidArgumentException $e) {
            return GuidedMailVerifyResult::fail($e->getMessage());
        }

        $error = $email->send(
            $accountId,
            $fromName !== '' ? ['address' => $from, 'name' => $fromName] : ['address' => $from],
            $recipient,
            'dply email verification',
            '<p>This is a verification message sent through Cloudflare Email Sending by dply. '
                .'If you received it, sending from <strong>'.e($domain).'</strong> is working.</p>',
            'This is a verification message sent through Cloudflare Email Sending by dply. '
                .'If you received it, sending from '.$domain.' is working.',
        );

        return $error === null ? GuidedMailVerifyResult::pass() : GuidedMailVerifyResult::fail($error);
    }

    /**
     * The Cloudflare DNS credential dply will verify through: the site's pinned
     * DNS credential when it's Cloudflare, else the org's first Cloudflare one.
     */
    private function dnsCredentialFor(Site $site): ?ProviderCredential
    {
        $pinned = $site->dnsProviderCredential;
        if ($pinned instanceof ProviderCredential && $pinned->provider === 'cloudflare') {
            return $pinned;
        }

        return ProviderCredential::query()
            ->where('organization_id', $site->organization_id)
            ->where('provider', 'cloudflare')
            ->orderBy('created_at')
            ->first();
    }

    /** Walk subdomain labels off $domain until a Cloudflare zone matches. */
    private function resolveZone(CloudflareDnsService $dns, string $domain): ?string
    {
        $labels = explode('.', $domain);
        while (count($labels) >= 2) {
            $candidate = implode('.', $labels);
            if ($dns->findZoneId($candidate) !== null) {
                return $candidate;
            }
            array_shift($labels);
        }

        return null;
    }

    /**
     * @param  list<array<string, mixed>>  $records
     */
    private function anyTxtMatches(array $records, string $needle): bool
    {
        foreach ($records as $record) {
            $content = strtolower((string) ($record['content'] ?? ''));
            if (str_contains($content, $needle)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<array<string, mixed>>  $records
     */
    private function anyRecordNameContains(array $records, string $needle): bool
    {
        foreach ($records as $record) {
            $name = strtolower((string) ($record['name'] ?? ''));
            if (str_contains($name, $needle)) {
                return true;
            }
        }

        return false;
    }
}
