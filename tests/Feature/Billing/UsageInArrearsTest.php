<?php

declare(strict_types=1);

use App\Models\EdgeDataUsage;
use App\Models\Organization;
use App\Models\User;
use App\Modules\Billing\Jobs\BillRenewalUsageJob;
use App\Modules\Billing\Models\Subscription;
use App\Modules\Billing\Services\BillingForecastCalculator;
use App\Modules\Billing\Services\DesiredBillingState;
use App\Modules\Billing\Services\EdgeOrganizationUsageReader;
use App\Modules\Billing\Services\EdgeUsageCostCalculator;
use App\Modules\Billing\Services\EdgeUsageTotals;
use App\Modules\Billing\Services\StandardSubscriptionCreator;
use App\Modules\Billing\Services\StripeSubscriptionSyncer;
use App\Modules\Billing\Services\UsageAlerts;
use App\Modules\Billing\Services\UsageInvoicer;
use App\Modules\Billing\Support\UsagePrice;
use App\Modules\Edge\Services\Containers\EdgeContainerUsageCollector;
use App\Modules\Edge\Services\EdgeDataUsageCollector;
use App\Modules\Edge\Services\EdgeKvUsageCollector;
use App\Modules\Edge\Services\EdgePlatformUsageCollector;
use App\Modules\Edge\Services\EdgeUsageCollector;
use App\Notifications\UsageThresholdNotice;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\CallQueuedClosure;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Laravel\Cashier\Events\WebhookHandled;
use Laravel\Cashier\Events\WebhookReceived;
use Stripe\ApiRequestor;
use Stripe\HttpClient\ClientInterface;

uses(RefreshDatabase::class);

/*
 * Usage is billed in arrears: one set of lines on each renewal invoice for the
 * period that just ended, a final invoice on cancel, never a subscription line.
 */

/** Stands in for api.stripe.com; records every call. */
final class FakeStripeHttp implements ClientInterface
{
    /** @var list<array{method: string, path: string, params: array<string, mixed>}> */
    public array $calls = [];

    public array $subscription = ['id' => 'sub_1', 'object' => 'subscription', 'trial_end' => null, 'items' => ['object' => 'list', 'data' => [['id' => 'si_1', 'price' => ['id' => 'price_tier_pro']]]]];

    public array $invoice = ['id' => 'in_renewal', 'object' => 'invoice', 'status' => 'draft', 'lines' => ['object' => 'list', 'data' => []]];

    public function request($method, $absUrl, $headers, $params, $hasFile, $apiMode = 'v1', $maxNetworkRetries = null)
    {
        $path = (string) parse_url($absUrl, PHP_URL_PATH);
        $this->calls[] = ['method' => $method, 'path' => $path, 'params' => (array) $params];
        $body = match (true) {
            str_starts_with($path, '/v1/subscriptions/') => $this->subscription,
            $method === 'get' && str_starts_with($path, '/v1/invoices/') => $this->invoice,
            $path === '/v1/invoiceitems' => ['id' => 'ii_'.count($this->calls), 'object' => 'invoiceitem'],
            $path === '/v1/invoices' => ['id' => 'in_final', 'object' => 'invoice', 'status' => 'draft'],
            str_ends_with($path, '/finalize') => ['id' => 'in_final', 'object' => 'invoice', 'status' => 'open'],
            default => ['id' => 'x', 'object' => 'unknown'],
        };

        return [json_encode($body), 200, []];
    }

    /** @return list<array<string, mixed>> */
    public function items(): array
    {
        return array_values(array_map(fn ($c) => $c['params'], array_filter($this->calls, fn ($c) => $c['path'] === '/v1/invoiceitems')));
    }
}

