<?php

declare(strict_types=1);

namespace App\Modules\Edge\Console;

use App\Modules\Edge\Support\EdgeBuilderHeartbeat;
use App\Modules\Notifications\Services\NotificationPublisher;
use App\Support\Admin\PlatformAdmins;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Every minute on the control plane: if no builder has passed
 * `dply:runtime:check` for five minutes, zero builders are alive and every
 * customer build is queuing. One alert to the platform admins, one recovery
 * notice. Same shape as dply:edge:check-realtime.
 */
class CheckEdgeBuildersCommand extends Command
{
    public const ALERTED_KEY = 'dply:builders:alerted';

    protected $signature = 'dply:edge:check-builders';

    protected $description = 'Alert platform admins when no build server has checked in for 5 minutes.';

    public function handle(NotificationPublisher $publisher): int
    {
        $last = EdgeBuilderHeartbeat::last();
        $seen = $last === null ? 'never' : $last['at']->diffForHumans().' ('.$last['host'].')';

        if (EdgeBuilderHeartbeat::alive()) {
            $this->line("Builders alive; last check-in {$seen}.");
            if (Cache::pull(self::ALERTED_KEY)) {
                $this->notify($publisher, 'platform.builders.recovered', __('Build servers are back'), __('A build server checked in :seen.', ['seen' => $seen]));
            }

            return self::SUCCESS;
        }

        $this->warn("No build server has checked in for 5 minutes (last: {$seen}).");
        if (Cache::add(self::ALERTED_KEY, true)) {
            $sent = $this->notify($publisher, 'platform.builders.down', __('No build servers are alive'),
                __('No build server has passed dply:runtime:check for 5 minutes (last check-in: :seen). Customer builds are queuing. See docs/self-hosting-runbook.md, "Builders".', ['seen' => $seen]));
            if (! $sent) {
                Cache::forget(self::ALERTED_KEY);
            }
        }

        return self::SUCCESS;
    }

    private function notify(NotificationPublisher $publisher, string $event, string $title, string $body): bool
    {
        $event === 'platform.builders.down' ? Log::error($title.': '.$body) : Log::info($title.': '.$body);
        $admins = PlatformAdmins::users();
        if ($admins->isEmpty()) {
            return true;
        }
        try {
            $publisher->publish(eventKey: $event, subject: null, title: $title, body: $body, metadata: [], recipientUsers: $admins->all());

            return true;
        } catch (Throwable $e) {
            $this->warn($e->getMessage());

            return false;
        }
    }
}
