<?php

declare(strict_types=1);

namespace Tests\Feature\EdgeOnlyCopyTest;

use App\Livewire\Auth\Register;
use App\Livewire\Organizations\Teams;
use App\Models\BetaInvitation;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

/*
| Copy that still described the removed VM platform (free servers, server
| scoping, SSH abilities, files the export never wrote).
*/

test('the invited register page offers the real trial, not free servers', function () {
    $invite = BetaInvitation::issue('new@example.com');

    Livewire::withQueryParams(['invite' => $invite->token])->test(Register::class)
        ->assertSee('5-day Pro trial')
        ->assertDontSee('cloud servers');
});

test('the teams page says teams group people, not scope servers', function () {
    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $org->users()->attach($user->id, ['role' => 'owner']);

    Livewire::actingAs($user)->test(Teams::class, ['organization' => $org])
        ->assertSee('route notifications')
        ->assertDontSee('scope servers');
});

test('the CLI page shows commands and a CI snippet that exist', function () {
    $user = User::factory()->create();
    Organization::factory()->create()->users()->attach($user->id, ['role' => 'owner']);

    $html = $this->actingAs($user)->get(route('profile.cli'))->assertOk()->getContent();

    expect($html)->toContain('bash -s -- --no-login')
        ->not->toContain('bash -s -- --no-shell')
        ->not->toContain('--idempotency-key')
        ->not->toContain('dply server list')
        ->not->toContain('commands.run')
        ->toContain('edge.deploy');
});

test('the compliance export README lists only the files in the archive', function () {
    $user = User::factory()->create();
    $org = Organization::factory()->create(['comped_until' => now()->addYear()]);
    $org->users()->attach($user->id, ['role' => 'owner']);

    $response = $this->actingAs($user)->get(route('organizations.compliance-export', $org))->assertOk();

    $zip = new \ZipArchive;
    $zip->open($response->baseResponse->getFile()->getPathname());
    $readme = (string) $zip->getFromName('README.txt');
    $names = array_map(fn (int $i): string => (string) $zip->getNameIndex($i), range(0, $zip->numFiles - 1));
    $zip->close();

    expect($readme)->not->toContain('deploys.csv')->not->toContain('certificates.csv')->not->toContain('full history')
        ->toContain('365 days');
    foreach (['audit_log.csv', 'edge_access_rules.csv'] as $file) {
        expect($names)->toContain($file)->and($readme)->toContain($file);
    }
});
