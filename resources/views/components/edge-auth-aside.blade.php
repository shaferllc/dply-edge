@props(['variant' => 'default'])

{{--
    The panel beside the auth form, in the "Terminal" identity.

    Not <x-auth-aside>: that one is brand-* and still speaks about servers and
    provisioning. Each variant answers the question someone actually has on
    THAT screen rather than repeating a generic pitch.
--}}
@php
    $copy = match ($variant) {
        'register' => [
            'kicker' => 'NEW_ACCOUNT',
            'title' => __('Your first site is about four minutes away.'),
            'body' => __('Create the account, connect a repo, and we build it. No card up front, and nothing to install.'),
            'trace' => [
                ['✓', __('Connect a Git repository')],
                ['✓', __('We detect the framework and build command')],
                ['✓', __('HTTPS hostname before the build finishes')],
            ],
        ],
        'forgot-password' => [
            'kicker' => 'RESET',
            'title' => __('We will email you a link.'),
            'body' => __('The link is single-use and expires in sixty minutes. Your sites keep serving throughout — a password reset never touches a deployment.'),
            'trace' => null,
        ],
        'reset-password' => [
            'kicker' => 'RESET',
            'title' => __('Pick something you have not used elsewhere.'),
            'body' => __('Changing your password signs out your other sessions. API tokens and CLI logins are unaffected.'),
            'trace' => null,
        ],
        'two-factor' => [
            'kicker' => 'TWO_FACTOR',
            'title' => __('One more step.'),
            'body' => __('Enter the six-digit code from your authenticator app, or use a recovery code if you have lost the device.'),
            'trace' => null,
        ],
        'verify-email' => [
            'kicker' => 'VERIFY',
            'title' => __('Check your inbox.'),
            'body' => __('Verifying proves the address can receive deploy and alert notifications — which is the point of having it.'),
            'trace' => null,
        ],
        'confirm-password' => [
            'kicker' => 'CONFIRM',
            'title' => __('This one is sensitive.'),
            'body' => __('You are about to change something that affects access or billing, so we ask for the password again.'),
            'trace' => null,
        ],
        default => [
            'kicker' => 'SIGN_IN',
            'title' => __('Push a repo. Get a site on the edge.'),
            'body' => __('Static, hybrid or Worker SSR — built in a clean container and published on Dply Edge.'),
            'trace' => [
                ['✓', __('clone').' <span class="text-edge-faint">4.2s</span>'],
                ['✓', __('build').' <span class="text-edge-faint">38.1s</span>'],
                ['✓', __('publish → r2').' <span class="text-edge-faint">2.4s</span>'],
            ],
        ],
    };
@endphp

<aside {{ $attributes->merge(['class' => 'border border-edge-line bg-edge-panel']) }}>
    <div class="border-b border-edge-line px-5 py-2.5">
        <p class="font-terminal text-[11px] tracking-[0.12em] text-edge-lime">{{ $copy['kicker'] }}</p>
    </div>

    <div class="px-5 py-6">
        <h2 class="text-xl font-bold leading-tight tracking-[-0.025em] text-edge-text">{{ $copy['title'] }}</h2>
        <p class="mt-3 text-sm leading-6 text-edge-mute">{{ $copy['body'] }}</p>

        @if ($copy['trace'])
            <div class="font-terminal mt-6 space-y-1.5 border-t border-edge-line pt-5 text-xs leading-5 text-edge-dim">
                @foreach ($copy['trace'] as [$mark, $line])
                    <p><span class="text-edge-lime">{{ $mark }}</span> {!! $line !!}</p>
                @endforeach
            </div>
        @endif
    </div>
</aside>
