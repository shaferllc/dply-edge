<?php

namespace Tests\Feature\ExampleTest;

// use Illuminate\Foundation\Testing\RefreshDatabase;
use App\Http\Middleware\RedirectGuestsToComingSoon;

test('the application returns a successful response', function () {
    // RedirectGuestsToComingSoon redirects '/' for non-local hosts;
    // bypass it here since this test asserts the homepage renders.
    $response = $this->withoutMiddleware([RedirectGuestsToComingSoon::class])
        ->get('/');

    $response->assertStatus(200);
});

test('error pages render in the marketing look with their own copy', function () {
    $this->get('/definitely-not-a-real-dply-route-'.uniqid())
        ->assertNotFound()
        ->assertSee(__('Error :code', ['code' => 404]))
        ->assertSee(__('Page not found'))
        ->assertSee('HTTP/2 404');

    foreach ([400, 401, 403, 419, 429, 500, 502, 503, 504] as $code) {
        expect(view('errors.'.$code)->render())->toContain('HTTP/2 '.$code);
    }
    expect(view('errors.minimal', ['code' => 418, 'title' => 'Teapot', 'message' => 'Short and stout.'])->render())
        ->toContain('Teapot')->toContain('Short and stout.');
});
