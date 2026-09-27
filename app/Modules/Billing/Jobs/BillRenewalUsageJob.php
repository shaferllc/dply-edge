<?php

declare(strict_types=1);

namespace App\Modules\Billing\Jobs;

use App\Modules\Billing\Services\UsageInvoicer;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * A renewal's usage lines ({@see UsageInvoicer::onInvoiceCreated}), off the
 * webhook request: the period's last day is re-collected account-wide first.
 * The webhook already answered 200, so this job retries in Stripe's place.
 * Only the first attempt collects; retries go straight to billing, which is
 * idempotent and falls back to its own invoice once the draft is finalized.
 */
class BillRenewalUsageJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public int $timeout = 600;

    /** @var list<int> */
    public array $backoff = [60, 120, 300, 600];

    /** @param  array<string, mixed>  $invoice  the Stripe invoice from invoice.created */
    public function __construct(public array $invoice) {}

    public function handle(UsageInvoicer $invoicer): void
    {
        $invoicer->onInvoiceCreated($this->invoice, collect: $this->attempts() <= 1);
    }
}
