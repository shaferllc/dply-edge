{{-- Self-contained Redis-unreachable diagnostic page. Renders without
     touching Cache / Pennant / session — the layout used by other error
     pages pulls site-header + marketing-footer + Pennant feature lookups
     and would recurse on the very RedisException we're trying to surface. --}}
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ __('Cache backend unreachable') }} – {{ config('app.name', 'dply') }}</title>
    <style>
        :root { color-scheme: light dark; }
        * { box-sizing: border-box; }
        body {
            margin: 0;
            font-family: 'Space Grotesk', -apple-system, BlinkMacSystemFont, system-ui, sans-serif;
            background: #f6f2eb;
            color: #1f2421;
            min-height: 100vh;
            min-height: 100dvh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 2rem 1rem;
            line-height: 1.5;
        }
        .card {
            max-width: 720px;
            width: 100%;
            background: #fff;
            border: 1px solid rgba(31, 36, 33, 0.1);
            border-radius: 20px;
            box-shadow: 0 10px 40px rgba(31, 36, 33, 0.08);
            padding: 2rem 2.25rem;
        }
        .badge {
            display: inline-block;
            padding: 0.25rem 0.6rem;
            border-radius: 999px;
            background: #fef2f2;
            color: #991b1b;
            border: 1px solid #fecaca;
            font-size: 10px; /* text-2xs */
            line-height: 1rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.16em;
        }
        h1 { margin: 0.75rem 0 0.5rem; font-size: 1.5rem; font-weight: 600; }
        p { margin: 0.5rem 0; color: #54635a; font-size: 0.9rem; }
        .block {
            background: #f6f2eb;
            border: 1px solid rgba(31, 36, 33, 0.08);
            border-radius: 12px;
            padding: 1rem 1.1rem;
            margin: 1.25rem 0;
        }
        .block .label {
            font-size: 10px; /* text-2xs */
            line-height: 1rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.14em;
            color: #8b9a90;
            margin-bottom: 0.35rem;
        }
        code, .mono {
            font-family: 'JetBrains Mono', 'SF Mono', Menlo, ui-monospace, monospace;
            font-size: 12px; /* text-xs */
            background: rgba(31, 36, 33, 0.05);
            padding: 0.1rem 0.35rem;
            border-radius: 4px;
            color: #1f2421;
        }
        ol { margin: 0.5rem 0 0; padding-left: 1.3rem; }
        ol li { margin-bottom: 0.85rem; }
        ol li strong { color: #1f2421; }
        .stack {
            font-size: 12px; /* text-xs */
            color: #8b9a90;
            margin-top: 1.25rem;
            line-height: 1.5;
        }
        .stack code { font-size: 12px; }
    </style>
</head>
<body>
    <div class="card">
        <span class="badge">{{ __('Redis backend timed out') }}</span>
        <h1>{{ __('Dply can\'t talk to its own cache / queue backend.') }}</h1>
        <p>
            {{ __('Every page render touches Cache (Livewire snapshots, session lookups, feature flags). The configured Redis at :host::port stopped responding within the :timeout-second timeout, so the request failed instead of wedging PHP-FPM for a minute. Fix one of the items below to bring pages back.', [
                'host' => $host,
                'port' => $port,
                'timeout' => $timeout,
            ]) }}
        </p>

        <div class="block">
            <div class="label">{{ __('Underlying error') }}</div>
            <code style="word-break: break-all;">{{ $message ?: __('Connection timed out.') }}</code>
        </div>

        <div class="block">
            <div class="label">{{ __('What to check') }}</div>
            <ol>
                <li>
                    <strong>{{ __('Network reachability.') }}</strong>
                    {{ __('SSH or run `redis-cli -h :host -p :port ping` from this machine — if that hangs, the box is down, the firewall is blocking, or the host moved.', ['host' => $host, 'port' => $port]) }}
                </li>
                <li>
                    <strong>{{ __('TLS for DigitalOcean managed Redis.') }}</strong>
                    {{ __('A host on :25061 or *.db.ondigitalocean.com is TLS-only. Plain tcp:// fails at AUTH. Set REDIS_SCHEME=tls (or REDIS_URL=rediss://…) in the live .env and in the site env cache, then `php artisan config:clear`.') }}
                </li>
                <li>
                    <strong>{{ __('Use local cache during the outage.') }}</strong><br>
                    <code>CACHE_STORE=file</code>
                    <code>QUEUE_CONNECTION=sync</code><br>
                    {{ __('Then run `php artisan config:clear`. Decouples dply from the very Redis box it manages.') }}
                </li>
                <li>
                    <strong>{{ __('Tune timeouts if the link is slow but alive.') }}</strong><br>
                    <code>REDIS_TIMEOUT=5</code>
                    <code>REDIS_READ_TIMEOUT=5</code>
                </li>
            </ol>
        </div>

        <p class="stack">
            {{ __('Current config:') }}
            <code>CACHE_STORE={{ $cacheStore }}</code>
            <code>QUEUE_CONNECTION={{ $queueConnection }}</code>
            <code>REDIS_HOST={{ $host }}</code>
            <code>REDIS_PORT={{ $port }}</code>
        </p>
    </div>
</body>
</html>
