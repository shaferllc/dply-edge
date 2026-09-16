<?php

declare(strict_types=1);

namespace App\Modules\Providers\Cloudflare;

use App\Models\ProviderCredential;
use RuntimeException;

/**
 * Validates that a Cloudflare API token can manage Edge delivery infra
 * (Workers KV + R2). DNS-only tokens fail here.
 */
class CloudflareEdgeCredentialValidator
{
    public function validate(ProviderCredential $credential, ?string $accountId = null): string
    {
        if ($credential->provider !== 'cloudflare') {
            throw new RuntimeException('Edge validation requires a Cloudflare credential.');
        }

        $token = $credential->getApiToken();
        if (! is_string($token) || trim($token) === '') {
            throw new RuntimeException('Cloudflare API token is required.');
        }

        (new CloudflareDnsService($credential))->verifyToken();

        $accountId = trim((string) ($accountId ?? $this->resolveAccountId($credential)));
        if ($accountId === '') {
            $accountId = $this->resolveFirstAccountId($credential);
        }
        if ($accountId === '') {
            throw new RuntimeException('Could not resolve a Cloudflare account for this token.');
        }

        $client = new EdgeCloudflareClient($accountId, trim($token));
        $client->listKvNamespaces();
        $client->listR2Buckets();

        return $accountId;
    }

    private function resolveFirstAccountId(ProviderCredential $credential): string
    {
        $token = $credential->getApiToken();
        if (! is_string($token) || trim($token) === '') {
            return '';
        }

        $client = new EdgeCloudflareClient('', trim($token));
        foreach ($client->listAccounts() as $account) {
            $id = (($account['id'] ?? null));
            if (is_string($id) && $id !== '') {
                return $id;
            }
        }

        return '';
    }

    private function resolveAccountId(ProviderCredential $credential): string
    {
        $creds = $credential->credentials;
        $edge = is_array($creds['edge'] ?? null) ? $creds['edge'] : [];

        return trim((string) ($edge['account_id'] ?? ''));
    }
}
