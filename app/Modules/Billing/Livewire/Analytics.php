<?php

namespace App\Modules\Billing\Livewire;

use App\Models\Organization;
use App\Modules\Billing\Services\BillingAnalytics;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.app')]
class Analytics extends Component
{
    public Organization $organization;

    public function mount(Organization $organization): void
    {
        $this->authorize('update', $organization);
        $this->organization = $organization;
    }

    public function render(BillingAnalytics $billingAnalytics): View
    {
        $analytics = $billingAnalytics->forOrganization($this->organization);

        return view('livewire.billing.analytics', [
            'summary' => $analytics['summary'] ?? [],
            'forecast' => $analytics['forecast'] ?? [],
            'spendTrend' => $analytics['spend_trend'] ?? [],
            'categoryBreakdown' => $analytics['category_breakdown'] ?? [],
            'lineItems' => $analytics['line_items'] ?? [],
            'edgeUsageDaily' => $analytics['edge_usage_daily'] ?? [],
            'edgeSites' => $analytics['edge_sites'] ?? [],
            'syncEvents' => $analytics['sync_events'] ?? [],
            'invoiceHistory' => $analytics['invoice_history'] ?? [],
            'managedProducts' => $analytics['managed_products'] ?? [],
            'subscription' => $analytics['subscription'] ?? [],
        ]);
    }
}
