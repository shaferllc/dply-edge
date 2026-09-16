<?php

namespace Tests\Feature\Auth\PasswordResetTest;

use App\Livewire\Auth\ForgotPassword;
use App\Livewire\Auth\ResetPassword as ResetPasswordPage;
use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Contracts\Notifications\Dispatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;

uses(RefreshDatabase::class);

test('reset password link screen can be rendered', function () {
    $response = $this->get('/forgot-password');

    $response->assertStatus(200);
});

test('reset password link can be requested', function () {
    Notification::fake();

    $user = User::factory()->create();

    Livewire::test(ForgotPassword::class)
        ->set('email', $user->email)
        ->call('submit');

    Notification::assertSentTo($user, ResetPassword::class);
});

test('reset password screen can be rendered', function () {
    Notification::fake();

    $user = User::factory()->create();

    Livewire::test(ForgotPassword::class)
        ->set('email', $user->email)
        ->call('submit');

    Notification::assertSentTo($user, ResetPassword::class, function ($notification) {
        $response = $this->get('/reset-password/'.$notification->token);

        $response->assertStatus(200);

        return true;
    });
});

test('password can be reset with valid token', function () {
    Notification::fake();

    $user = User::factory()->create();

    Livewire::test(ForgotPassword::class)
        ->set('email', $user->email)
        ->call('submit');

    $token = null;
    Notification::assertSentTo($user, ResetPassword::class, function ($notification) use (&$token) {
        $token = $notification->token;

        return true;
    });

    Livewire::test(ResetPasswordPage::class, ['token' => $token])
        ->set('email', $user->email)
        ->set('password', 'password')
        ->set('password_confirmation', 'password')
        ->call('submit')
        ->assertRedirect(route('login'));
});

test('a failed reset email send shows an error on the form', function () {
    $user = User::factory()->create();
    $this->mock(Dispatcher::class)
        ->shouldReceive('send')->andThrow(new \RuntimeException('email.sending.error.email.invalid'));

    Livewire::test(ForgotPassword::class)
        ->set('email', $user->email)
        ->call('submit')
        ->assertHasErrors('email');
});

test('the cloudflare mailer prefers the email sending token over the dns token', function () {
    putenv('CLOUDFLARE_KEY=email-sending-token');
    putenv('CLOUDFLARE_API_KEY=dns-token');

    try {
        $config = require base_path('config/mail.php');
    } finally {
        putenv('CLOUDFLARE_KEY');
        putenv('CLOUDFLARE_API_KEY');
    }

    expect($config['mailers']['cloudflare']['key'])->toBe('email-sending-token');
});
