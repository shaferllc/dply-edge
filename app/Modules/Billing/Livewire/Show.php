<?php

namespace App\Modules\Billing\Livewire;

use App\Livewire\Concerns\DispatchesToastNotifications;
use App\Models\Organization;
use App\Modules\Billing\Services\BillingAnalytics;
use App\Modules\Billing\Services\DesiredBillingState;
use App\Modules\Billing\Services\OrganizationBillingStateComputer;
use App\Modules\Billing\Services\PlanCheckout;
use App\Modules\Billing\Services\StandardSubscriptionCreator;
use App\Modules\Billing\Services\StarterTrafficGate;
use App\Modules\Billing\Services\SubscriptionPlanResolver;
use App\Modules\Billing\Services\VatInsightService;
use Carbon\CarbonInterface;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\Rule;
use Laravel\Cashier\Invoice;
use Laravel\Cashier\Subscription;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Throwable;

/**
 * Livewire exposes #[Computed] methods and the older get<Name>Property()
 * methods as $this-><name> in PHP and Blade. PHPStan cannot see that
 * magic, so the contract is stated here.
 *
 * @property-read Subscription|null $subscription
 * @property-read DesiredBillingState $billingState
 * @property-read array<string, int|null|string> $costForecast
 * @property-read bool $standardPricingAvailable
 * @property-read string $paymentSummary
 */
#[Layout('layouts.app')]
class Show extends Component
{
    use DispatchesToastNotifications;

    public Organization $organization;

    /**
     * Billing-entity fields for the org's invoices. Migrated off
     * `users` in 2026-05 because subscriptions are org-scoped.
     */
    public string $invoice_email = '';

    public string $vat_number = '';

    public string $billing_currency = '';

    public string $billing_details = '';

    /** Usage soft limit in dollars ('' = the default, twice the plan price). */
    public string $usage_alert_dollars = '';

    /** Monthly cap on AI, browser rendering and vector search (EdgeMeter); '' = the default, 0 = off. */
    public string $metered_cap_dollars = '';

    /** Flipped by wire:init so the Stripe invoice list never blocks first paint. */
    public bool $invoicesLoaded = false;

    /** Stripe reads are cached per customer; SyncBillingOnSubscriptionWebhook busts them. */
    private const STRIPE_CACHE_TTL = 600;

    public static function forgetStripeCache(string $stripeId): void
    {
        Cache::forget("billing:stripe:{$stripeId}:invoices");
        Cache::forget("billing:stripe:{$stripeId}:next-invoice");
        Cache::forget("billing:stripe:{$stripeId}:payment-summary");
    }

    public function mount(Organization $organization): void
    {
        $this->authorize('update', $organization);
        $this->organization = $organization;
        $this->invoice_email = (string) ($organization->invoice_email ?? '');
        $this->vat_number = (string) ($organization->vat_number ?? '');
        $this->billing_currency = (string) ($organization->billing_currency ?? '');
        $this->billing_details = (string) ($organization->billing_details ?? '');
        $this->usage_alert_dollars = $organization->usage_alert_cents === null ? '' : (string) ($organization->usage_alert_cents / 100);
        $this->metered_cap_dollars = $organization->metered_cap_cents === null ? '' : (string) ($organization->metered_cap_cents / 100);
    }

    /** Owners are emailed at 50/80/100% of this each billing period (UsageAlerts). */
    public function saveUsageAlert(): void
    {
        $this->authorize('update', $this->organization);
        $this->validate(['usage_alert_dollars' => ['nullable', 'numeric', 'min:1', 'max:1000000']]);
        $this->organization->forceFill([
            'usage_alert_cents' => $this->usage_alert_dollars === '' ? null : (int) round((float) $this->usage_alert_dollars * 100),
        ])->save();
        $this->toastSuccess(__('Usage alert saved.'));
    }

