<?php

namespace App\Jobs;

use App\Models\ConsoleAction;
use App\Models\ErrorEvent;
use App\Models\Site;
use App\Models\SiteUptimeCheckResult;
use App\Models\SiteUptimeIncident;
use App\Models\SiteUptimeMonitor;
use App\Modules\Notifications\Services\NotificationPublisher;
use App\Services\ConsoleActions\ConsoleEmitter;
use App\Services\Sites\SiteUptimeCheckUrlResolver;
use App\Services\Sites\UptimeProbeWorkerResolver;
use App\Services\Status\MonitorOperationalState;
use Carbon\CarbonImmutable;
use App\Jobs\Concerns\WritesConsoleAction;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class RunSiteUptimeMonitorCheckJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable, WritesConsoleAction;

    public int $timeout = 25;

    private const CONNECT_TIMEOUT_SECONDS = 4;

    private const HTTP_TIMEOUT_SECONDS = 8;

    public function __construct(
        public string $siteUptimeMonitorId,
        public ?string $userId = null,
    ) {
        $this->onQueue(config('dply.queues.background'));
    }

    /** Auto-expire the unique lock so a lost/killed run can't wedge it forever. */
    public int $uniqueFor = 120;

    public function uniqueId(): string
    {
        return 'console-action:uptime_check:'.$this->siteUptimeMonitorId;
    }

    protected function consoleSubject(): Model
    {
        $monitor = SiteUptimeMonitor::query()->with('site')->findOrFail($this->siteUptimeMonitorId);

        return $monitor->site;
    }

    protected function consoleKind(): string
    {
        return 'uptime_check';
    }

    protected function triggeringUserId(): ?string
    {
        return $this->userId;
    }

    /**
     * Seed a queued console_actions row before dispatch so the page-top banner
     * appears instantly rather than after the worker picks up the job. Mirrors
     * the seed pattern used elsewhere (see Sites/Settings::seedQueuedConsoleAction).
     */
    public static function dispatchWithConsoleAction(
        Site $site,
        SiteUptimeMonitor $monitor,
        ?string $userId = null,
    ): void {
        if (! config('site_uptime.enabled', true)) {
            return;
        }

        ConsoleAction::query()
            ->where('subject_type', $site->getMorphClass())
            ->where('subject_id', $site->id)
            ->whereNull('dismissed_at')
            ->whereIn('status', [ConsoleAction::STATUS_COMPLETED, ConsoleAction::STATUS_FAILED])
            ->update(['dismissed_at' => now()]);

        ConsoleAction::query()->create([
            'subject_type' => $site->getMorphClass(),
            'subject_id' => $site->id,
            'kind' => 'uptime_check',
            'status' => ConsoleAction::STATUS_QUEUED,
            'user_id' => $userId,
            'output' => [
                'v' => (int) config('console_actions.current_version', 1),
                'lines' => [],
            ],
        ]);

        self::dispatch($monitor->id, $userId)->onQueue(self::queueForMonitor($monitor));
    }

    /**
     * Horizon queue this monitor's check runs on — the probe worker's queue
     * (regional egress), or the central `default` queue when it has none.
     */
    public static function queueForMonitor(SiteUptimeMonitor $monitor): string
    {
        return app(UptimeProbeWorkerResolver::class)->queueFor($monitor->probe_worker);
    }

    public function handle(SiteUptimeCheckUrlResolver $resolver, NotificationPublisher $notificationPublisher): void
    {
        if (! config('site_uptime.enabled', true)) {
            return;
        }

        $monitor = SiteUptimeMonitor::query()->with('site')->find($this->siteUptimeMonitorId);
        if (! $monitor) {
            return;
        }

        $site = $monitor->site;
        if (! $site instanceof Site) {
            return;
        }
        $previousState = $this->previousState($monitor);

        $emit = $this->beginConsoleAction();
        $regionLabel = (string) (config('site_uptime.probe_regions.'.$monitor->probe_region) ?? $monitor->probe_region);
        $kindLabel = $monitor->checkTypeLabel();
        $emit->step('uptime', sprintf('%s · %s · %s', $monitor->label, $kindLabel, $regionLabel));

        $base = $resolver->resolveBaseUrl($site);
        if ($base === null) {
            $outcome = $this->unresolvableOutcome();
            $emit->error($outcome['error']);
        } elseif ($monitor->isSslCheck()) {
            $outcome = $this->runSslCheck($monitor, $base, $emit);
        } else {
            $outcome = $this->runHttpCheck($monitor, $resolver->resolveFullUrl($site, $monitor) ?? $base, $emit);
        }

        $this->persistOutcome($monitor, $outcome);
        $this->recordCheckResult($monitor, $outcome);

        if ($outcome['state'] === MonitorOperationalState::OUTAGE) {
            $emit->error($this->summaryLine($outcome));
            $this->failConsoleAction($outcome['error'] ?? __('failed'));
        } else {
            $emit->success($this->summaryLine($outcome));
            $this->completeConsoleAction();
            // Recovered from an outage: close the single folded uptime ErrorEvent
            // so the stream reflects current reality and a future outage opens a
            // fresh one. Gate on the edge to avoid an UPDATE on every healthy probe.
            if ($previousState === MonitorOperationalState::OUTAGE) {
                $this->resolveOpenUptimeErrors($site);
            }
        }

        $this->syncIncident($monitor, $site, $previousState, $outcome);
        $this->maybePublishTransition($monitor->fresh(), $site, $previousState, $outcome, $notificationPublisher);
    }

    /**
     * Prior operational state, derived from last_state with a fallback to the
     * legacy last_ok column for rows checked before last_state existed.
     */
    private function previousState(SiteUptimeMonitor $monitor): string
    {
        if ($monitor->last_state !== null && $monitor->last_state !== '') {
            return $monitor->last_state;
        }

        return match ($monitor->last_ok) {
            true => MonitorOperationalState::OPERATIONAL,
            false => MonitorOperationalState::OUTAGE,
            // last_ok is nullable — a monitor that has never been probed has no
            // prior signal, so it reads UNKNOWN (no spurious recovered/outage
            // transition is fired off the first check).
            default => MonitorOperationalState::UNKNOWN,
        };
    }

    /**
     * @return array{state: string, ok: bool, http_status: ?int, latency_ms: int, error: ?string, checked_url: ?string, meta: array<string, mixed>, ssl_should_warn: bool}
     */
    private function unresolvableOutcome(): array
    {
        return [
            'state' => MonitorOperationalState::OUTAGE,
            'ok' => false,
            'http_status' => null,
            'latency_ms' => 0,
            'error' => __('No public URL could be resolved for this site.'),
            'checked_url' => null,
            'meta' => [],
            'ssl_should_warn' => false,
        ];
    }

    /**
     * HTTP GET with optional expected-status, keyword and response-time
     * assertions. The URL scheme is pinned to the monitor's check type
     * (http vs https) — no cross-scheme fallback. A failing status/keyword
     * reads OUTAGE; a pass that is slower than the threshold reads DEGRADED.
     *
     * @return array{state: string, ok: bool, http_status: ?int, latency_ms: int, error: ?string, checked_url: ?string, meta: array<string, mixed>, ssl_should_warn: bool}
     */
    private function runHttpCheck(SiteUptimeMonitor $monitor, string $url, ConsoleEmitter $emit): array
    {
        $attempts = [$this->constrainUrlToCheckType($monitor, $url)];

        $expected = $monitor->expectedStatus();
        $keyword = $monitor->keywordAssertion();
        $threshold = $monitor->responseThresholdMs();

        $status = null;
        $latency = 0;
        $error = null;
        $pass = false;
        $checkedUrl = $url;

        foreach ($attempts as $attemptUrl) {
            $emit->info('GET '.$attemptUrl);
            $checkedUrl = $attemptUrl;
            $started = microtime(true);
            try {
                $response = Http::timeout(self::HTTP_TIMEOUT_SECONDS)
                    ->connectTimeout(self::CONNECT_TIMEOUT_SECONDS)
                    ->get($attemptUrl);
                $latency = $this->elapsedMs($started);
                $status = $response->status();

                $statusOk = $expected !== null ? $status === $expected : $response->successful();
                if (! $statusOk) {
                    $error = $expected !== null
                        ? __('Expected HTTP :want, got :got', ['want' => $expected, 'got' => $status])
                        : 'HTTP '.$status;
                    $emit->warn($error);

                    continue;
                }

                if ($keyword !== null) {
                    $body = (string) $response->body();
                    $contains = str_contains($body, $keyword);
                    $bodyOk = $monitor->keywordMatchMode() === SiteUptimeMonitor::MATCH_NOT_CONTAIN
                        ? ! $contains
                        : $contains;
                    if (! $bodyOk) {
                        $error = $monitor->keywordMatchMode() === SiteUptimeMonitor::MATCH_NOT_CONTAIN
                            ? __('Forbidden text ":word" present in response', ['word' => Str::limit($keyword, 40)])
                            : __('Expected text ":word" missing from response', ['word' => Str::limit($keyword, 40)]);
                        $emit->warn($error);
                        // Server answered but content is wrong — that's an outage,
                        // and an HTTP fallback won't fix content. Stop here.
                        $pass = false;
                        break;
                    }
                }

                $pass = true;
                $error = null;
                $emit->success('HTTP '.$status);
                break;
            } catch (\Throwable $e) {
                $latency = $this->elapsedMs($started);
                $error = $e->getMessage();
                $emit->warn(Str::limit($e->getMessage(), 200, ''));
            }
        }

        if (! $pass) {
            return [
                'state' => MonitorOperationalState::OUTAGE,
                'ok' => false,
                'http_status' => $status,
                'latency_ms' => $latency,
                'error' => $error,
                'checked_url' => $checkedUrl,
                'meta' => [],
                'ssl_should_warn' => false,
            ];
        }

        $degraded = $threshold !== null && $latency > $threshold;
        if ($degraded) {
            $emit->warn(__('Slow · :ms ms over :threshold ms threshold', ['ms' => $latency, 'threshold' => $threshold]));
        }

        return [
            'state' => $degraded ? MonitorOperationalState::DEGRADED : MonitorOperationalState::OPERATIONAL,
            'ok' => true,
            'http_status' => $status,
            'latency_ms' => $latency,
            'error' => $degraded ? __('Response time :ms ms exceeded :threshold ms', ['ms' => $latency, 'threshold' => $threshold]) : null,
            'checked_url' => $checkedUrl,
            'meta' => $threshold !== null ? ['response_threshold_ms' => $threshold] : [],
            'ssl_should_warn' => false,
        ];
    }

    /**
     * TLS handshake that reads the peer certificate's expiry. An expired cert or
     * failed handshake is an OUTAGE; a valid cert within the warn window stays
     * OPERATIONAL but flags ssl_should_warn so the daily check can alert once on
     * crossing into the window.
     *
     * @return array{state: string, ok: bool, http_status: ?int, latency_ms: int, error: ?string, checked_url: ?string, meta: array<string, mixed>, ssl_should_warn: bool}
     */
    private function runSslCheck(SiteUptimeMonitor $monitor, string $base, ConsoleEmitter $emit): array
    {
        $host = parse_url($base, PHP_URL_HOST) ?: $base;
        $emit->info(__('TLS handshake :host:443', ['host' => $host]));

        $started = microtime(true);
        $context = stream_context_create([
            'ssl' => [
                'capture_peer_cert' => true,
                'verify_peer' => true,
                'verify_peer_name' => true,
                'SNI_enabled' => true,
                'peer_name' => $host,
            ],
        ]);

        $errno = 0;
        $errstr = '';
        $client = @stream_socket_client(
            'ssl://'.$host.':443',
            $errno,
            $errstr,
            self::CONNECT_TIMEOUT_SECONDS,
            STREAM_CLIENT_CONNECT,
            $context,
        );
        $latency = $this->elapsedMs($started);

        if ($client === false) {
            $reason = $errstr !== '' ? $errstr : __('TLS handshake failed');

            return [
                'state' => MonitorOperationalState::OUTAGE,
                'ok' => false,
                'http_status' => null,
                'latency_ms' => $latency,
                'error' => Str::limit($reason, 500, ''),
                'checked_url' => 'https://'.$host,
                'meta' => [],
                'ssl_should_warn' => false,
            ];
        }

        $params = stream_context_get_params($client);
        fclose($client);
        $cert = $params['options']['ssl']['peer_certificate'] ?? null;
        $parsed = $cert !== null ? openssl_x509_parse($cert) : false;

        if (! is_array($parsed) || ! isset($parsed['validTo_time_t'])) {
            return [
                'state' => MonitorOperationalState::OUTAGE,
                'ok' => false,
                'http_status' => null,
                'latency_ms' => $latency,
                'error' => __('Could not read the TLS certificate.'),
                'checked_url' => 'https://'.$host,
                'meta' => [],
                'ssl_should_warn' => false,
            ];
        }

        $expiresAt = CarbonImmutable::createFromTimestamp((int) $parsed['validTo_time_t']);
        $daysRemaining = (int) floor(now()->diffInDays($expiresAt, false));
        $meta = [
            'ssl_expires_at' => $expiresAt->toIso8601String(),
            'ssl_days_remaining' => $daysRemaining,
        ];

        if ($daysRemaining < 0) {
            return [
                'state' => MonitorOperationalState::OUTAGE,
                'ok' => false,
                'http_status' => null,
                'latency_ms' => $latency,
                'error' => __('Certificate expired :days day(s) ago', ['days' => abs($daysRemaining)]),
                'checked_url' => 'https://'.$host,
                'meta' => $meta,
                'ssl_should_warn' => false,
            ];
        }

        $warnDays = $monitor->sslWarnDays();
        $withinWindow = $daysRemaining <= $warnDays;
        // Alert once on crossing into the warn window: warn only when the prior
        // reading was outside it (or unknown). A renewed cert that drops back in
        // later re-arms this naturally.
        $previousDays = $monitor->last_meta['ssl_days_remaining'] ?? null;
        $wasWithin = is_numeric($previousDays) && (int) $previousDays <= $warnDays && (int) $previousDays >= 0;
        $shouldWarn = $withinWindow && ! $wasWithin;

        if ($withinWindow) {
            $emit->warn(__('Certificate expires in :days day(s)', ['days' => $daysRemaining]));
        } else {
            $emit->success(__('Certificate valid · :days day(s) left', ['days' => $daysRemaining]));
        }

        return [
            'state' => MonitorOperationalState::OPERATIONAL,
            'ok' => true,
            'http_status' => null,
            'latency_ms' => $latency,
            'error' => null,
            'checked_url' => 'https://'.$host,
            'meta' => $meta,
            'ssl_should_warn' => $shouldWarn,
        ];
    }

    /**
     * Pin the request to the monitor's scheme so an HTTPS check never
     * silently succeeds over HTTP, and vice versa.
     */
    private function constrainUrlToCheckType(SiteUptimeMonitor $monitor, string $url): string
    {
        $scheme = match ($monitor->check_type) {
            SiteUptimeMonitor::CHECK_HTTP => 'http',
            SiteUptimeMonitor::CHECK_HTTPS => 'https',
            default => null,
        };
        if ($scheme === null) {
            return $url;
        }

        if (preg_match('#^https?://#i', $url) === 1) {
            return (string) preg_replace('#^https?://#i', $scheme.'://', $url, 1);
        }

        return $scheme.'://'.$url;
    }

    private function elapsedMs(float $started): int
    {
        $ms = (int) round((microtime(true) - $started) * 1000);

        return max(0, $ms);
    }

    /**
     * @param  array{state: string, ok: bool, http_status: ?int, latency_ms: int, error: ?string, checked_url: ?string, meta: array<string, mixed>, ssl_should_warn: bool}  $outcome
     */
    private function persistOutcome(SiteUptimeMonitor $monitor, array $outcome): void
    {
        $monitor->update([
            'last_checked_at' => now(),
            'last_ok' => $outcome['ok'],
            'last_state' => $outcome['state'],
            'last_http_status' => $outcome['http_status'],
            'last_latency_ms' => $outcome['latency_ms'],
            'last_error' => $outcome['error'] !== null && $outcome['error'] !== '' ? Str::limit($outcome['error'], 500, '') : null,
            'last_meta' => $outcome['meta'] !== [] ? $outcome['meta'] : null,
        ]);
    }

    /**
     * @param  array{state: string, ok: bool, http_status: ?int, latency_ms: int, error: ?string, checked_url: ?string, meta: array<string, mixed>, ssl_should_warn: bool}  $outcome
     */
    private function recordCheckResult(SiteUptimeMonitor $monitor, array $outcome): void
    {
        SiteUptimeCheckResult::query()->create([
            'site_uptime_monitor_id' => $monitor->id,
            'checked_at' => now(),
            'state' => $outcome['state'],
            'http_status' => $outcome['http_status'],
            'latency_ms' => $outcome['latency_ms'],
            'error' => $outcome['error'] !== null && $outcome['error'] !== '' ? Str::limit($outcome['error'], 500, '') : null,
            'probe_worker' => $monitor->probe_worker,
        ]);
    }

    /**
     * @param  array{state: string, ok: bool, http_status: ?int, latency_ms: int, error: ?string, checked_url: ?string, meta: array<string, mixed>, ssl_should_warn: bool}  $outcome
     */
    private function summaryLine(array $outcome): string
    {
        $status = $outcome['http_status'];
        $latency = $outcome['latency_ms'];

        return match ($outcome['state']) {
            MonitorOperationalState::OPERATIONAL => $status !== null
                ? sprintf('OK · HTTP %d · %d ms', $status, $latency)
                : sprintf('OK · %d ms', $latency),
            MonitorOperationalState::DEGRADED => sprintf('Degraded · %d ms', $latency),
            default => $status !== null
                ? sprintf('Failed · HTTP %d · %d ms', $status, $latency)
                : sprintf('Failed · %s · %d ms', $outcome['error'] ?? 'no response', $latency),
        };
    }

    /**
     * Open / escalate / resolve the monitor's incident at the state edge. An
     * outage or degraded span opens (or escalates) one incident; recovery to
     * operational closes the open one. SSL-expiry warnings are not incidents
     * (the outcome state stays OPERATIONAL), so they never reach here.
     *
     * @param  array{state: string, ok: bool, http_status: ?int, latency_ms: int, error: ?string, checked_url: ?string, meta: array<string, mixed>, ssl_should_warn: bool}  $outcome
     */
    private function syncIncident(SiteUptimeMonitor $monitor, Site $site, string $previousState, array $outcome): void
    {
        $state = $outcome['state'];
        $ongoing = $monitor->incidents()->whereNull('resolved_at')->latest('started_at')->first();

        if ($state === MonitorOperationalState::OPERATIONAL) {
            if ($ongoing !== null) {
                $ongoing->update(['resolved_at' => now()]);
            }

            return;
        }

        // Non-operational (outage or degraded).
        $severity = $state === MonitorOperationalState::OUTAGE
            ? SiteUptimeIncident::SEVERITY_OUTAGE
            : SiteUptimeIncident::SEVERITY_DEGRADED;

        if ($ongoing === null) {
            SiteUptimeIncident::query()->create([
                'site_uptime_monitor_id' => $monitor->id,
                'site_id' => $site->id,
                'severity' => $severity,
                'cause' => $outcome['error'] !== null ? Str::limit($outcome['error'], 500, '') : null,
                'started_at' => now(),
            ]);

            return;
        }

        // Escalate a degraded incident to outage; never downgrade an outage.
        if ($severity === SiteUptimeIncident::SEVERITY_OUTAGE && $ongoing->severity !== SiteUptimeIncident::SEVERITY_OUTAGE) {
            $ongoing->update([
                'severity' => SiteUptimeIncident::SEVERITY_OUTAGE,
                'cause' => $outcome['error'] !== null ? Str::limit($outcome['error'], 500, '') : $ongoing->cause,
            ]);
        }
    }

    /**
     * Dismiss any open uptime ErrorEvents for the site once it's back up. The
     * syncer keeps at most one un-dismissed uptime event per site (it folds
     * repeats), so this clears the streak's single row; the next outage records
     * a fresh one. dismissed_by stays null to mark it as system-resolved.
     */
    private function resolveOpenUptimeErrors(Site $site): void
    {
        ErrorEvent::query()
            ->where('category', 'uptime_check')
            ->where('site_id', $site->id)
            ->whereNull('dismissed_at')
            ->update(['dismissed_at' => now()]);
    }

    /**
     * Publish the split notification events at the state edge:
     *  - site.uptime.down      → down (worsened to outage) / recovered (back to operational)
     *  - site.uptime.degraded  → entered the degraded state
     *  - site.ssl.expiring     → cert crossed into the warn window (operational)
     *
     * @param  array{state: string, ok: bool, http_status: ?int, latency_ms: int, error: ?string, checked_url: ?string, meta: array<string, mixed>, ssl_should_warn: bool}  $outcome
     */
    private function maybePublishTransition(
        SiteUptimeMonitor $monitor,
        Site $site,
        string $previousState,
        array $outcome,
        NotificationPublisher $notificationPublisher,
    ): void {
        if (! config('site_uptime.notify_on_transitions', true)) {
            return;
        }

        $site = $site->fresh(['server']);
        if (! $site?->server) {
            return;
        }

        $state = $outcome['state'];
        $label = (string) $monitor->label;
        $checkedUrl = $outcome['checked_url'];
        $url = route('sites.show', [$site->server, $site, 'edge-alerts'], absolute: true);

        $baseMeta = [
            'site_id' => $site->id,
            'site_uptime_monitor_id' => $monitor->id,
            'monitor_label' => $label,
            'last_http_status' => $outcome['http_status'],
            'last_latency_ms' => $outcome['latency_ms'],
            'checked_url' => $checkedUrl,
        ];

        // SSL expiry warning — independent of up/down state.
        if ($outcome['ssl_should_warn']) {
            $days = $outcome['meta']['ssl_days_remaining'] ?? null;
            $notificationPublisher->publish(
                eventKey: 'site.ssl.expiring',
                subject: $site,
                title: '['.config('app.name').'] '.$site->name.' — '.__('TLS certificate expiring'),
                body: is_numeric($days) ? __(':label: certificate expires in :days day(s).', ['label' => $label, 'days' => (int) $days]) : null,
                url: $url,
                metadata: $baseMeta + ['state' => 'ssl_expiring', 'ssl_days_remaining' => $days],
            );
        }

        $wentDown = $state === MonitorOperationalState::OUTAGE && $previousState !== MonitorOperationalState::OUTAGE;
        $recovered = $state === MonitorOperationalState::OPERATIONAL
            && in_array($previousState, [MonitorOperationalState::OUTAGE, MonitorOperationalState::DEGRADED], true);
        $becameDegraded = $state === MonitorOperationalState::DEGRADED && $previousState !== MonitorOperationalState::DEGRADED;

        if ($wentDown || $recovered) {
            $notificationPublisher->publish(
                eventKey: 'site.uptime.down',
                subject: $site,
                title: '['.config('app.name').'] '.$site->name.' — '.$label.' '.($wentDown ? __('down') : __('recovered')),
                body: $checkedUrl !== null && $checkedUrl !== '' ? 'URL: '.$checkedUrl : null,
                url: $url,
                metadata: $baseMeta + ['state' => $wentDown ? 'down' : 'recovered'],
            );

            return;
        }

        if ($becameDegraded) {
            $notificationPublisher->publish(
                eventKey: 'site.uptime.degraded',
                subject: $site,
                title: '['.config('app.name').'] '.$site->name.' — '.$label.' '.__('degraded'),
                body: $outcome['error'] !== null ? $outcome['error'] : ($checkedUrl !== null ? 'URL: '.$checkedUrl : null),
                url: $url,
                metadata: $baseMeta + ['state' => 'degraded'],
            );
        }
    }
}
