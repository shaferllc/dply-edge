<?php

declare(strict_types=1);

namespace App\Support\Cloud;

use RuntimeException;

final class OciRequestSigner
{
    public function __construct(
        private readonly string $tenancyOcid,
        private readonly string $userOcid,
        private readonly string $fingerprint,
        private readonly string $privateKey,
    ) {}

    /**
     * @param  array<string, mixed>  $headers
     * @return array<string, string>
     */
    public function sign(
        string $method,
        string $url,
        array $headers = [],
        ?string $body = null,
    ): array {
        $method = strtolower(trim($method));
        $host = (string) parse_url($url, PHP_URL_HOST);
        $path = (string) parse_url($url, PHP_URL_PATH);
        $query = (string) parse_url($url, PHP_URL_QUERY);
        $requestPath = $path !== '' ? $path : '/';
        if ($query !== '') {
            $requestPath .= '?'.$query;
        }

        $headers = $this->normalizeHeaders($headers);
        $headers['host'] = $headers['host'] ?? $host;
        $headers['date'] = $headers['date'] ?? gmdate('D, d M Y H:i:s').' GMT';

        $signedHeaderNames = ['(request-target)', 'host', 'date'];

        if ($body !== null) {
            $headers['content-type'] = $headers['content-type'] ?? 'application/json';
            $headers['content-length'] = (string) strlen($body);
            $headers['x-content-sha256'] = base64_encode(hash('sha256', $body, true));
            $signedHeaderNames = array_merge($signedHeaderNames, [
                'content-type',
                'content-length',
                'x-content-sha256',
            ]);
        }

        $signingLines = [
            '(request-target): '.$method.' '.$requestPath,
        ];
        foreach ($signedHeaderNames as $headerName) {
            if ($headerName === '(request-target)') {
                continue;
            }

            if (! array_key_exists($headerName, $headers)) {
                throw new RuntimeException('Missing OCI signing header: '.$headerName);
            }

            $signingLines[] = $headerName.': '.$headers[$headerName];
        }

        $signingString = implode("\n", $signingLines);
        $privateKeyResource = openssl_pkey_get_private($this->normalizedPrivateKey());
        if ($privateKeyResource === false) {
            throw new RuntimeException('Invalid OCI private key.');
        }

        $signature = '';
        $signed = openssl_sign($signingString, $signature, $privateKeyResource, OPENSSL_ALGO_SHA256);
        openssl_pkey_free($privateKeyResource);

        if (! $signed) {
            throw new RuntimeException('Failed to sign OCI request.');
        }

        $headers['authorization'] = sprintf(
            'Signature version="1",keyId="%s/%s/%s",algorithm="rsa-sha256",headers="%s",signature="%s"',
            $this->tenancyOcid,
            $this->userOcid,
            $this->fingerprint,
            implode(' ', $signedHeaderNames),
            base64_encode($signature),
        );

        return $this->toCanonicalHeaderCase($headers);
    }

    /**
     * @param  array<string, mixed>  $headers
     * @return array<string, string>
     */
    private function normalizeHeaders(array $headers): array
    {
        $normalized = [];

        foreach ($headers as $key => $value) {
            $normalized[strtolower(trim($key))] = $value;
        }

        return $normalized;
    }

    /**
     * @param  array<string, mixed>  $headers
     * @return array<string, string>
     */
    private function toCanonicalHeaderCase(array $headers): array
    {
        $out = [];

        foreach ($headers as $key => $value) {
            $canonical = match ($key) {
                'host' => 'Host',
                'date' => 'Date',
                'authorization' => 'Authorization',
                'content-type' => 'Content-Type',
                'content-length' => 'Content-Length',
                'x-content-sha256' => 'x-content-sha256',
                default => $key,
            };

            $out[$canonical] = $value;
        }

        return $out;
    }

    private function normalizedPrivateKey(): string
    {
        $key = trim($this->privateKey);
        if (str_contains($key, '\n')) {
            $key = str_replace('\n', "\n", $key);
        }

        return $key;
    }
}
