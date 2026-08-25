<?php

declare(strict_types=1);

namespace App\Support\Errors;

use App\Models\ErrorEvent;
use Closure;

/**
 * Maps an error {@see ErrorEvent::$category} to a handler that reconstructs and
 * re-dispatches the original operation. A row in the Errors view shows "Retry"
 * only when its category is registered here — there is no blind re-dispatch, so
 * every retryable path is an explicit, reviewed re-run of an existing job.
 *
 * To make a category retryable, add a handler that resolves the origin from the
 * error's source row and dispatches the same job the original flow used. The
 * re-dispatched job seeds its own console run (and, if it fails again, produces
 * a fresh ErrorEvent via the listeners) — so retries are self-tracking.
 */
class ErrorRetryRegistry
{
    /** @var array<string, Closure(ErrorEvent, ?string): bool>|null */
    private ?array $handlers = null;

    public function isRetryable(string $category): bool
    {
        return array_key_exists($category, $this->handlers());
    }

    /** Run the handler for this error. Returns false when not retryable or the origin is gone. */
    public function retry(ErrorEvent $event, ?string $userId = null): bool
    {
        $handler = $this->handlers()[$event->category] ?? null;
        if ($handler === null) {
            return false;
        }

        return $handler($event, $userId);
    }

    /**
     * No category is retryable today — the retryable paths left with the VM
     * platform. Add a handler that resolves the origin from the error's source
     * row and dispatches the same job the original flow used; the re-dispatched
     * job seeds its own console run, so retries stay self-tracking.
     *
     * @return array<string, Closure(ErrorEvent, ?string): bool>
     */
    private function handlers(): array
    {
        return $this->handlers ??= [];
    }
}