beforeEach(function () {
    config([
        'cashier.secret' => 'sk_test_fake',
        'subscription.standard.stripe.tier_pro' => 'price_tier_pro',
        'subscription.standard.stripe.edge_usage' => 'price_edge_usage',
        'dply.edge.usage_billing.margin_percent' => 0,
        // Most tests read the usage lines alone; the credit line has its own test.
        'subscription.standard.tiers.pro.usage_credit_cents' => 0,
    ]);
    $this->stripe = new FakeStripeHttp;
    ApiRequestor::setHttpClient($this->stripe);
    $this->org = Organization::factory()->create(['stripe_id' => 'cus_1']);
    $this->org->users()->attach(User::factory()->create()->id, ['role' => 'owner']);
    fakeUsageCollectors();
});

afterEach(fn () => ApiRequestor::setHttpClient(null));

/** $3.15 of D1 + Queues usage on $date (see EdgeDataUsageBillingTest). */
function dataUsageOn(Organization $org, string $date): void
{
    EdgeDataUsage::query()->create(['organization_id' => $org->id, 'date' => $date, 'd1_rows_read' => 1_000_000_000, 'd1_rows_written' => 1_000_000, 'd1_storage_bytes' => 1024 ** 3, 'queue_operations' => 1_000_000]);
}

function renewalInvoice(array $overrides = []): array
{
    return $overrides + [
        'id' => 'in_renewal', 'object' => 'invoice', 'customer' => 'cus_1', 'status' => 'draft',
        'billing_reason' => 'subscription_cycle',
        'period_start' => Carbon::parse('2026-08-05 14:00')->timestamp,
        'period_end' => Carbon::parse('2026-09-05 14:00')->timestamp,
        'parent' => ['subscription_details' => ['subscription' => 'sub_1']],
    ];
}

function stripeEvent(string $type, array $object): void
{
    $before = count(Queue::pushedJobs()[BillRenewalUsageJob::class] ?? []);
    event(new WebhookReceived(['type' => $type, 'data' => ['object' => $object]]));
    // invoice.created is queued: run it as a worker would.
    foreach (array_slice(Queue::pushedJobs()[BillRenewalUsageJob::class] ?? [], $before) as $pushed) {
        app()->call([$pushed['job'], 'handle']);
    }
}

/** Stand-ins for the date-based usage collectors the renewal re-runs. */
function fakeUsageCollectors(?Closure $data = null): void
{
    $fake = fn (?Closure $then = null) => new class($then)
    {
        public function __construct(private ?Closure $then) {}

        public function collectForDate($date, bool $dryRun = false): array
        {
            $this->then && ($this->then)($date);

            return [];
        }
    };
    foreach ([EdgeUsageCollector::class, EdgeContainerUsageCollector::class, EdgeKvUsageCollector::class, EdgePlatformUsageCollector::class] as $class) {
        app()->instance($class, $fake());
    }
    app()->instance(EdgeDataUsageCollector::class, $fake($data));
}

test('a renewal invoice gets the ended period’s usage, once, for exactly that period', function () {
    dataUsageOn($this->org, '2026-08-04'); // before the period
    dataUsageOn($this->org, '2026-08-05'); // first day
    dataUsageOn($this->org, '2026-09-04'); // last day
    dataUsageOn($this->org, '2026-09-05'); // renewal day: next period

    stripeEvent('invoice.created', renewalInvoice());
    stripeEvent('invoice.created', renewalInvoice()); // Stripe retry

    $items = $this->stripe->items();
    expect($items)->toHaveCount(1)
        ->and($items[0]['invoice'])->toBe('in_renewal')
        ->and($items[0]['amount'])->toBe(555) // two days; storage is the peak, once
        ->and($items[0]['metadata']['dply_usage_line'])->toBe('data')
        ->and($items[0]['description'])->toContain('Aug 5 to Sep 4');

    $charge = DB::table('billing_usage_charges')->where('organization_id', $this->org->id)->sole();
    expect($charge->status)->toBe('billed')->and($charge->period_start)->toBe('2026-08-05')->and($charge->period_end)->toBe('2026-09-04')->and($charge->cents)->toBe(555);
});

