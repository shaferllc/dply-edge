<?php

declare(strict_types=1);

namespace Tests\Feature\LegalPagesTest;

use App\Http\Middleware\RedirectGuestsToComingSoon;

test('the legal pages render with the operator, version and one subprocessor list', function (string $route) {
    $this->withoutMiddleware([RedirectGuestsToComingSoon::class])
        ->get(route($route))
        ->assertOk()
        ->assertSee(config('legal.entity'))
        ->assertSee(config('legal.version'));
})->with(['legal.terms', 'legal.privacy', 'legal.acceptable-use', 'legal.dpa']);

test('the DPA and the compliance page list the same subprocessors, from config', function () {
    foreach (['legal.dpa', 'compliance'] as $route) {
        $page = $this->withoutMiddleware([RedirectGuestsToComingSoon::class])->get(route($route))->assertOk();
        foreach (config('legal.subprocessors') as [$name]) {
            $page->assertSee($name);
        }
    }
});
