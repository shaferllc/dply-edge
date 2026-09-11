<?php

namespace Tests\Unit\ApiTokenPermissionsConfigTest;

use App\Models\ApiToken;

test('deployer allowlist is subset of catalog or star', function () {
    $catalog = array_flip(ApiToken::catalogAbilities());

    foreach (ApiToken::deployerApiAllowlist() as $ab) {
        expect($catalog)->toHaveKey($ab);
    }
});

test('http route abilities reference catalog', function () {
    $routes = config('api_token_permissions.http_route_abilities', []);

    foreach ($routes as $key => $ability) {
        expect(ApiToken::abilityIsAllowedForStorage($ability))->toBeTrue();
    }
});
