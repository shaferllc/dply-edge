<?php

declare(strict_types=1);

namespace App\Modules\Providers\Cloudflare;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Cloudflare Email Service (Email Sending) API — the *sending* side, authed with
 * an `Email Sending: Edit` token (distinct from the DNS-edit token used by
 * {@see CloudflareDnsService}; we keep the two least-privilege so the
 * DNS-mutating token never lands on a customer box).
 *
 * Used from the control plane to prove a domain is actually set up to send,
 * before the customer's app is even deployed.
 *
 * @see https://developers.cloudflare.com/email-service/
 */
class CloudflareEmailService
{
    private const BASE = 'https://api.cloudflare.com/client/v4';

    private string $sendingToken;

    /** @param  non-empty-string  $sendingToken  An `Email Sending: Edit` API token. */
    public function __construct(string $sendingToken)
    {
        $token = trim($sendingToken);
        if ($token === '') {
            throw new \InvalidArgumentException('Cloudflare Email Sending token is required.');
        }
        $this->sendingToken = $token;
    }

    /**
     * Send one message via Cloudflare Email Sending. Returns null on success, or
     * a human-readable error string on failure (so callers can surface it as
     * copy without catching). Mirrors the payload of Laravel's CloudflareTransport.
     *
     * @param  array{address: string, name?: string}  $from
     */
    public function send(string $accountId, array $from, string $to, string $subject, string $html, ?string $text = null): ?string
    {
        $accountId = trim($accountId);
        if ($accountId === '') {
            return 'A Cloudflare account ID is required to send.';
        }

        $payload = array_filter([
            'from' => isset($from['name']) && $from['name'] !== ''
                ? ['name' => $from['name'], 'address' => $from['address']]
                : $from['address'],
            'to' => [$to],
            'subject' => $subject,
            'html' => $html,
            'text' => $text,
        ], static fn ($v): bool => $v !== null && $v !== '');

        try {
            $response = Http::withToken($this->sendingToken)
                ->acceptJson()
                ->asJson()
                ->post(self::BASE.'/accounts/'.$accountId.'/email/sending/send', $payload);
        } catch (\Throwable $e) {
            return 'Could not reach the Cloudflare Email API: '.$e->getMessage();
        }

        if ($response->successful()) {
            $json = $response->json();
            if (is_array($json) && array_key_exists('success', $json) && $json['success'] === false) {
                return $this->extractError($response) ?? 'Cloudflare rejected the send.';
            }

            return null;
        }

        return $this->extractError($response) ?? ('Cloudflare returned HTTP '.$response->status().'.');
    }

    private function extractError(Response $response): ?string
    {
        $message = $response->json('errors.0.message')
            ?? $response->json('message');

        return is_string($message) && $message !== '' ? $message : null;
    }
}
