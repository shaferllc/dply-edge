<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Billing\EdgeUsageCostCalculatorTest;

use App\Modules\Billing\Services\EdgeUsageCostCalculator;
use App\Modules\Billing\Services\EdgeUsageTotals;
use Illuminate\Support\Facades\Config;

beforeEach(function () {
    Config::set('dply.edge.usage_billing.enabled', true);
    Config::set('dply.edge.usage_billing.margin_percent', 0);
    Config::set('dply.edge.usage_billing.requests_millicents_per_million', 50_000);
    Config::set('dply.edge.usage_billing.egress_millicents_per_gb', 5_000);
    Config::set('dply.edge.usage_billing.r2_storage_millicents_per_gb_month', 3_000);

    $this->calculator = app(EdgeUsageCostCalculator::class);
});

test('returns zero when usage billing disabled', function () {
    Config::set('dply.edge.usage_billing.enabled', false);

    expect($this->calculator->estimate(new EdgeUsageTotals(requests: 5_000_000))['subtotal_cents'])->toBe(0);
});

test('every request and byte bills: there are no per-site allowances', function () {
    $estimate = $this->calculator->estimate(new EdgeUsageTotals(requests: 2_500_000, bytesEgress: 15 * 1024 ** 3));

    expect($estimate['billable_requests'])->toBe(2_500_000)
        ->and($estimate['billable_bytes_egress'])->toBe(15 * 1024 ** 3)
        // 2.5M requests => $1.25 + 15 GB => $0.75
        ->and($estimate['subtotal_cents'])->toBe(200)
        ->and($estimate)->not->toHaveKey('included_requests');
});

test('the margin is applied once, by UsagePrice, and rounded to the nearest cent', function () {
    Config::set('dply.edge.usage_billing.margin_percent', 25);

    // 1M requests @ $0.50 cost = 50 cents, +25% = 62.5 → 63 cents
    expect($this->calculator->estimate(new EdgeUsageTotals(requests: 1_000_000))['subtotal_cents'])->toBe(63);
});
