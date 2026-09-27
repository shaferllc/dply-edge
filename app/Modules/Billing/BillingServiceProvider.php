<?php

declare(strict_types=1);

namespace App\Modules\Billing;

use App\Modules\Billing\Console\CompOrganizationCommand;
use App\Modules\Billing\Console\EnforceOrganizationBillingCommand;
use App\Modules\Billing\Console\ProvisionStripeBillingCommand;
use App\Modules\Billing\Console\SnapshotOrganizationBillingCommand;
use App\Modules\Billing\Console\SyncAllOrganizationBillingCommand;
use App\Modules\Billing\Listeners\BillUsageFromStripeWebhooks;
use App\Modules\Billing\Listeners\CaptureTrialCardFingerprint;
use App\Modules\Billing\Livewire\Analytics;
use App\Modules\Billing\Livewire\Invoices;
use App\Modules\Billing\Livewire\Show;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Laravel\Cashier\Events\WebhookHandled;
use Laravel\Cashier\Events\WebhookReceived;
use Livewire\Livewire;

/**
 * Billing module wiring (docs/adr/modular-monolith-structure.md).
 *
 * The revenue engine: 29 Services (subscriptions, Stripe sync, metering, usage cost
 * calculators incl. the serverless/log/vat billing services other modules depend on),
 * SyncOrganizationBillingJob, the sync/snapshot/provision commands, the billing
 * controllers + the org billing Livewire pages.
 *
 * Re-registers the commands (sync/snapshot scheduled via repointed refs) and the 3
 * full-page billing components. SiteBillingObserver stays wired in AppServiceProvider
 * (Site::observe) with a repointed reference; billing models (Subscription, etc.) stay
 * in app/Models. The edge-workspace Billing tab + MeterServerLogUsageCommand
 * (server-log metering) stay in their domains.
 */
class BillingServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                CompOrganizationCommand::class,
                EnforceOrganizationBillingCommand::class,
                ProvisionStripeBillingCommand::class,
                SnapshotOrganizationBillingCommand::class,
                SyncAllOrganizationBillingCommand::class,
            ]);
        }
    }

    public function boot(): void
    {
        Livewire::component('billing.show', Show::class);
        Livewire::component('billing.analytics', Analytics::class);
        Livewire::component('billing.invoices', Invoices::class);

        Event::listen(WebhookReceived::class, [BillUsageFromStripeWebhooks::class, 'received']);
        Event::listen(WebhookHandled::class, [BillUsageFromStripeWebhooks::class, 'handled']);
        Event::listen(WebhookReceived::class, CaptureTrialCardFingerprint::class);
    }
}
