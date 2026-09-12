<?php

namespace Tests\Unit\ApiTokenDeployerTest;

use App\Models\ApiToken;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function deployerToken(array $abilities): ApiToken
{
    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $org->users()->attach($user->id, ['role' => 'deployer']);

    $token = new ApiToken(['abilities' => $abilities]);
    $token->setRelation('user', $user);
    $token->setRelation('organization', $org);

    return $token;
}

test('deployer is capped to the allowlist even with a star token', function () {
    $token = deployerToken(['*']);

    expect($token->allows('commands.run'))->toBeFalse()
        ->and($token->allows('edge.write'))->toBeFalse()
        ->and($token->allows('sites.read'))->toBeTrue();
});

// T-015: the allowlist used to hold only VM abilities, so a deployer's
// `dply login` token was refused on every /api/v1/edge/* route.
test('deployer can deploy edge sites', function () {
    $token = deployerToken(['edge.read', 'edge.deploy', 'edge.env.read']);

    expect($token->allows('edge.read'))->toBeTrue()
        ->and($token->allows('edge.deploy'))->toBeTrue()
        ->and($token->allows('edge.env.read'))->toBeTrue();
});

test('deployer allowlist covers every scope the device flow grants deployers', function () {
    $granted = config('cli.device_flow_role_caps.deployer', []);

    expect(array_values(array_diff($granted, ApiToken::deployerApiAllowlist())))->toBe([]);
});
