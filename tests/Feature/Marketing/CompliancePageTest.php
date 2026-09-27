<?php

namespace Tests\Feature\Marketing\CompliancePageTest;

test('compliance page renders for guests with the security contact', function () {
    $this->get(route('compliance'))
        ->assertOk()
        ->assertSee('Security &amp; compliance', false)
        ->assertSee('security@dply.io')
        ->assertSee('Responsible disclosure')
        ->assertSee('SOC 2')
        ->assertSee(route('compliance'));   // footer link
});

test('/security redirects to the compliance page', function () {
    $this->get('/security')->assertRedirect('/compliance')->assertStatus(301);
});

test('security.txt carries the configured contact at both paths', function (string $path) {
    config(['dply.security_email' => 'sec@example.test']);

    $response = $this->get($path)->assertOk();

    expect($response->headers->get('Content-Type'))->toStartWith('text/plain')
        ->and($response->getContent())
        ->toContain('Contact: mailto:sec@example.test')
        ->toContain('Expires: ')
        ->toContain('Policy: '.route('compliance').'#disclosure');
})->with(['/.well-known/security.txt', '/security.txt']);

test('security.txt stays reachable behind the coming-soon gate', function () {
    config(['dply.coming_soon' => true]);

    $this->get('/.well-known/security.txt')->assertOk();
});
