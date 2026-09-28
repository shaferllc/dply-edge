<?php

declare(strict_types=1);

use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('admins see a needs-attention item when no notification channel exists', function () {
    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $org->users()->attach($user->id, ['role' => 'owner']);

    $this->actingAs($user)->get(route('organizations.show', $org))
        ->assertOk()
        ->assertSee('Needs attention')
        ->assertSee('Nobody hears about failures');
});

test('a stale invite is flagged, a fresh one is not', function () {
    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $org->users()->attach($user->id, ['role' => 'owner']);
    $org->invitations()->create([
        'email' => 'fresh@example.com',
        'role' => 'member',
        'token' => 'fresh-token',
        'invited_by' => $user->id,
        'expires_at' => now()->addDays(6),
    ]);

    $this->actingAs($user)->get(route('organizations.show', $org))
        ->assertOk()
        ->assertDontSee('fresh@example.com');
});
