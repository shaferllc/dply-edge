<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Services;

use App\Models\NotificationChannel;
use App\Support\Http\UnsafeOutboundUrlException;
use Illuminate\Support\Facades\Log;

/**
 * Posts Adaptive Cards to a Microsoft Teams **Power Automate Workflows**
 * incoming webhook.
 *
 * The important thing this class knows is which kind of URL it was handed.
 * Microsoft retired Office 365 connectors between 18 and 22 May 2026; a
 * connector URL (`*.webhook.office.com`) now fails, and it fails in the worst
 * possible way — often still returning a 2xx while nothing is delivered. So the
 * URL is classified up front and a connector URL is refused with an explanation
 * rather than posted hopefully.
 */
class MicrosoftTeamsClient
{
    public const KIND_WORKFLOW = 'workflow';

    public const KIND_CONNECTOR = 'connector';

    public const KIND_UNKNOWN = 'unknown';

    /**
     * Hosts Power Automate serves Workflows webhooks from. The regional prefix
     * varies (`prod-27.westus.logic.azure.com`), so match on the suffix.
     */
    private const WORKFLOW_HOST_SUFFIXES = [
        '.logic.azure.com',
        '.logic.azure.us',
        '.logic.azure.cn',
    ];

    /** Retired connector hosts. Kept only so we can name the problem. */
    private const CONNECTOR_HOST_SUFFIXES = [
        '.webhook.office.com',
        '.office.com',
    ];

    /**
     * Classify a pasted webhook URL so the UI and the sender can both refuse a
     * retired connector URL with the same explanation.
     */
    public static function classifyUrl(?string $url): string
    {
        if (! is_string($url) || $url === '') {
            return self::KIND_UNKNOWN;
        }

        $host = parse_url($url, PHP_URL_HOST);
        if (! is_string($host) || $host === '') {
            return self::KIND_UNKNOWN;
        }

        $host = mb_strtolower($host);

        foreach (self::WORKFLOW_HOST_SUFFIXES as $suffix) {
            if (str_ends_with($host, $suffix)) {
                return self::KIND_WORKFLOW;
            }
        }

        foreach (self::CONNECTOR_HOST_SUFFIXES as $suffix) {
            if (str_ends_with($host, $suffix)) {
                return self::KIND_CONNECTOR;
            }
        }

        return self::KIND_UNKNOWN;
    }

    public static function isRetiredConnectorUrl(?string $url): bool
    {
        return self::classifyUrl($url) === self::KIND_CONNECTOR;
    }

    /**
     * Validation rule refusing a retired connector URL at save time.
     *
     * Deliberately only rejects the *known-retired* host — an unrecognised host
     * still saves, because self-hosters proxy these webhooks and we should not
     * become the reason a working setup can't be entered.
     *
     * @return \Closure(string, mixed, \Closure): void
     */
    public static function urlRule(): \Closure
    {
        return static function (string $attribute, mixed $value, \Closure $fail): void {
            if (is_string($value) && self::isRetiredConnectorUrl($value)) {
                $fail(self::describeError('retired_connector'));
            }
        };
    }

    /**
     * @param  array<string, mixed>  $payload  Built by MicrosoftTeamsMessage::toArray()
     * @return array{ok: bool, error: string, status: int}
     */
    public function send(string $webhookUrl, array $payload): array
    {
        if ($webhookUrl === '') {
            return ['ok' => false, 'error' => 'not_configured', 'status' => 0];
        }

        if (self::isRetiredConnectorUrl($webhookUrl)) {
            return ['ok' => false, 'error' => 'retired_connector', 'status' => 0];
        }

        try {
            $response = NotificationChannel::postToWebhookUrl($webhookUrl, $payload);
        } catch (UnsafeOutboundUrlException $e) {
            return ['ok' => false, 'error' => 'unsafe_url', 'status' => 0];
        } catch (\Throwable $e) {
            // Transport text is logged, not shown: the test button is no probe.
            Log::warning('microsoft_teams.post_failed', ['error' => $e->getMessage()]);

            return ['ok' => false, 'error' => 'unreachable', 'status' => 0];
        }

        if (! $response->successful()) {
            return [
                'ok' => false,
                'error' => self::extractError($response->body(), $response->status()),
                'status' => $response->status(),
            ];
        }

        return ['ok' => true, 'error' => '', 'status' => $response->status()];
    }

    public static function describeError(string $error): string
    {
        $lower = mb_strtolower($error);

        return match (true) {
            $error === '' => __('Microsoft Teams rejected the request.'),
            $error === 'not_configured' => __('No Teams workflow URL is set for this channel.'),
            $error === 'unsafe_url' => __('That URL is not allowed: it must point at a public address.'),
            $error === 'unreachable' => __('Could not reach the Teams workflow URL.'),
            $error === 'retired_connector' => __('That is an Office 365 connector URL, which Microsoft retired in May 2026. Create a Workflows webhook in Teams instead — see the setup steps on this form.'),
            $error === 'http_404' => __('That workflow no longer exists. It may have been deleted in Power Automate.'),
            $error === 'http_403' => __('Power Automate refused the request. Check the flow is turned on and its trigger is still "When a Teams webhook request is received".'),
            $error === 'http_429' => __('Power Automate is rate limiting us. Try again shortly.'),
            str_contains($lower, 'expired') || str_contains($lower, 'signature') => __('The workflow URL signature is invalid or expired. Copy a fresh URL from the flow trigger.'),
            str_contains($lower, 'flow') && str_contains($lower, 'disabled') => __('That Power Automate flow is turned off. Turn it on and try again.'),
            default => __('Microsoft Teams returned :error.', ['error' => $error]),
        };
    }

    private static function extractError(string $body, int $status): string
    {
        $decoded = json_decode($body, true);

        if (is_array($decoded)) {
            $message = $decoded['error']['message'] ?? $decoded['message'] ?? null;
            if (is_string($message) && $message !== '') {
                return $message;
            }
        }

        return 'http_'.$status;
    }
}
