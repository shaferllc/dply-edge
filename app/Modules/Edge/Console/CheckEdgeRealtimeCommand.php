<?php

declare(strict_types=1);

namespace App\Modules\Edge\Console;

use App\Modules\Edge\Services\Realtime\EdgeRealtimeMonitor;
use App\Modules\Notifications\Services\NotificationPublisher;
use App\Support\Admin\PlatformAdmins;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Synthetic check of the customer realtime relay, every minute. After
 * FAILS_BEFORE_ALERT failures in a row, one alert to the platform admins
 * (PLATFORM_ADMIN_EMAILS) and the log; one recovery notice when it passes.
 *
 *   php artisan dply:edge:check-realtime          # scheduled: record + alert
 *   php artisan dply:edge:check-realtime --once   # print the result only
 */
class CheckEdgeRealtimeCommand extends Command
{
    public const FAILS_BEFORE_ALERT = 2;

    /** Last result: ok, latency_ms, error, grace, at. */
    public const LAST_KEY = 'edge:realtime:monitor:last';

    public const FAILS_KEY = 'edge:realtime:monitor:fails';

    public const ALERTED_KEY = 'edge:realtime:monitor:alerted';

    protected $signature = 'dply:edge:check-realtime {--once : Run the round trip and print the result without recording or alerting}';

    protected $description = 'Round-trip the customer realtime relay and alert operators when it breaks.';

    public function handle(EdgeRealtimeMonitor $monitor, NotificationPublisher $publisher): int
    {
        if (! EdgeRealtimeMonitor::enabled()) {
            $this->line('Realtime monitor off: EDGE_REALTIME_KV_NAMESPACE_ID is unset or EDGE_REALTIME_MONITOR_ENABLED=false.');

            return self::SUCCESS;
        }

        $result = $monitor->check();

        if ($this->option('once')) {
            $result['ok']
                ? $this->info('ok: round trip in '.$result['latency_ms'].'ms')
                : $this->error('failed after '.$result['latency_ms'].'ms: '.$result['error']);

            return $result['ok'] ? self::SUCCESS : self::FAILURE;
        }

        Cache::forever(self::LAST_KEY, $result + ['at' => now()->toIso8601String()]);

        if ($result['ok']) {
            Cache::forget(self::FAILS_KEY);
            if (Cache::pull(self::ALERTED_KEY)) {
                $this->notify($publisher, 'platform.realtime.recovered',
                    __('Realtime relay recovered'),
                    __('The round trip through :host passes again (:ms ms).', ['host' => config('edge.realtime.host'), 'ms' => $result['latency_ms']]),
                    $result);
            }

            return self::SUCCESS;
        }

        $this->warn((string) $result['error']);
        if ($result['grace']) {
            return self::SUCCESS; // the monitor's KV record was just written and may not have reached every edge yet
        }

        $fails = (int) Cache::get(self::FAILS_KEY, 0) + 1;
        Cache::forever(self::FAILS_KEY, $fails);
        if ($fails >= self::FAILS_BEFORE_ALERT && Cache::add(self::ALERTED_KEY, true)) {
            $sent = $this->notify($publisher, 'platform.realtime.down',
                __('Realtime relay is failing'),
                __(':count round trips in a row through :host failed. Latest: :error', ['count' => $fails, 'host' => config('edge.realtime.host'), 'error' => $result['error']]),
                $result + ['consecutive_failures' => $fails]);
            if (! $sent) {
                Cache::forget(self::ALERTED_KEY); // try again next run
            }
        }

        return self::SUCCESS;
    }

    /** @param  array<string, mixed>  $metadata */
    private function notify(NotificationPublisher $publisher, string $event, string $title, string $body, array $metadata): bool
    {
        $event === 'platform.realtime.down'
            ? Log::error($title.': '.$body, $metadata)
            : Log::info($title.': '.$body, $metadata);
        $this->line($title);

        $admins = PlatformAdmins::users();
        if ($admins->isEmpty()) {
            return true;
        }
        try {
            $publisher->publish(
                eventKey: $event,
                subject: null,
                title: $title,
                body: $body,
                metadata: $metadata,
                recipientUsers: $admins->all(),
            );

            return true;
        } catch (Throwable $e) {
            $this->warn($e->getMessage());

            return false;
        }
    }
}