    /** AI, browser rendering and vector search are refused past this each period (EdgeMeter). */
    public function saveMeteredCap(): void
    {
        $this->authorize('update', $this->organization);
        $ceiling = (int) config('edge.metered_services.ceiling_cents', 100000) / 100;
        $this->validate(['metered_cap_dollars' => ['nullable', 'numeric', 'min:0', 'max:'.$ceiling]]);
        $this->organization->forceFill([
            'metered_cap_cents' => $this->metered_cap_dollars === '' ? null : (int) round((float) $this->metered_cap_dollars * 100),
        ])->save();
        Cache::forget('edge-meter:spent:'.$this->organization->id);
        $this->toastSuccess(__('Limit saved. Apps pick it up within a minute.'));
    }

    public function saveBillingDetails(VatInsightService $vatInsights): void
    {
        $this->authorize('update', $this->organization);

        $rules = [
            'invoice_email' => ['nullable', 'string', 'email', 'max:255'],
            'vat_number' => [
                'nullable',
                'string',
                'max:64',
                function ($attribute, $value, $fail) use ($vatInsights): void {
                    $msg = $vatInsights->blockingValidationMessage(is_string($value) ? $value : null);
                    if ($msg !== null) {
                        $fail($msg);
                    }
                },
            ],
            'billing_details' => ['nullable', 'string', 'max:5000'],
        ];

        // Currency must come from the supported list when populated.
        $allowed = array_keys((array) config('profile_options.currencies', []));
        $rules['billing_currency'] = $this->billing_currency === ''
            ? ['nullable']
            : ['nullable', 'string', Rule::in($allowed)];

        $this->validate($rules);

        $this->organization->update([
            'invoice_email' => $this->invoice_email !== '' ? $this->invoice_email : null,
            'vat_number' => $this->vat_number !== '' ? $this->vat_number : null,
            'billing_currency' => $this->billing_currency === '' ? null : $this->billing_currency,
            'billing_details' => $this->billing_details !== '' ? $this->billing_details : null,
        ]);

        $fresh = $this->organization->fresh();
        if ($fresh) {
            $this->organization = $fresh;
            $this->invoice_email = (string) ($fresh->invoice_email ?? '');
            $this->vat_number = (string) ($fresh->vat_number ?? '');
            $this->billing_currency = (string) ($fresh->billing_currency ?? '');
            $this->billing_details = (string) ($fresh->billing_details ?? '');
        }

        $this->toastSuccess(__('Billing details saved.'));

        foreach ($vatInsights->collectSoftWarnings($this->vat_number) as $message) {
            $this->toastInfo($message);
        }
    }

    public function getSubscriptionProperty()
    {
        return $this->organization->subscription('default');
    }

    public function getStatusProperty(): ?string
    {
        $sub = $this->getSubscriptionProperty();

        return $sub ? $sub->stripe_status : null;
    }

    public function getPlanNameProperty(): ?string
    {
        $sub = $this->getSubscriptionProperty();
        if (! $sub) {
            return null;
        }
        if ($this->organization->onAnyPaidPlan()) {
            return $this->organization->planTierLabel();
        }

        return $sub->stripe_price ?? $sub->items->first()?->stripe_price;
    }

    public function getPaymentSummaryProperty(): string
    {
        $org = $this->organization;
        if ($org->pm_last_four) {
            return '•••• '.$org->pm_last_four;
        }
        if (! $org->hasStripeId()) {
            return 'No payment method';
        }

        // defaultPaymentMethod() is a Stripe round-trip; cache it like the invoices.
        return Cache::remember("billing:stripe:{$org->stripe_id}:payment-summary", self::STRIPE_CACHE_TTL, function () use ($org): string {
            $paymentMethod = $org->defaultPaymentMethod();
            if ($paymentMethod && method_exists($paymentMethod, 'asStripePaymentMethod')) {
                $pm = $paymentMethod->asStripePaymentMethod();
                if (isset($pm->card->last4)) {
                    return '•••• '.$pm->card->last4;
                }
            }

            return 'No payment method';
        });
    }

