<?php

namespace Tests\Feature\Auth\RegistrationTest;

use App\Http\Middleware\RedirectGuestsToComingSoon;
use App\Livewire\Auth\Register;
use App\Livewire\Legal\AcceptTerms;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

test('registration screen can be rendered', function () {
    // Bypass RedirectGuestsToComingSoon — non-local environments
    // (incl. testing) bounce guest traffic to /coming-soon by default.
    $response = $this->withoutMiddleware([RedirectGuestsToComingSoon::class])
        ->get('/register');

    $response->assertStatus(200);
});

test('new users can register', function () {
    Livewire::test(Register::class)
        ->set('form.name', 'Test User')
        ->set('form.email', 'test@example.com')
        ->set('form.password', 'password')
        ->set('form.password_confirmation', 'password')
        ->set('form.terms', true)
        ->call('submit')
        ->assertRedirect(route('verification.notice', absolute: false));

    $this->assertAuthenticated();
});

test('registration creates a default workspace organization', function () {
    Livewire::test(Register::class)
        ->set('form.name', 'Test User')
        ->set('form.email', 'test@example.com')
        ->set('form.password', 'password')
        ->set('form.password_confirmation', 'password')
        ->set('form.terms', true)
        ->call('submit');

    $user = auth()->user();

    expect($user)->not->toBeNull();
    $this->assertDatabaseHas('organizations', [
        'name' => "Test User's Workspace",
    ]);

    $org = Organization::query()->where('name', "Test User's Workspace")->first();
    expect($org)->not->toBeNull();
    expect($org->hasMember($user))->toBeTrue();
    expect(session('current_organization_id'))->toBe($org->id);
});

test('registration needs the terms accepted, and records the version', function () {
    Livewire::test(Register::class)
        ->set('form.name', 'Test User')
        ->set('form.email', 'terms@example.com')
        ->set('form.password', 'password')
        ->set('form.password_confirmation', 'password')
        ->call('submit')
        ->assertHasErrors('form.terms');
    $this->assertGuest();

    Livewire::test(Register::class)
        ->set('form.name', 'Test User')
        ->set('form.email', 'terms@example.com')
        ->set('form.password', 'password')
        ->set('form.password_confirmation', 'password')
        ->set('form.terms', true)
        ->call('submit');

    expect(auth()->user()->terms_version)->toBe(config('legal.version'))
        ->and(auth()->user()->terms_accepted_at)->not->toBeNull();
});

test('a user on an older version accepts the current terms before using the app', function () {
    $user = User::factory()->create(['terms_version' => null, 'terms_accepted_at' => null]);

    $this->actingAs($user)->get(route('dashboard'))->assertRedirect(route('legal.accept'));
    // Legal pages, JSON and Livewire updates are never redirected.
    $this->actingAs($user)->get(route('legal.terms'))->assertOk();
    expect($this->actingAs($user)->getJson(route('dashboard'))->status())->not->toBe(302);

    Livewire::actingAs($user)->test(AcceptTerms::class)
        ->call('accept')->assertHasErrors('agree')
        ->set('agree', true)->call('accept')->assertRedirect();
    expect($user->fresh()->acceptedCurrentTerms())->toBeTrue();
    // No longer sent to the accept page (the dashboard's own org check may still answer).
    expect($this->actingAs($user->fresh())->get(route('dashboard'))->headers->get('Location'))->not->toBe(route('legal.accept'));
});