test('the renewal re-collects the period’s last day first, so its final hour is billed', function () {
    dataUsageOn($this->org, '2026-08-05');
    // Cloudflare's last samples for Sep 4 are only there once collected again.
    fakeUsageCollectors(fn ($date) => dataUsageOn($this->org, $date->toDateString()));

    stripeEvent('invoice.created', renewalInvoice());

    expect($this->stripe->items()[0]['amount'])->toBe(555); // both days, not 315 + storage for one
    // The webhook already answered, so the job retries in Stripe's place.
    expect((new BillRenewalUsageJob([]))->tries)->toBeGreaterThan(1);
});

test('a collector that fails does not stop the renewal from billing', function () {
    dataUsageOn($this->org, '2026-08-05');
    fakeUsageCollectors(fn () => throw new RuntimeException('Cloudflare is down'));

    stripeEvent('invoice.created', renewalInvoice());

    expect($this->stripe->items())->toHaveCount(1);
});

test('the trial’s own period, other invoices and zero usage bill nothing', function () {
    dataUsageOn($this->org, '2026-08-06');

    stripeEvent('invoice.created', renewalInvoice(['billing_reason' => 'subscription_create']));
    stripeEvent('invoice.created', renewalInvoice(['status' => 'open']));
    $this->stripe->subscription['trial_end'] = Carbon::parse('2026-09-05 14:00')->timestamp;
    stripeEvent('invoice.created', renewalInvoice());
    expect($this->stripe->items())->toBe([]);

    $this->stripe->subscription['trial_end'] = Carbon::parse('2026-08-05 14:00')->timestamp;
    stripeEvent('invoice.created', renewalInvoice(['period_start' => Carbon::parse('2026-10-05')->timestamp, 'period_end' => Carbon::parse('2026-11-05')->timestamp]));
    expect($this->stripe->items())->toBe([])
        ->and(DB::table('billing_usage_charges')->value('status'))->toBe('empty');
});

test('a draft still carrying the old in-advance usage line is not billed twice', function () {
    dataUsageOn($this->org, '2026-08-06');
    $this->stripe->invoice['lines']['data'][] = ['id' => 'il_1', 'object' => 'line_item', 'pricing' => ['price_details' => ['price' => 'price_edge_usage']]];

    stripeEvent('invoice.created', renewalInvoice());

    expect($this->stripe->items())->toBe([])->and(DB::table('billing_usage_charges')->value('status'))->toBe('legacy');
});

test('a canceled subscription’s last partial period is billed on a final invoice', function () {
    dataUsageOn($this->org, '2026-09-05');
    dataUsageOn($this->org, '2026-09-12');

    stripeEvent('customer.subscription.deleted', [
        'id' => 'sub_1', 'customer' => 'cus_1', 'trial_end' => null, 'currency' => 'usd', 'default_payment_method' => 'pm_sub_card',
        'ended_at' => Carbon::parse('2026-09-12 10:00')->timestamp,
        'items' => ['data' => [['price' => ['id' => 'price_tier_pro'], 'current_period_start' => Carbon::parse('2026-09-05 14:00')->timestamp]]],
    ]);

    // Queued (the suite fakes the queue): run it as a worker would.
    Queue::assertClosurePushed();
    Queue::pushedJobs()[CallQueuedClosure::class][0]['job']->handle(app());
    $paths = array_column($this->stripe->calls, 'path');
    expect($paths)->toBe(['/v1/invoices', '/v1/invoiceitems', '/v1/invoices/in_final/finalize'])
        ->and($this->stripe->items()[0]['amount'])->toBe(555)
        ->and($this->stripe->items()[0]['invoice'])->toBe('in_final')
        // Checkout keeps the card on the subscription, so the invoice names it.
        ->and($this->stripe->calls[0]['params']['default_payment_method'])->toBe('pm_sub_card');
});

