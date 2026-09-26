<?php

namespace Tests\Feature\Marketing\ComingSoonSignupTest;

use App\Livewire\Marketing\ComingSoonSignup;
use App\Models\ComingSoonSignup as ComingSoonSignupRecord;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

test('coming soon page can be rendered', function () {
    $this->get(route('coming-soon'))
        ->assertOk()
        ->assertSee('Request early access')
        ->assertSee(route('login'));
});

test('guest public pages redirect to coming soon', function () {
    // Gate is off for localhost unless forced — tests run on localhost.
    config(['dply.coming_soon' => true]);

    $this->get('/')
        ->assertRedirect(route('coming-soon', absolute: false));

    $this->get(route('pricing'))
        ->assertRedirect(route('coming-soon', absolute: false));

    $this->get(route('features'))
        ->assertRedirect(route('coming-soon', absolute: false));

    $this->get(route('register', absolute: false))
        ->assertRedirect(route('coming-soon', absolute: false));
});

test('login page stays available for guests', function () {
    $this->get(route('login'))
        ->assertOk();
});

test('guest can join the coming soon list', function () {
    Livewire::test(ComingSoonSignup::class)
        ->set('email', 'Founder@Example.com')
        ->call('submit')
        ->assertSet('submitted', true)
        ->assertSet('alreadySubscribed', false)
        ->assertSee('on the list, thanks');

    $this->assertDatabaseHas('coming_soon_signups', [
        'email' => 'founder@example.com',
        'source' => 'coming-soon',
    ]);
});

test('duplicate email submissions are handled gracefully', function () {
    ComingSoonSignupRecord::query()->create([
        'email' => 'founder@example.com',
        'source' => 'coming-soon',
    ]);

    Livewire::test(ComingSoonSignup::class)
        ->set('email', 'Founder@Example.com')
        ->call('submit')
        ->assertSet('submitted', true)
        ->assertSet('alreadySubscribed', true)
        ->assertSee('already on the list');

    $this->assertDatabaseCount('coming_soon_signups', 1);
});
