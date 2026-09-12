<?php

declare(strict_types=1);

namespace Tests\Feature\Api\BillingApiTest;

use App\Models\ApiToken;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Pennant\Feature;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Feature::define('global.billing_enabled', fn () => true);
});

/**
 * @return array{0: Organization, 1: string}
 */
function billingToken(array $abilities, string $role = 'owner'): array
{
    $org = Organization::factory()->create(['name' => 'Billing Org']);
    $user = User::factory()->create();
    $org->users()->attach($user->id, ['role' => $role]);

    ['plaintext' => $plain] = ApiToken::createToken($user, $org, 'billing-cli', null, $abilities);

    return [$org, $plain];
}

test('billing show returns plan summary for org admins', function (): void {
    [$org, $plain] = billingToken(['billing.read']);

    $this->getJson('/api/v1/billing', [
        'Authorization' => 'Bearer '.$plain,
    ])
        ->assertOk()
        ->assertJsonPath('data.organization_id', (string) $org->id)
        ->assertJsonStructure([
            'data' => [
                'summary',
                'plan' => ['key', 'label', 'price_cents'],
                'monthly_total_cents',
                'counts' => ['edge'],
                'subscription',
            ],
        ]);
});

test('billing breakdown returns line items', function (): void {
    [, $plain] = billingToken(['billing.read']);

    $this->getJson('/api/v1/billing/breakdown', [
        'Authorization' => 'Bearer '.$plain,
    ])
        ->assertOk()
        ->assertJsonStructure([
            'data' => [
                'monthly_total_cents',
                'categories',
                'line_items',
            ],
        ]);
});

test('billing invoices returns invoice list payload', function (): void {
    [, $plain] = billingToken(['billing.read']);

    $this->getJson('/api/v1/billing/invoices', [
        'Authorization' => 'Bearer '.$plain,
    ])
        ->assertOk()
        ->assertJsonStructure(['data' => ['invoices']]);
});

test('billing endpoints require billing.read ability', function (): void {
    [, $plain] = billingToken(['servers.read']);

    $this->getJson('/api/v1/billing', [
        'Authorization' => 'Bearer '.$plain,
    ])->assertForbidden();
});

test('billing endpoints require org admin role', function (): void {
    [, $plain] = billingToken(['billing.read'], 'member');

    $this->getJson('/api/v1/billing', [
        'Authorization' => 'Bearer '.$plain,
    ])->assertForbidden();
});

test('billing endpoints respect billing feature flag', function (): void {
    Feature::define('global.billing_enabled', fn () => false);
    [, $plain] = billingToken(['billing.read']);

    $this->getJson('/api/v1/billing', [
        'Authorization' => 'Bearer '.$plain,
    ])->assertForbidden();
});
