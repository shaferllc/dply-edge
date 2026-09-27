<?php

declare(strict_types=1);

namespace App\Modules\Billing\Services;

use App\Models\Organization;
use App\Modules\Billing\Models\Subscription;
use App\Modules\Edge\Services\Containers\EdgeContainerUsageCollector;
use App\Modules\Edge\Services\EdgeDataUsageCollector;
use App\Modules\Edge\Services\EdgeKvUsageCollector;
use App\Modules\Edge\Services\EdgePlatformUsageCollector;
use App\Modules\Edge\Services\EdgeUsageCollector;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Cashier\Cashier;
use Stripe\StripeClient;

/**
 * Usage is billed in arrears, once per Stripe billing period, for exactly
 * that period (ruling r-2zxevg4sj675qn1m):
 *
 * - `invoice.created` for a renewal (billing_reason subscription_cycle, still
 *   a draft) gets one invoice item per usage category at customer price
 *   (cost + margin, UsagePrice) for the period that just ended (days
 *   period_start .. period_end - 1), then one negative "Included usage
 *   credit" item = min(plan credit, usage). Usage never goes below $0.
 * - `customer.subscription.deleted` gets a final invoice for the days from
 *   the last period start to the day it ended. It gets the plan's full
 *   credit too: that period's plan fee was paid in advance, in full, so its
 *   credit is not prorated.
 *
 * A trial's usage is not billed ("5 day free trial"; its spending cap bounds
 * it). One billing_usage_charges row per org and period makes both paths
 * idempotent; each Stripe create also carries an idempotency key.
 *
 * Collectors run hourly, and the full-day "yesterday" re-runs land around
 * 01:30–02:10 UTC, so the period's last day can still be short when the
 * renewal arrives. Its date-based collectors are re-run for that day first
 * ({@see collectLastDay}). Valkey and realtime collect increments, so a late
 * increment is billed next period rather than lost.
 */
class UsageInvoicer
{
    public function __construct(private OrganizationBillingStateComputer $computer) {}

    /**
     * @param  array<string, mixed>  $invoice  a Stripe invoice (webhook payload object)
     * @param  bool  $collect  re-collect the period's last day first (a retry skips it)
     */
    public function onInvoiceCreated(array $invoice, bool $collect = true): void
    {
        if (($invoice['billing_reason'] ?? '') !== 'subscription_cycle' || ($invoice['status'] ?? '') !== 'draft') {
            return;
        }
        $organization = $this->organization($invoice['customer'] ?? null);
        $subscriptionId = $invoice['parent']['subscription_details']['subscription'] ?? null;
        if ($organization === null || ! is_string($subscriptionId)) {
            return;
        }

        $subscription = $this->stripe()->subscriptions->retrieve($subscriptionId)->toArray();
        $periodEnd = (int) $invoice['period_end'];
        if (($subscription['trial_end'] ?? null) !== null && $periodEnd <= (int) $subscription['trial_end']) {
            return; // the trial's own period
        }

        $tier = $this->tierOf($organization, $subscription);
        if ($tier !== null) {
            $lastDay = Carbon::createFromTimestamp($periodEnd)->subDay();
            if ($collect) {
                $this->collectLastDay($lastDay);
            }
            $this->bill($organization, Carbon::createFromTimestamp((int) $invoice['period_start']), $lastDay, $tier, (string) $invoice['id'], $subscription);
        }
    }

    /**
     * Re-collect the period's last day across the account, so its final hour
     * is on the renewal invoice. A collector that fails is reported and the
     * period is billed with what is there.
     *
     * ponytail: account-wide, once per renewal; runs queued
     * (BillRenewalUsageJob). Scope the collectors to one organization
     * if many renewals a day make this slow.
     */
    private function collectLastDay(Carbon $day): void
    {
        foreach ([EdgeUsageCollector::class, EdgeContainerUsageCollector::class, EdgeDataUsageCollector::class, EdgeKvUsageCollector::class, EdgePlatformUsageCollector::class] as $collector) {
            try {
                app($collector)->collectForDate($day->copy()->startOfDay());
            } catch (\Throwable $e) {
                report($e);
            }
        }
        EdgeOrganizationUsageReader::flushMemo();
    }

