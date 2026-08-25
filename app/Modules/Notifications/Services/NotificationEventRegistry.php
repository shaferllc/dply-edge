<?php

namespace App\Modules\Notifications\Services;


class NotificationEventRegistry
{
    /**
     * @return array{key: string, label: string, category: string|null, severity: string, supports_in_app: bool, supports_email: bool, supports_webhook: bool}
     */
    public function definition(string $eventKey): array
    {
        $configured = config('notification_events.categories', []);

        foreach ($configured as $categoryKey => $category) {
            $events = $category['events'] ?? [];
            if (! is_array($events) || ! array_key_exists($eventKey, $events)) {
                continue;
            }

            return [
                'key' => $eventKey,
                'label' => (string) $events[$eventKey],
                'category' => (string) $categoryKey,
                'severity' => $this->severityFor($eventKey),
                'supports_in_app' => true,
                // Import migration events surface action-required moments;
                // default them to email-on per the Q17 cadence (in-app +
                // email at action-required moments only). Uptime down/recovered,
                // degraded, and SSL-expiry are likewise action-required.
                'supports_email' => str_starts_with($eventKey, 'import.migration.')
                    || str_starts_with($eventKey, 'site.uptime.')
                    || str_starts_with($eventKey, 'server.logs.')
                    // A dead/expiring Git credential is action-required: every
                    // deploy using it fails at clone until it's replaced.
                    || str_starts_with($eventKey, 'account.git_token.')
                    || str_starts_with($eventKey, 'account.provider_credential.')
                    || $eventKey === 'site.ssl.expiring',
                'supports_webhook' => true,
            ];
        }


        return [
            'key' => $eventKey,
            'label' => $eventKey,
            'category' => null,
            'severity' => 'info',
            'supports_in_app' => true,
            'supports_email' => false,
            'supports_webhook' => true,
        ];
    }

    /**
     * Severity bumps to 'warning' for monitoring / alerts / failed-step / cutover-ready
     * / aborted events. Default 'info'.
     */
    protected function severityFor(string $eventKey): string
    {
        if (str_contains($eventKey, 'monitor')
            || str_starts_with($eventKey, 'account.git_token.')
            || str_starts_with($eventKey, 'account.provider_credential.')
            || str_contains($eventKey, 'uptime')
            || str_contains($eventKey, '.ssl.')
            || str_contains($eventKey, 'alerts')
            || str_contains($eventKey, '.logs.alert')
            || str_ends_with($eventKey, 'step_failed')
            || str_ends_with($eventKey, 'cutover_ready')
            || str_ends_with($eventKey, 'aborted')
            || str_ends_with($eventKey, 'paused_nudge')
            || str_ends_with($eventKey, 'container_launch.failed')
            || str_ends_with($eventKey, 'provision_failed')
            || str_ends_with($eventKey, 'scale_failed')
            || str_ends_with($eventKey, 'security_digest.critical_finding')
            || str_ends_with($eventKey, 'security_digest.warning_finding')
            || str_ends_with($eventKey, 'release_hygiene.critical_finding')
            || str_ends_with($eventKey, 'release_hygiene.warning_finding')
            || str_ends_with($eventKey, 'health.critical_finding')
            || str_ends_with($eventKey, 'health.warning_finding')
            || str_ends_with($eventKey, 'errors.deploy_failed')
            || str_ends_with($eventKey, 'errors.operation_failed')
        ) {
            return 'warning';
        }

        return 'info';
    }
}