    /**
     * True only when there's a real subscription to manage. Deliberately not
     * gated on hasStripeId() — a Stripe customer record is created the moment
     * a Checkout session opens, well before (or even without) a completed
     * subscription. Gating on the customer record would hide the Subscribe
     * button from anyone who abandoned a checkout.
     */
    public function getCanManageBillingProperty(): bool
    {
        return $this->subscription !== null;
    }

    public function loadInvoices(): void
    {
        $this->invoicesLoaded = true;
    }

    /**
     * Plain rows (not Cashier Invoice objects) so they cache cleanly.
     *
     * @return list<array{date: int, total: string, url: string|null}>
     */
    public function getInvoicesProperty(): array
    {
        if (! $this->invoicesLoaded || ! $this->organization->hasStripeId()) {
            return [];
        }

        try {
            return Cache::remember(
                "billing:stripe:{$this->organization->stripe_id}:invoices",
                self::STRIPE_CACHE_TTL,
                $this->stripeInvoiceRows(...),
            );
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * @return list<array{date: int, total: string, url: string|null}>
     */
    private function stripeInvoiceRows(): array
    {
        return $this->organization->invoices(false, ['limit' => 12])
            ->map(fn (Invoice $invoice): array => [
                'date' => $invoice->date()->getTimestamp(),
                'total' => $invoice->total(),
                'url' => $invoice->asStripeInvoice()->hosted_invoice_url ?? null,
            ])->values()->all();
    }

    /**
     * Start a Stripe Checkout session for a paid plan. Line items are the
     * plan fee plus any extra seats the org already has.
     */
    public function subscribeTier(string $tier = 'pro'): mixed
    {
        $this->authorize('update', $this->organization);

        if (! in_array($tier, SubscriptionPlanResolver::PAID_TIERS, true)) {
            $this->addError('plan', __('Choose Starter, Pro or Team.'));

            return null;
        }
        if (($seatError = $this->seatCapError($tier)) !== null) {
            $this->addError('plan', $seatError);

            return null;
        }

        if ($this->organization->subscription('default')?->valid()) {
            $this->addError('billing', __('This organization already has a subscription. Change plan below instead.'));

            return null;
        }

        $subscriptionUrl = route('subscription.show', $this->organization);
        $url = app(PlanCheckout::class)->url($this->organization, $tier, $subscriptionUrl.'?checkout=success', $subscriptionUrl.'?checkout=cancelled');
        if ($url === null) {
            $this->addError('billing', __('Plan pricing is not configured yet. Contact :email.', ['email' => config('dply.support_email')]));

            return null;
        }

        audit_log($this->organization, auth()->user(), 'billing.checkout_started', null, null, [
            'plan' => $tier,
        ]);

        // Stripe Checkout lives on a different origin (checkout.stripe.com),
        // so Livewire's default wire:navigate redirect fails silently — pass
        // navigate: false to force a full-page window.location swap.
        return $this->redirect($url, navigate: false);
    }

    /**
     * Move an existing subscription to another paid tier. Swaps every line to
     * the target tier's set and invoices the prorated difference now.
     */
    public function changeTier(string $tier): mixed
    {
        $this->authorize('update', $this->organization);

        $subscription = $this->organization->subscription('default');
        if (! $subscription || ! $subscription->valid()) {
            return $this->billingRedirect('billing_error', __('No active subscription to change.'));
        }
        if (! in_array($tier, SubscriptionPlanResolver::PAID_TIERS, true) || $tier === $this->organization->subscribedTier()) {
            return $this->billingRedirect('billing_error', __('Choose a different plan.'));
        }
        if (($seatError = $this->seatCapError($tier)) !== null) {
            return $this->billingRedirect('billing_error', $seatError);
        }

        $items = app(StandardSubscriptionCreator::class)->buildPriceList(
            app(OrganizationBillingStateComputer::class)->computeForTier($this->organization, $tier),
        );
        if ($items === []) {
            return $this->billingRedirect('billing_error', __('Plan pricing is not configured yet. Contact :email.', ['email' => config('dply.support_email')]));
        }

        audit_log($this->organization, auth()->user(), 'billing.plan_changed', null, null, [
            'from' => $this->organization->subscribedTier(),
            'to' => $tier,
        ]);

        try {
            $prices = collect($items)->mapWithKeys(
                static fn (array $item): array => [$item['price'] => ['quantity' => $item['quantity']]],
            )->all();
            // During a trial there is nothing to prorate: swap and keep the
            // trial (Cashier keeps trial_end while onTrial()).
            $subscription->onTrial() ? $subscription->swap($prices) : $subscription->swapAndInvoice($prices);
        } catch (Throwable $e) {
            report($e);

            return $this->billingRedirect('billing_error', __('Could not change plan. Please try again or contact :email.', ['email' => config('dply.support_email')]));
        }

        OrganizationBillingStateComputer::flushMemo((string) $this->organization->id);

        return $this->billingRedirect('billing_status', __('You\'re now on :plan.', [
            'plan' => (string) config('subscription.standard.tiers.'.$tier.'.label'),
        ]));
    }

    /**
     * End a card trial now and start paying: lifts the trial's spending cap.
     */
    public function endTrial(): mixed
    {
        $this->authorize('update', $this->organization);
        $subscription = $this->organization->subscription('default');
        if (! $subscription || ! $subscription->onTrial()) {
            return $this->billingRedirect('billing_error', __('There is no trial to end.'));
        }
        try {
            $subscription->endTrial();
        } catch (Throwable $e) {
            report($e);

            return $this->billingRedirect('billing_error', __('Could not end the trial. Please try again or contact :email.', ['email' => config('dply.support_email')]));
        }
        audit_log($this->organization, auth()->user(), 'billing.trial_ended_early');
        app(StarterTrafficGate::class)->syncOrganization($this->organization->fresh());

        return $this->billingRedirect('billing_status', __('Your plan is active and billed from today.'));
    }

    /**
     * Cancel the subscription at the end of the current billing period. The
     * customer keeps full access until then (Cashier grace period) and can
     * resume before it ends.
     */
    public function cancelSubscription(): mixed
    {
        $this->authorize('update', $this->organization);

        $subscription = $this->organization->subscription('default');
        if (! $subscription || ! $subscription->valid()) {
            return $this->billingRedirect('billing_error', __('No active subscription to cancel.'));
        }
        if ($subscription->canceled()) {
            return $this->billingRedirect('billing_error', __('This subscription is already scheduled to cancel.'));
        }

        audit_log($this->organization, auth()->user(), 'billing.subscription_canceled');

        try {
            $subscription->cancel();
        } catch (Throwable $e) {
            return $this->billingRedirect('billing_error', __('Could not cancel the subscription. Please try again or contact :email.', ['email' => config('dply.support_email')]));
        }

        // getAttribute(): ends_at is a Cashier column (cast to datetime in
        // the package), but Cashier's migrations live in the vendor dir, so
        // Larastan cannot see the column from database/migrations.
        $endsAt = $subscription->fresh()?->getAttribute('ends_at');

        return $this->billingRedirect('billing_status', $endsAt
            ? __('Subscription canceled. You keep full access until :date.', ['date' => $endsAt->toFormattedDateString()])
            : __('Subscription canceled. You keep access until the end of your billing period.'));
    }

    /**
     * Un-cancel a subscription that's still inside its grace period.
     */
    public function resumeSubscription(): mixed
    {
        $this->authorize('update', $this->organization);

        $subscription = $this->organization->subscription('default');
        if (! $subscription || ! $subscription->onGracePeriod()) {
            return $this->billingRedirect('billing_error', __('There\'s no canceled subscription to resume.'));
        }

        audit_log($this->organization, auth()->user(), 'billing.subscription_resumed');

        try {
            $subscription->resume();
        } catch (Throwable $e) {
            return $this->billingRedirect('billing_error', __('Could not resume the subscription. Please try again or contact :email.', ['email' => config('dply.support_email')]));
        }

        return $this->billingRedirect('billing_status', __('Your subscription has been resumed.'));
    }

    /**
     * Flash a message and reload the billing page. Reloading gives a clean
     * end state for these modal-driven actions: the modal disappears, any
     * stale subscription state is re-read fresh, and the flashed alert shows.
     */
    private function billingRedirect(string $key, string $message): mixed
    {
        session()->flash($key, $message);

        return $this->redirect(route('subscription.show', $this->organization));
    }

    /**
     * True when the subscription is canceled but still inside the grace period
     * — the customer has access but billing will stop at period end.
     */
    public function getOnGracePeriodProperty(): bool
    {
        return $this->subscription?->onGracePeriod() ?? false;
    }

    public function getSubscriptionEndsAtProperty(): ?CarbonInterface
    {
        return $this->subscription?->getAttribute('ends_at');
    }

    public function getStandardPricingAvailableProperty(): bool
    {
        // Checkout needs a plan price; everything else on the list is optional.
        foreach (SubscriptionPlanResolver::PAID_TIERS as $tier) {
            if ((string) config('subscription.standard.stripe.tier_'.$tier) !== '') {
                return true;
            }
        }

        return false;
    }

    /** Why the org's members do not fit a plan with a hard seat cap, or null. */
    private function seatCapError(string $tier): ?string
    {
        $plan = (array) config('subscription.standard.tiers.'.$tier);
        if (($plan['extra_seat_cents'] ?? null) !== null || ($plan['seats'] ?? null) === null) {
            return null;
        }
        $seats = (int) $plan['seats'];

        return $this->organization->seatCount() > $seats
            ? trans_choice(':plan includes :count seat. Remove members before moving to :plan.|:plan includes :count seats. Remove members before moving to :plan.', $seats, ['plan' => $plan['label'], 'count' => $seats])
            : null;
    }

    /**
     * The bill dply *would* charge based on the org's live Edge sites — the
     * estimate before subscribing, the current invoice basis after.
     *
     * Request-memoised via {@see Computed} and
     * {@see OrganizationBillingStateComputer::compute()} so hero / preview /
     * line-item accessors share one DesiredBillingState.
     */
    #[Computed]
    public function billingState(): DesiredBillingState
    {
        return app(OrganizationBillingStateComputer::class)->compute($this->organization);
    }

    /**
     * Compact cost forecast for the merged billing page — projected
     * month-end plus Δ vs 30 days. Charts stay off this page.
     *
     * @return array<string, int|null|string>
     */
    #[Computed]
    public function costForecast(): array
    {
        return app(BillingAnalytics::class)->forecastFor($this->organization);
    }

    /**
     * Structured line items for the "Your bill" hero — one per Edge site kind
     * in use plus metered Edge usage.
     *
     * @return list<array{label: string, quantity: int, unit_cents: int, line_cents: int, detail?: ?string}>
     */
    public function getTierLineItemsProperty(): array
    {
        return app(BillingAnalytics::class)->lineItems($this->billingState);
    }

    public function getNextInvoiceAtProperty(): ?CarbonInterface
    {
        $sub = $this->subscription;
        if (! $sub) {
            return null;
        }

        try {
            $ts = Cache::remember(
                "billing:stripe:{$this->organization->stripe_id}:next-invoice",
                self::STRIPE_CACHE_TTL,
                fn (): int => $this->organization->upcomingInvoice()?->date()->getTimestamp() ?? 0,
            );

            return $ts > 0 ? Carbon::createFromTimestamp($ts) : null;
        } catch (Throwable) {
            return null;
        }
    }

    public function portal(): mixed
    {
        $this->authorize('update', $this->organization);

        if (! $this->organization->hasStripeId()) {
            $this->addError('billing', 'No billing account yet. Add a card first.');

            return null;
        }

        audit_log($this->organization, auth()->user(), 'billing.portal_accessed');

        return $this->organization->redirectToBillingPortal(route('subscription.show', $this->organization));
    }

    public function render(): View
    {
        return view('livewire.billing.show');
    }
}
