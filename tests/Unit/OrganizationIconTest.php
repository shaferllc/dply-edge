<?php

declare(strict_types=1);

namespace Tests\Unit\OrganizationIconTest;

use App\Models\Organization;

test('an org without an icon (null or empty path) has no icon url', function () {
    foreach ([null, ''] as $path) {
        $org = new Organization;
        $org->icon_path = $path;

        // A null path reached the R2 disk and 500'd the org pages in a container.
        expect($org->hasIcon())->toBeFalse()->and($org->iconUrl())->toBeNull();
    }
});