test('enterprise is invoiced by hand, so its cancellation bills no usage', function () {
    config(['subscription.enterprise.stripe_price_id' => 'price_enterprise']);
    dataUsageOn($this->org, '2026-09-06');

    app(UsageInvoicer::class)->onSubscriptionDeleted([
        'id' => 'sub_1', 'customer' => 'cus_1', 'trial_end' => null, 'ended_at' => Carbon::parse('2026-09-12')->timestamp,
        'items' => ['data' => [['price' => ['id' => 'price_enterprise'], 'current_period_start' => Carbon::parse('2026-09-05')->timestamp]]],
    ]);

    expect($this->stripe->calls)->toBe([]);
});

test('subscription webhooks remember the current stripe period, and billing reads it', function () {
    $sub = Subscription::factory()->withPrice('price_tier_pro')->active()->create(['organization_id' => $this->org->id, 'stripe_id' => 'sub_1']);
    event(new WebhookHandled(['type' => 'customer.subscription.updated', 'data' => ['object' => [
        'id' => 'sub_1',
        'items' => ['data' => [['current_period_start' => now()->subDays(3)->timestamp, 'current_period_end' => now()->addDays(27)->timestamp]]],
    ]]]));

    expect($sub->fresh()->current_period_start->toDateString())->toBe(now()->subDays(3)->toDateString());
    [$from, $to] = app(EdgeOrganizationUsageReader::class)->currentWindow($this->org->fresh());
    expect($from->toDateString())->toBe(now()->subDays(3)->toDateString())->and($to->toDateString())->toBe(now()->toDateString());

    $none = Organization::factory()->create();
    expect(app(EdgeOrganizationUsageReader::class)->currentWindow($none)[0]->toDateString())->toBe(now()->startOfMonth()->toDateString());
});

test('usage is never a subscription line: new subscriptions skip it and the sync removes it without proration', function () {
    config(['subscription.standard.stripe.edge' => 'price_edge']);
    $state = DesiredBillingState::fromPlanAndUsage(plan: ['key' => 'pro', 'label' => 'Pro', 'price_cents' => 2000], usage: ['data' => 500]);
    expect(app(StandardSubscriptionCreator::class)->buildPriceList($state))->toBe([['price' => 'price_tier_pro', 'quantity' => 1]]);
    config(['subscription.standard.stripe.retired_site_fees' => []]);

    $real = Subscription::factory()->active()->create(['organization_id' => $this->org->id, 'stripe_price' => null]);
    foreach (['price_tier_pro', 'price_edge_usage'] as $price) {
        $real->items()->create(['stripe_id' => 'si_'.Str::random(8), 'stripe_product' => 'prod', 'stripe_price' => $price, 'quantity' => 1]);
    }
    $fake = new class extends Subscription
    {
        public array $log = [];

        public function noProrate()
        {
            $this->log[] = 'noProrate';

            return $this;
        }

        public function alwaysInvoice()
        {
            $this->log[] = 'alwaysInvoice';

            return $this;
        }

        public function removePrice($price)
        {
            $this->log[] = 'remove:'.$price;

            return $this;
        }

        public function addPrice($price, $quantity = 1, array $options = [])
        {
            $this->log[] = 'add:'.$price;

            return $this;
        }
    };
    $fake->setRawAttributes($real->getAttributes(), true);
    $fake->exists = true;
    $fake->setRelation('items', $real->items);
    $org = new class extends Organization
    {
        public ?Subscription $fakeSubscription = null;

        public function subscription(string $type = 'default'): ?Laravel\Cashier\Subscription
        {
            return $this->fakeSubscription;
        }
    };
    $org->id = $this->org->id;
    $org->fakeSubscription = $fake;

    $changes = app(StripeSubscriptionSyncer::class)->reconcile($org, $state);

    expect(array_slice($fake->log, -2))->toBe(['noProrate', 'remove:price_edge_usage'])
        ->and(preg_grep('/^add:/', $fake->log))->toBe([])
        ->and($changes)->toBe([['tier' => 'edge_usage', 'action' => 'remove', 'from' => 1, 'to' => 0]]);
});

