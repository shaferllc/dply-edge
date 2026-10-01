<?php

declare(strict_types=1);

use Livewire\Mechanisms\HandleRequests\HandleRequests;

test('Livewire posts to a fixed update URL that does not depend on APP_KEY', function () {
    // An image that cached routes without APP_KEY 404'd every Livewire request (2026-10-01).
    expect(app(HandleRequests::class)->getUpdateUri())->toBe('/livewire/update');
});
