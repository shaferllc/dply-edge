<?php

declare(strict_types=1);

namespace App\Modules\Billing;

use App\Modules\Billing\Console\ProvisionStripeBillingCommand;
use App\Modules\Billing\Console\SnapshotOrganizationBillingCommand;
use App\Modules\Billing\Console\SyncAllOrganizationBillingCommand;
use Illuminate\Support\ServiceProvider;
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
                ProvisionStripeBillingCommand::class,
                SnapshotOrganizationBillingCommand::class,
                SyncAllOrganizationBillingCommand::class,
            ]);
        }
    }

    public function boot(): void
    {
        Livewire::component('billing.show', \App\Modules\Billing\Livewire\Show::class);
        Livewire::component('billing.analytics', \App\Modules\Billing\Livewire\Analytics::class);
        Livewire::component('billing.invoices', \App\Modules\Billing\Livewire\Invoices::class);
    }
}