test('delivery usage bills without any site: there is no per-site allowance', function () {
    config(['dply.edge.usage_billing.enabled' => true, 'dply.edge.usage_billing.requests_millicents_per_million' => 10_000]);

    expect(app(EdgeUsageCostCalculator::class)->estimate(new EdgeUsageTotals(requests: 5_000_000))['subtotal_cents'])->toBe(50);
});

test('the forecast runs every usage kind out to the period end', function () {
    $state = DesiredBillingState::fromPlanAndUsage(
        plan: ['key' => 'pro', 'label' => 'Pro', 'price_cents' => 2000],
        edgeUsageEstimate: ['period_start' => '2026-09-05'],
        usage: ['data' => 100],
    );

    $forecast = app(BillingForecastCalculator::class)->calculate($state, 'month', null, Carbon::parse('2026-09-14'));

    // 10 of 30 days → $1 of data usage runs out to $3.
    expect($forecast['edge_usage_mtd_cents'])->toBe(100)
        ->and($forecast['projected_edge_usage_cents'])->toBe(300)
        ->and($forecast['projected_month_end_cents'])->toBe(2300)
        ->and($forecast['period_end'])->toBe('2026-10-05');
});

test('owners are emailed at 50, 80 and 100 percent of the usage alert, each once per period', function () {
    Notification::fake();
    Subscription::factory()->withPrice('price_tier_pro')->active()->create(['organization_id' => $this->org->id]);
    $org = $this->org->fresh();
    $state = fn (int $cents, string $period = '2026-09-05') => DesiredBillingState::fromPlanAndUsage(
        plan: ['key' => 'pro', 'label' => 'Pro', 'price_cents' => 2000],
        edgeUsageEstimate: ['period_start' => $period],
        usage: ['data' => $cents],
    );
    $alerts = app(UsageAlerts::class);

    // Default limit: 2 × $20 = $40.
    expect($alerts->check($org, $state(1000)))->toBeNull()
        ->and($alerts->check($org, $state(2100)))->toBe(50)
        ->and($alerts->check($org, $state(2200)))->toBeNull()
        ->and($alerts->check($org, $state(4100)))->toBe(100)
        ->and($alerts->check($org, $state(4200)))->toBeNull()
        ->and($alerts->check($org, $state(2100, '2026-10-05')))->toBe(50);

    $org->forceFill(['usage_alert_cents' => 10_000])->save();
    expect($alerts->check($org, $state(8000, '2026-10-05')))->toBe(80);
    Notification::assertSentTimes(UsageThresholdNotice::class, 4);
});

test('the renewal invoice lists usage by category, then the included credit as a negative line', function () {
    config(['subscription.standard.stripe.tier_starter' => 'price_tier_starter']);
    $this->stripe->subscription['items']['data'][0]['price']['id'] = 'price_tier_starter';
    // Per day at cost: 1B rows read $1, 1M written $1, 1M queue ops $0.40;
    // storage is the 1 GB peak once ($0.75). Two days: $5.55 of cost.
    dataUsageOn($this->org, '2026-08-05');
    dataUsageOn($this->org, '2026-09-04');

    stripeEvent('invoice.created', renewalInvoice());

    $items = $this->stripe->items();
    $data = UsagePrice::cents(2 * 100_000 + 2 * 100_000 + 75_000 + 2 * 40_000);
    expect($items)->toHaveCount(2)
        ->and($items[0]['metadata']['dply_usage_line'])->toBe('data')
        ->and($items[0]['amount'])->toBe($data)
        ->and($items[1]['metadata']['dply_usage_line'])->toBe('credit')
        ->and($items[1]['description'])->toContain('Included usage credit')
        // Starter includes $5: the credit is min($5, usage).
        ->and($items[1]['amount'])->toBe(-min(500, $data));
    expect(DB::table('billing_usage_charges')->value('cents'))->toBe(max(0, $data - 500));
});
