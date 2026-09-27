<?php

declare(strict_types=1);

use App\Models\SocialAccount;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as SocialiteUser;

uses(RefreshDatabase::class);

function fakeGithubUser(User $user): void
{
    $oauth = (new SocialiteUser)->setRaw([])->map([
        'id' => 'gh-123', 'nickname' => 'octo', 'name' => 'Octo', 'email' => $user->email,
    ])->setToken('token');
    Socialite::shouldReceive('driver->user')->andReturn($oauth);
    SocialAccount::query()->create(['user_id' => $user->id, 'provider' => 'github', 'provider_id' => 'gh-123', 'access_token' => 'old']);
}

test('signing in with GitHub still asks for the two-factor code when 2FA is on', function () {
    $user = User::factory()->create();
    $user->forceFill(['two_factor_secret' => encrypt('secret'), 'two_factor_confirmed_at' => now()])->save();
    fakeGithubUser($user);

    $this->get('/auth/github/callback')->assertRedirect(route('two-factor.login'));

    $this->assertGuest();
    expect(session('login.id'))->toBe($user->id);
});

test('signing in with GitHub without 2FA logs straight in', function () {
    $user = User::factory()->create();
    fakeGithubUser($user);

    $this->get('/auth/github/callback')->assertRedirect();

    $this->assertAuthenticatedAs($user);
});
