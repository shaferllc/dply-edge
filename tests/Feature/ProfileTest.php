<?php

namespace Tests\Feature\ProfileTest;

use App\Livewire\Profile\DeleteAccount;
use App\Livewire\Settings\Hub as SettingsHub;
use App\Models\Organization;
use App\Models\User;
use App\Modules\Billing\Livewire\Show as BillingShow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

uses(RefreshDatabase::class);

test('profile page is displayed', function () {
    $user = User::factory()->create();

    $response = $this
        ->actingAs($user)
        ->get('/settings/profile');

    $response->assertOk();
});

test('profile edit shows dashboard profile breadcrumb', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('settings.profile'))
        ->assertOk()
        ->assertSeeText('Dashboard')
        ->assertSeeText('Profile')
        ->assertSee('aria-current="page"', false);
});

test('security page is displayed', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get('/profile/security')
        ->assertOk()
        ->assertSee('Security', false);
});

test('profile information can be updated', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test(SettingsHub::class)
        ->set('profileForm.name', 'Test User')
        ->set('profileForm.email', 'test@example.com')
        ->call('updateProfile')
        ->assertHasNoErrors()
        ->assertDispatched('notify', message: __('Profile details saved.'), type: 'success');

    $user->refresh();

    expect($user->name)->toBe('Test User');
    expect($user->email)->toBe('test@example.com');
    expect($user->email_verified_at)->toBeNull();
});

test('email verification status is unchanged when the email address is unchanged', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test(SettingsHub::class)
        ->set('profileForm.name', 'Test User')
        ->set('profileForm.email', $user->email)
        ->call('updateProfile')
        ->assertHasNoErrors();

    expect($user->refresh()->email_verified_at)->not->toBeNull();
});

test('billing details can be updated', function () {
    Http::fake([
        'ec.europa.eu/*' => Http::response(
            '<?xml version="1.0"?><soap:Envelope xmlns:soap="http://schemas.xmlsoap.org/soap/envelope/">'
            .'<soap:Body><checkVatResponse><valid>true</valid></checkVatResponse></soap:Body></soap:Envelope>',
            200,
            ['Content-Type' => 'text/xml']
        ),
    ]);

    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $org->users()->attach($user->id, ['role' => 'owner']);

    // Billing details are now organization-scoped (Billing module's Show page).
    Livewire::actingAs($user)
        ->test(BillingShow::class, ['organization' => $org])
        ->set('invoice_email', 'billing@example.com')
        ->set('vat_number', 'NL123456789B01')
        ->set('billing_currency', 'EUR')
        ->set('billing_details', "Acme Co.\n123 Main St")
        ->call('saveBillingDetails')
        ->assertHasNoErrors()
        ->assertDispatched('notify', message: __('Billing details saved.'), type: 'success');

    $org->refresh();

    expect($org->invoice_email)->toBe('billing@example.com');
    expect($org->vat_number)->toBe('NL123456789B01');
    expect($org->billing_currency)->toBe('EUR');
    expect($org->billing_details)->toBe("Acme Co.\n123 Main St");
});

test('billing vat number must match supported format', function () {
    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $org->users()->attach($user->id, ['role' => 'owner']);

    Livewire::actingAs($user)
        ->test(BillingShow::class, ['organization' => $org])
        ->set('vat_number', 'weewrewerwrewrweew')
        ->call('saveBillingDetails')
        ->assertHasErrors(['vat_number']);

    expect($org->refresh()->vat_number)->toBeNull();
});

test('delete account page is displayed for authenticated user', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->get('/profile/delete-account');

    $response->assertOk();
});

test('delete account page redirects guests', function () {
    $response = $this->get('/profile/delete-account');

    $response->assertRedirect(route('login', absolute: false));
});

test('user can delete their account', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test(DeleteAccount::class)
        ->set('delete_password', 'password')
        ->call('deleteAccount')
        ->assertRedirect('/');

    $this->assertGuest();
    expect($user->fresh())->toBeNull();
});

test('correct password must be provided to delete account', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test(DeleteAccount::class)
        ->set('delete_password', 'wrong-password')
        ->call('deleteAccount')
        ->assertHasErrors(['delete_password']);

    expect($user->fresh())->not->toBeNull();
});
