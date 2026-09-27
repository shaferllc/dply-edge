<?php

namespace Tests\Feature\Auth\RegistrationTest;

use App\Http\Middleware\RedirectGuestsToComingSoon;
use App\Livewire\Auth\Register;
use App\Models\Organization;
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
