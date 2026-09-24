<?php

declare(strict_types=1);

use App\Modules\SourceControl\Services\DefaultBranchResolver;

test('it parses the default branch, branches, and tags from ls-remote', function () {
    $refs = app(DefaultBranchResolver::class)->parseLsRemoteOutput(<<<'OUT'
ref: refs/heads/development	HEAD
aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa	HEAD
bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb	refs/heads/development
cccccccccccccccccccccccccccccccccccccccc	refs/heads/main
dddddddddddddddddddddddddddddddddddddddd	refs/tags/v25.07
eeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeee	refs/tags/v25.07^{}
OUT);

    expect($refs['default'])->toBe('development')
        ->and($refs['branches'])->toBe(['development', 'main'])
        ->and($refs['tags'])->toBe(['v25.07']);
});
