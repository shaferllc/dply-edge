<?php

declare(strict_types=1);

namespace App\Support\Cloud;

use App\Models\ProviderCredential;
use Illuminate\Support\Facades\Http;

final class GcpAccessToken
{
    /**
     * @var array<string, array{token: string, expires_at: int}>
     */
    private static array $tokenCache = [];

    /**
     * @param  array<string, mixed>  $serviceAccount
     */
    public function __construct(
        private readonly ProviderCredential $credential,
        private readonly array $serviceAccount,
        private readonly string $projectId,
    ) {}

    public static function fromCredential(ProviderCredential $credential): self
    {
        $raw = $credential->credentials['service_account'] ?? null;
        $serviceAccount = self::normalizeServiceAccount($raw);

        $projectId = trim((string) ($credential->credentials['project_id'] ?? $serviceAccount['project_id'] ?? ''));
        if ($projectId === '') {
            throw new \RuntimeException('GCP service account is missing project_id.');
        }

        self::assertRequiredFields($serviceAccount);

        return new self($credential, $serviceAccount, $projectId);
    }

    /**
     * @return array<string, mixed>
     */
    public static function normalizeServiceAccount(mixed $serviceAccount): array
    {
        if (is_array($serviceAccount)) {
            return $serviceAccount;
        }

        if (! is_string($serviceAccount) || trim($serviceAccount) === '') {
            throw new \RuntimeException('GCP service account JSON is required.');
        }

        $decoded = json_decode($serviceAccount, true);
        if (! is_array($decoded)) {
            throw new \RuntimeException('GCP service account JSON is invalid.');
        }

        return $decoded;
    }

    public function projectId(): string
    {
        return $this->projectId;
    }

    /**
     * @return array<string, mixed>
     */
    public function serviceAccount(): array
    {
        return $this->serviceAccount;
    }

    /**
     * @param  array<int, string>  $scopes
     */
    public function token(array $scopes): string
    {
        $scopes = array_values(array_unique(array_filter(array_map('trim', $scopes))));
        if ($scopes === []) {
            throw new \InvalidArgumentException('At least one GCP OAuth scope is required.');
        }

        $cacheKey = sha1($this->projectId.'|'.implode(' ', $scopes).'|'.$this->credential->id);
        $cached = self::$tokenCache[$cacheKey] ?? null;
        if (is_array($cached) && ($cached['expires_at']) > (time() + 30)) {
            return (string) $cached['token'];
        }

        $tokenUri = (string) $this->serviceAccount['token_uri'];
        $assertion = $this->buildJwtAssertion($scopes);

        $response = Http::asForm()
            ->acceptJson()
            ->post($tokenUri, [
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion' => $assertion,
            ]);

        if (! $response->successful()) {
            $error = $response->json('error_description')
                ?? $response->json('error')
                ?? $response->body();
            throw new \RuntimeException('GCP token exchange failed: '.trim((string) $error));
        }

        $accessToken = (string) $response->json('access_token', '');
        if ($accessToken === '') {
            throw new \RuntimeException('GCP token exchange did not return access_token.');
        }

        $expiresIn = (int) $response->json('expires_in', 3600);
        self::$tokenCache[$cacheKey] = [
            'token' => $accessToken,
            'expires_at' => time() + max(60, $expiresIn),
        ];

        return $accessToken;
    }

    /**
     * @param  list<string>  $scopes
     */
    private function buildJwtAssertion(array $scopes): string
    {
        $header = ['alg' => 'RS256', 'typ' => 'JWT'];
        $issuedAt = time();
        $payload = [
            'iss' => (string) $this->serviceAccount['client_email'],
            'sub' => (string) $this->serviceAccount['client_email'],
            'aud' => (string) $this->serviceAccount['token_uri'],
            'scope' => implode(' ', $scopes),
            'iat' => $issuedAt,
            'exp' => $issuedAt + 3600,
        ];

        $segments = [
            self::base64UrlEncode(json_encode($header, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)),
            self::base64UrlEncode(json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)),
        ];

        $signingInput = implode('.', $segments);
        $privateKey = (string) $this->serviceAccount['private_key'];

        $signature = '';
        $ok = openssl_sign($signingInput, $signature, $privateKey, OPENSSL_ALGO_SHA256);
        if (! $ok) {
            throw new \RuntimeException('Unable to sign GCP service-account JWT assertion.');
        }

        $segments[] = self::base64UrlEncode($signature);

        return implode('.', $segments);
    }

    /**
     * @param  array<string, mixed>  $serviceAccount
     */
    private static function assertRequiredFields(array $serviceAccount): void
    {
        $required = ['type', 'private_key', 'client_email', 'token_uri'];

        foreach ($required as $key) {
            $value = $serviceAccount[$key] ?? null;
            if (! is_string($value) || trim($value) === '') {
                throw new \RuntimeException(sprintf('GCP service account is missing required field: %s.', $key));
            }
        }

        if (($serviceAccount['type'] ?? null) !== 'service_account') {
            throw new \RuntimeException('GCP credential must be a service account JSON key.');
        }
    }

    private static function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