    /** @param  array<string, mixed>  $subscription  a Stripe subscription (webhook payload object) */
    public function onSubscriptionDeleted(array $subscription): void
    {
        $organization = $this->organization($subscription['customer'] ?? null);
        $periodStart = $subscription['items']['data'][0]['current_period_start'] ?? null;
        if ($organization === null || $periodStart === null) {
            return;
        }
        $ended = (int) ($subscription['ended_at'] ?? time());
        if (($subscription['trial_end'] ?? null) !== null && $ended <= (int) $subscription['trial_end']) {
            return; // canceled during the trial
        }

        $tier = $this->tierOf($organization, $subscription);
        if ($tier !== null) {
            $this->bill($organization, Carbon::createFromTimestamp((int) $periodStart), Carbon::createFromTimestamp($ended), $tier, null, $subscription);
        }
    }

    /**
     * Charge the usage for days $from..$to (inclusive) on $invoiceId, or on a
     * new invoice when there is none (or it is no longer a draft).
     *
     * @param  array<string, mixed>  $subscription  the Stripe subscription: its currency, and the
     *                                              card a standalone invoice charges (Checkout puts
     *                                              it on the subscription, not the customer)
     * @return array<string, int> the lines charged, key => cents (`credit` is negative)
     */
    public function bill(Organization $organization, Carbon $from, Carbon $to, string $tier, ?string $invoiceId, array $subscription = []): array
    {
        $currency = (string) ($subscription['currency'] ?? 'usd');
        $to = $to->lt($from) ? $from->copy() : $to;
        $start = $from->toDateString();
        DB::table('billing_usage_charges')->insertOrIgnore([
            'id' => (string) Str::ulid(),
            'organization_id' => $organization->id,
            'period_start' => $start,
            'period_end' => $to->toDateString(),
            'tier' => $tier,
            'status' => 'pending',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $charge = DB::table('billing_usage_charges')->where('organization_id', $organization->id)->where('period_start', $start)->first();
        if ($charge === null || $charge->status !== 'pending') {
            return []; // already billed (a webhook retry)
        }

        $state = $this->computer->computeForPeriod($organization, $from, $to, $tier);
        $lines = $state->usageLines();
        if ($state->creditAppliedCents() > 0) {
            $lines['credit'] = -$state->creditAppliedCents();
        }
        $record = static fn (string $status, array $extra = []) => DB::table('billing_usage_charges')->where('id', $charge->id)
            ->update(['status' => $status, 'cents' => array_sum($lines), 'lines' => json_encode($lines), 'updated_at' => now()] + $extra);
        if ($lines === []) {
            $record('empty');

            return [];
        }

        $stripe = $this->stripe();
        $customer = (string) $organization->stripe_id;
        if ($invoiceId !== null) {
            $invoice = $stripe->invoices->retrieve($invoiceId);
            $legacy = (string) config('subscription.standard.stripe.edge_usage', '');
            foreach ($invoice->lines->data as $line) {
                if ($legacy !== '' && ($line->pricing->price_details->price ?? null) === $legacy) {
                    // Still on the old in-advance usage line: that line is
                    // this period's usage charge. The next sync removes it.
                    $record('legacy', ['stripe_invoice_id' => $invoiceId]);

                    return [];
                }
            }
            if ($invoice->status !== 'draft') {
                $invoiceId = null; // finalized before we got here
            }
        }
        $standalone = $invoiceId === null;
        $paymentMethod = $subscription['default_payment_method'] ?? null;
        $invoiceId ??= $stripe->invoices->create(array_filter([
            'customer' => $customer,
            'currency' => $currency,
            'collection_method' => 'charge_automatically',
            'pending_invoice_items_behavior' => 'exclude',
            'default_payment_method' => is_array($paymentMethod) ? ($paymentMethod['id'] ?? null) : $paymentMethod,
            'description' => sprintf('Usage %s – %s', $from->toFormattedDateString(), $to->toFormattedDateString()),
            'metadata' => ['dply_usage_period' => $start],
        ]), ['idempotency_key' => "usage-invoice:{$organization->id}:{$start}"])->id;

        foreach ($lines as $key => $cents) {
            $stripe->invoiceItems->create([
                'customer' => $customer,
                'invoice' => $invoiceId,
                'amount' => $cents,
                'currency' => $currency,
                'description' => DesiredBillingState::usageLineLabel($key).sprintf(' — %s to %s', $from->format('M j'), $to->format('M j')),
                'period' => ['start' => $from->copy()->startOfDay()->timestamp, 'end' => $to->copy()->endOfDay()->timestamp],
                'metadata' => ['dply_usage_period' => $start, 'dply_usage_line' => $key],
            ], ['idempotency_key' => "usage:{$organization->id}:{$start}:{$key}:{$invoiceId}"]);
        }
        if ($standalone) {
            $stripe->invoices->finalizeInvoice($invoiceId, ['auto_advance' => true]);
        }
        $record('billed', ['stripe_invoice_id' => $invoiceId]);

        return $lines;
    }

    /**
     * Keep the subscription row's current Stripe period (for "this period" on
     * the billing page). The period lives on the items since API 2025-03.
     *
     * @param  array<string, mixed>  $stripeSubscription
     */
    public function rememberPeriod(Subscription $subscription, array $stripeSubscription): void
    {
        $item = $stripeSubscription['items']['data'][0] ?? null;
        if (! isset($item['current_period_start'], $item['current_period_end'])) {
            return;
        }
        $subscription->forceFill([
            'current_period_start' => Carbon::createFromTimestamp((int) $item['current_period_start']),
            'current_period_end' => Carbon::createFromTimestamp((int) $item['current_period_end']),
        ])->save();
    }

    /**
     * The plan the period was on, from the subscription's prices: a
     * configured plan price (current or STRIPE_PRICE_*_LEGACY), else the
     * price's own `metadata.dply_role` (an archived plan price nobody listed
     * keeps it). Null for Enterprise (invoiced by hand), and for a price
     * nothing identifies: that is reported, not guessed.
     *
     * @param  array<string, mixed>  $subscription
     */
    private function tierOf(Organization $organization, array $subscription): ?string
    {
        $items = (array) ($subscription['items']['data'] ?? []);
        $prices = array_values(array_filter(array_map(static fn (array $item): ?string => $item['price']['id'] ?? null, $items)));
        $enterprise = (string) config('subscription.enterprise.stripe_price_id', '');
        if ($enterprise !== '' && in_array($enterprise, $prices, true)) {
            return null;
        }
        foreach ($items as $item) {
            $tier = SubscriptionPlanResolver::tierOfPrice((string) ($item['price']['id'] ?? ''), (array) ($item['price']['metadata'] ?? []));
            if ($tier !== null) {
                return $tier;
            }
        }

        $unknown = SubscriptionPlanResolver::unrecognisedPrices($prices);
        if ($unknown !== []) {
            report(new \RuntimeException('Usage not billed: subscription '.($subscription['id'] ?? '?')." of organization {$organization->id} has no recognisable plan price (".implode(', ', $unknown).'). List it in STRIPE_PRICE_*_LEGACY and bill the period by hand.'));

            return null;
        }

        // A pre-tier per-site subscription reads as Pro until the sync moves it.
        return $organization->onEnterpriseSubscription() ? null : 'pro';
    }

    private function organization(mixed $customerId): ?Organization
    {
        return is_string($customerId) && $customerId !== ''
            ? Organization::query()->where('stripe_id', $customerId)->first()
            : null;
    }

    private function stripe(): StripeClient
    {
        return Cashier::stripe();
    }
}
