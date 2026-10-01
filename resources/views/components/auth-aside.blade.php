@props([
    'variant' => 'default',
])

@php
    $blocks = match ($variant) {
        'login' => [
            'title' => 'Welcome back',
            'lead' => 'Pick up where you left off—servers, sites, and deploys stay scoped to your organization.',
            'items' => [
                ['icon' => 'server', 'title' => 'One console', 'text' => 'Dashboard, SSH commands, and provider links without juggling tokens.'],
                ['icon' => 'shield', 'title' => 'Org-scoped access', 'text' => 'Credentials and servers belong to the team—no shared pastebins of API keys.'],
                ['icon' => 'spark', 'title' => 'Ship faster', 'text' => 'Git deploys, SSL, and workers configured next to each site.'],
            ],
        ],
        'register' => [
            'title' => 'Start in minutes',
            'lead' => 'Create an account, connect a Git repository, and deploy your first app.',
            'items' => [
                ['icon' => 'org', 'title' => 'Organizations first', 'text' => 'Billing and resources group naturally for teams and side projects.'],
                ['icon' => 'cloud', 'title' => 'Deploys from Git', 'text' => 'Static sites, SSR and Laravel, Rails or Node containers on Cloudflare’s network, with databases, storage and queues.'],
                ['icon' => 'key', 'title' => 'Vaulted secrets', 'text' => 'API tokens and deploy keys stay encrypted; teammates use the app, not raw secrets.'],
            ],
        ],
        'forgot-password' => [
            'title' => 'Account recovery',
            'lead' => 'We will email a single-use link. It expires for your security.',
            'items' => [
                ['icon' => 'mail', 'title' => 'Check spam', 'text' => 'If nothing arrives in a minute, peek at junk or promotions folders.'],
                ['icon' => 'lock', 'title' => 'Strong passwords', 'text' => 'After reset, consider a password manager and optional 2FA in profile.'],
                ['icon' => 'user', 'title' => 'Same email', 'text' => 'Use the address you registered with—aliases must match exactly.'],
            ],
        ],
        'reset-password' => [
            'title' => 'Choose a new password',
            'lead' => 'You are one step away from signing in again with fresh credentials.',
            'items' => [
                ['icon' => 'lock', 'title' => 'Unique & long', 'text' => 'Avoid reuse from other sites; length beats complexity.'],
                ['icon' => 'shield', 'title' => '2FA optional', 'text' => 'Add an authenticator from profile settings after you log in.'],
                ['icon' => 'spark', 'title' => 'Link expires', 'text' => 'If this page errors, request a new reset email from the login screen.'],
            ],
        ],
        'verify-email' => [
            'title' => 'Almost there',
            'lead' => 'Verified email keeps invitations, billing notices, and security alerts reliable.',
            'items' => [
                ['icon' => 'mail', 'title' => 'Inbox required', 'text' => 'Servers, orgs, and sensitive actions need a confirmed address.'],
                ['icon' => 'spark', 'title' => 'Resend anytime', 'text' => 'Use the button below if the link did not land the first time.'],
                ['icon' => 'shield', 'title' => 'Wrong account?', 'text' => 'Log out and register with the email you want to keep.'],
            ],
        ],
        'confirm-password' => [
            'title' => 'Sensitive action',
            'lead' => 'Re-entering your password prevents stray clicks from changing critical settings.',
            'items' => [
                ['icon' => 'shield', 'title' => 'Short detour', 'text' => 'You will return to what you were doing right after.'],
                ['icon' => 'lock', 'title' => 'Session based', 'text' => 'We may ask again after a while or on a new device.'],
                ['icon' => 'user', 'title' => 'Forgot it?', 'text' => 'Log out and use “Forgot password” from the login page.'],
            ],
        ],
        'two-factor' => [
            'title' => 'Second factor',
            'lead' => 'Enter the 6-digit code from your authenticator app, or a one-time recovery code.',
            'items' => [
                ['icon' => 'lock', 'title' => 'Time-based codes', 'text' => 'They refresh every 30 seconds—wait for the new one if it just rolled.'],
                ['icon' => 'spark', 'title' => 'Recovery codes', 'text' => 'Each backup code works once; store them somewhere safe offline.'],
                ['icon' => 'shield', 'title' => 'Lost device?', 'text' => 'Use a recovery code, then add 2FA again from profile settings.'],
            ],
        ],
        default => [
            'title' => config('app.name'),
            'lead' => 'Infrastructure operations for teams—servers, sites, and secrets in one place.',
            'items' => [
                ['icon' => 'server', 'title' => 'Control plane', 'text' => 'Provision, configure, and run commands from the browser.'],
                ['icon' => 'cloud', 'title' => 'Your clouds', 'text' => 'Link providers or SSH—everything stays under your organization.'],
                ['icon' => 'shield', 'title' => 'Built for teams', 'text' => 'Invites, audit visibility, and per-org billing as you scale.'],
            ],
        ],
    };
@endphp

<div {{ $attributes->merge(['class' => 'rounded-2xl border border-brand-ink/10 bg-brand-cream p-8 lg:p-10']) }}>
    <p class="text-xs font-semibold uppercase tracking-wider text-brand-sage">{{ config('app.name') }}</p>
    <h2 class="mt-2 text-xl font-bold tracking-tight text-brand-ink leading-snug sm:text-2xl">{{ $blocks['title'] }}</h2>
    <p class="mt-3 text-sm text-brand-moss leading-relaxed">{{ $blocks['lead'] }}</p>
    <ul class="mt-8 space-y-6">
        @foreach ($blocks['items'] as $item)
            <li class="flex gap-4">
                <span class="flex h-11 w-11 shrink-0 items-center justify-center rounded-xl bg-brand-gold/15 text-brand-forest" aria-hidden="true">
                    @switch($item['icon'])
                        @case('server')
                            <x-heroicon-o-server-stack class="h-5 w-5" />
                            @break
                        @case('shield')
                            <x-heroicon-o-shield-check class="h-5 w-5" />
                            @break
                        @case('spark')
                            <x-heroicon-o-bolt class="h-5 w-5" />
                            @break
                        @case('org')
                            <x-heroicon-o-building-office-2 class="h-5 w-5" />
                            @break
                        @case('cloud')
                            <x-heroicon-o-cloud class="h-5 w-5" />
                            @break
                        @case('key')
                            <x-heroicon-o-key class="h-5 w-5" />
                            @break
                        @case('mail')
                            <x-heroicon-o-envelope class="h-5 w-5" />
                            @break
                        @case('lock')
                            <x-heroicon-o-lock-closed class="h-5 w-5" />
                            @break
                        @case('user')
                            <x-heroicon-o-user class="h-5 w-5" />
                            @break
                        @default
                            <x-heroicon-o-information-circle class="h-5 w-5" />
                    @endswitch
                </span>
                <div>
                    <p class="font-semibold text-brand-ink text-sm">{{ $item['title'] }}</p>
                    <p class="mt-1 text-sm text-brand-moss leading-relaxed">{{ $item['text'] }}</p>
                </div>
            </li>
        @endforeach
    </ul>
    <p class="mt-8 pt-6 border-t border-brand-ink/10 text-xs text-brand-moss">
        Questions? See <a href="{{ route('features') }}" class="font-medium text-brand-sage hover:text-brand-forest underline decoration-brand-sage/40 underline-offset-2">features</a>
        or <a href="{{ route('pricing') }}" class="font-medium text-brand-sage hover:text-brand-forest underline decoration-brand-sage/40 underline-offset-2">pricing</a>.
    </p>
</div>
