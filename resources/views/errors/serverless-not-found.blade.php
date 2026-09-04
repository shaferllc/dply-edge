{{--
    Shown when a request arrives on a dply serverless hostname (or /fn/{slug})
    that no function answers at.

    Deliberately standalone rather than extending errors.layout: that layout
    renders dply's own site header and nav, which is the wrong thing to put in
    front of someone who typed an app's address and has never heard of dply.
    It is also self-contained — no Vite assets, no Livewire — so it still
    renders correctly on a hostname served before the app's asset pipeline is
    warm, which is exactly when this page tends to be hit.

    Still returns 404: the resource genuinely is not there, and search engines
    and uptime checks should be told so. Only the page changed.
--}}
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>{{ __('No app at this address') }}</title>
    <style>
        :root { color-scheme: light dark; }
        * { box-sizing: border-box; }
        body {
            margin: 0;
            min-height: 100vh;
            min-height: 100dvh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 1.5rem;
            background: #FAF7F0;
            color: #1B2A24;
            font-family: 'Space Grotesk', ui-sans-serif, system-ui, -apple-system, 'Segoe UI', sans-serif;
            line-height: 1.6;
        }
        .card {
            width: 100%;
            max-width: 34rem;
            background: rgba(255, 255, 255, 0.9);
            border: 1px solid rgba(27, 42, 36, 0.1);
            border-radius: 1.25rem;
            padding: 2.5rem 2rem;
            text-align: center;
            box-shadow: 0 18px 40px -24px rgba(27, 42, 36, 0.45);
        }
        .badge {
            display: inline-block;
            font-size: 0.7rem;
            letter-spacing: 0.08em;
            text-transform: uppercase;
            font-weight: 600;
            color: #5C6F66;
            border: 1px solid rgba(27, 42, 36, 0.14);
            border-radius: 999px;
            padding: 0.25rem 0.7rem;
            margin-bottom: 1.25rem;
        }
        h1 { font-size: 1.6rem; line-height: 1.25; margin: 0 0 0.75rem; font-weight: 700; }
        p { margin: 0 0 1rem; color: #5C6F66; }
        .host {
            display: block;
            font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
            font-size: 0.9rem;
            color: #1B2A24;
            background: rgba(27, 42, 36, 0.05);
            border-radius: 0.6rem;
            padding: 0.6rem 0.85rem;
            margin: 0 0 1.5rem;
            word-break: break-all;
        }
        ul { text-align: left; margin: 0 auto 1.75rem; padding-left: 1.1rem; color: #5C6F66; max-width: 26rem; }
        li { margin-bottom: 0.35rem; }
        a.cta {
            display: inline-block;
            background: #1B2A24;
            color: #FAF7F0;
            text-decoration: none;
            font-weight: 600;
            border-radius: 0.7rem;
            padding: 0.65rem 1.3rem;
        }
        a.cta:hover { background: #2C4239; }
        .footer { margin-top: 1.5rem; font-size: 0.8rem; color: #8A9992; }
        @media (prefers-color-scheme: dark) {
            body { background: #121A16; color: #ECF1EE; }
            .card { background: rgba(23, 34, 29, 0.92); border-color: rgba(236, 241, 238, 0.12); }
            p, ul, .badge { color: #9DB0A6; }
            .host { color: #ECF1EE; background: rgba(236, 241, 238, 0.08); }
            a.cta { background: #ECF1EE; color: #121A16; }
            a.cta:hover { background: #FFFFFF; }
        }
    </style>
</head>
<body>
    <main class="card">
        <span class="badge">{{ __('Nothing deployed here') }}</span>

        <h1>{{ __('There’s no app at this address') }}</h1>

        <span class="host">{{ $host }}</span>

        <p>{{ __('This is a :brand serverless address, but no function is answering at it right now.', ['brand' => $brand]) }}</p>

        <ul>
            <li>{{ __('The app may have been deleted or renamed.') }}</li>
            <li>{{ __('It may never have finished its first deploy.') }}</li>
            <li>{{ __('The address may simply be mistyped.') }}</li>
        </ul>

        @if ($isOwnerHint)
            <p>{{ __('If this is yours, check the function’s Deployments tab — a failed first deploy leaves the address reserved but unanswered.') }}</p>
        @endif

        <a class="cta" href="{{ $homeUrl }}">{{ __('Go to :brand', ['brand' => $brand]) }}</a>

        <p class="footer">{{ __('Served by :brand', ['brand' => $brand]) }}</p>
    </main>
</body>
</html>
