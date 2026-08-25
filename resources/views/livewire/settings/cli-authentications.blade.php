<div>
    @push('breadcrumbs')
        <x-breadcrumb-trail doc-contextual :items="[
            ['label' => __('Dashboard'), 'href' => route('dashboard'), 'icon' => 'home'],
            ['label' => __('Profile'), 'href' => route('settings.profile'), 'icon' => 'user-circle'],
            ['label' => __('CLI'), 'icon' => 'command-line'],
        ]" />
    @endpush

    @php
        $sessionCount = $cliTokens->count();
        $orgCount = $organizations->count();
        $lastUsed = $cliTokens->pluck('last_used_at')->filter()->sort()->last();
        $installUrl = route('cli.install');
    @endphp

    <x-profile-shell
        :title="__('CLI')"
        :description="__('Install once, device-flow login, then manage sessions for your orgs.')"
        icon="heroicon-o-command-line"
    >
        <x-slot:actions>
            <x-docs-link
                slug="account-cli"
                class="!h-6 !gap-1 !rounded-md !px-2 !py-0 !text-xs !font-semibold"
            >
                <x-heroicon-o-book-open class="h-3.5 w-3.5 shrink-0 opacity-90" aria-hidden="true" />
                {{ __('Docs') }}
            </x-docs-link>
        </x-slot:actions>

        <x-slot:stats>
            <dl class="grid grid-cols-3 gap-px bg-brand-ink/5" aria-label="{{ __('CLI at a glance') }}">
                <div class="bg-white px-3 py-2">
                    <dt class="text-2xs font-semibold uppercase tracking-wide text-brand-mist">{{ __('Sessions') }}</dt>
                    <dd class="mt-0.5 font-mono text-base font-semibold tabular-nums text-brand-ink">{{ $sessionCount }}</dd>
                </div>
                <div class="bg-white px-3 py-2">
                    <dt class="text-2xs font-semibold uppercase tracking-wide text-brand-mist">{{ __('Organizations') }}</dt>
                    <dd class="mt-0.5 font-mono text-base font-semibold tabular-nums text-brand-ink">{{ $orgCount }}</dd>
                </div>
                <div class="bg-white px-3 py-2">
                    <dt class="text-2xs font-semibold uppercase tracking-wide text-brand-mist">{{ __('Last used') }}</dt>
                    <dd class="mt-0.5 truncate text-sm font-semibold text-brand-ink">{{ $lastUsed ? $lastUsed->diffForHumans() : '—' }}</dd>
                </div>
            </dl>
        </x-slot:stats>

        @if ($organizations->isEmpty())
            <div class="px-3 py-6 sm:px-4">
                <p class="text-sm text-brand-moss">{{ __('Org admin access is required to manage CLI authentications.') }}</p>
            </div>
        @else
            {{-- Get started --}}
            <x-workspace-panel-head
                dense
                class="border-b border-brand-ink/10"
                icon="heroicon-o-arrow-down-tray"
                :title="__('Get the dply CLI')"
                :note="__('Node 18+. Package served from this instance at `/cli/dply-cli.tgz` — not npm.')"
            />

            <div class="grid gap-px border-b border-brand-ink/10 bg-brand-ink/5 lg:grid-cols-2">
                <div class="space-y-3 bg-white px-3 py-3 sm:px-4">
                    <div>
                        <p class="text-2xs font-semibold uppercase tracking-wide text-brand-mist">{{ __('1. Install') }}</p>
                        <x-cli-snippet class="mt-1.5" size="10" :commands="[
                            ['label' => '', 'command' => 'curl -fsSL '.$installUrl.' | bash -s -- --login'],
                        ]" />
                    </div>
                    <div>
                        <p class="text-2xs font-semibold uppercase tracking-wide text-brand-mist">{{ __('2. Sign in') }}</p>
                        <x-cli-snippet class="mt-1.5" size="10" :commands="[
                            ['label' => '', 'command' => 'dply login --base-url '.$appUrl],
                        ]" />
                        <p class="mt-1 text-xs leading-relaxed text-brand-moss">
                            {{ __('Skip if you used `--login` above. More scopes later: `dply auth refresh` (or `r`).') }}
                        </p>
                    </div>
                    <div>
                        <p class="text-2xs font-semibold uppercase tracking-wide text-brand-mist">{{ __('3. Verify') }}</p>
                        <x-cli-snippet class="mt-1.5" size="10" open :commands="[
                            ['label' => __('Account'), 'command' => 'dply account show'],
                            ['label' => __('Menu'),    'command' => 'dply menu'],
                            ['label' => __('Servers'), 'command' => 'dply server list'],
                            ['label' => __('Sites'),   'command' => 'dply site list'],
                        ]" />
                    </div>
                </div>

                <div class="space-y-3 bg-white px-3 py-3 sm:px-4">
                    <div>
                        <p class="text-2xs font-semibold uppercase tracking-wide text-brand-mist">{{ __('4. Deploy from a repo') }}</p>
                        <x-cli-snippet class="mt-1.5" size="10" :commands="[
                            ['label' => __('Link'),   'command' => 'dply link'],
                            ['label' => __('Deploy'), 'command' => 'dply deploy --follow'],
                            ['label' => __('Status'), 'command' => 'dply site status'],
                            ['label' => __('Logs'),   'command' => 'dply site logs --follow'],
                        ]" />
                        <p class="mt-1 text-xs leading-relaxed text-brand-moss">
                            {{ __('Edge: `dply edge status --wait`. SSH run needs `commands.run`; firewall needs `network.read`.') }}
                        </p>
                    </div>
                    <div>
                        <p class="text-2xs font-semibold uppercase tracking-wide text-brand-mist">{{ __('5. GitHub Actions') }}</p>
                        <pre class="mt-1.5 max-h-40 overflow-auto rounded-md border border-brand-ink/10 bg-brand-ink/95 px-2.5 py-2 font-mono text-2xs leading-relaxed text-brand-sand"><code>name: Deploy
on:
  push:
    branches: [main]
jobs:
  deploy:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v4
      - uses: actions/setup-node@v4
        with:
          node-version: 20
      - run: curl -fsSL {{ $installUrl }} | bash -s -- --no-shell
      - run: dply login --token "$@{{ secrets.DPLY_TOKEN }}" --no-shell
      - run: dply deploy --sync --wait --idempotency-key "$@{{ github.sha }}"</code></pre>
                        <p class="mt-1 text-xs leading-relaxed text-brand-moss">
                            {{ __('Org API token with `sites.deploy`. Commit `.dply/site.json` or pass `--site` in CI.') }}
                        </p>
                    </div>
                </div>
            </div>

            {{-- Command index --}}
            <x-workspace-panel-head
                dense
                class="border-b border-brand-ink/10"
                icon="heroicon-o-queue-list"
                :title="__('Command index')"
                :note="__(':n commands · search or filter · press / to focus', ['n' => $cliTotal])"
                :count="(string) $cliTotal"
            />

            <x-cli-command-index
                :groups="$cliGroups"
                :entries="$cliEntries"
                :total="$cliTotal"
            />

            {{-- Repo config (collapsed) --}}
            <details class="group border-b border-brand-ink/10">
                <summary class="flex cursor-pointer list-none items-center gap-2 bg-brand-sand/15 px-3 py-2 sm:px-4 [&::-webkit-details-marker]:hidden">
                    <x-heroicon-o-code-bracket-square class="h-4 w-4 shrink-0 text-brand-sage" aria-hidden="true" />
                    <span class="text-sm font-semibold text-brand-ink">{{ __('Repo config: dply.yaml / dply.json') }}</span>
                    <span class="min-w-0 flex-1 truncate text-xs text-brand-mist">{{ __('Optional — build, redirects, headers, env…') }}</span>
                    <x-heroicon-m-chevron-down class="h-4 w-4 shrink-0 text-brand-mist transition group-open:rotate-180" aria-hidden="true" />
                </summary>
                <div class="space-y-3 border-t border-brand-ink/10 px-3 py-3 sm:px-4">
                    <p class="text-xs leading-relaxed text-brand-moss">
                        {{ __('Drop `dply.yaml`, `dply.yml`, or `dply.json` at the repo root. YAML and JSON are interchangeable. Validate with `dply edge lint` / `dply lint`.') }}
                    </p>
                    <div class="grid gap-3 lg:grid-cols-2">
                        <div>
                            <p class="text-2xs font-semibold uppercase tracking-wide text-brand-mist">{{ __('YAML') }}</p>
                            <pre class="mt-1 max-h-48 overflow-auto rounded-md border border-brand-ink/10 bg-brand-ink/95 px-2.5 py-2 font-mono text-2xs leading-relaxed text-brand-sand"><code>@verbatim build:
  command: npm run build
  output: dist
  node: "20"

redirects:
  - from: /old/*
    to: /new/:splat
    status: 301

headers:
  - for: /assets/*
    values:
      Cache-Control: "public, max-age=31536000, immutable"

env:
  public:
    NODE_VERSION: "20"
  secret:
    - DATABASE_URL@endverbatim</code></pre>
                        </div>
                        <div>
                            <p class="text-2xs font-semibold uppercase tracking-wide text-brand-mist">{{ __('JSON') }}</p>
                            <pre class="mt-1 max-h-48 overflow-auto rounded-md border border-brand-ink/10 bg-brand-ink/95 px-2.5 py-2 font-mono text-2xs leading-relaxed text-brand-sand"><code>@verbatim{
  "build": { "command": "npm run build", "output": "dist", "node": "20" },
  "redirects": [
    { "from": "/old/*", "to": "/new/:splat", "status": 301 }
  ],
  "headers": [
    { "for": "/assets/*", "values": { "Cache-Control": "public, max-age=31536000, immutable" } }
  ],
  "env": {
    "public": { "NODE_VERSION": "20" },
    "secret": ["DATABASE_URL"]
  }
}@endverbatim</code></pre>
                        </div>
                    </div>
                    <x-cli-snippet size="10" :commands="[
                        ['label' => __('Lint cwd'),  'command' => 'dply lint'],
                        ['label' => __('Lint file'), 'command' => 'dply lint --path dply.json'],
                    ]" />
                    <div class="overflow-hidden rounded-md border border-brand-ink/10">
                        <table class="w-full text-left text-xs">
                            <thead class="bg-brand-sand/30 text-brand-mist">
                                <tr>
                                    <th class="px-2.5 py-1.5 font-semibold uppercase tracking-wide">{{ __('Key') }}</th>
                                    <th class="px-2.5 py-1.5 font-semibold uppercase tracking-wide">{{ __('What it does') }}</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-brand-ink/10">
                                @foreach ([
                                    ['build', __('Build overrides: `command`, `output`, `root`, `node`, `env_files`.')],
                                    ['redirects', __('`{from, to, status}` — 301–308 (default 301).')],
                                    ['rewrites', __('`{from, to}` path proxies without changing the URL bar.')],
                                    ['headers', __('`{for, values}` response headers for path globs.')],
                                    ['env', __('`public:` map + `secret:` name list (values in dashboard).')],
                                    ['bindings', __('Cloudflare: `kv`, `r2`, `d1`, `queues` maps.')],
                                    ['origin', __('Origin proxy: `url`, `routes`, `failover_html`.')],
                                    ['domains', __('Hostnames to attach on deploy (attach-only).')],
                                    ['crons', __('`{schedule}` 5-field cron · up to 5 per site.')],
                                    ['firewall', __('`country_mode` + `countries` ISO codes.')],
                                    ['images', __('`allowed_hosts` for image resizing.')],
                                    ['error_pages', __('Custom 404/500 via html or path keys.')],
                                    ['maintenance', __('`enabled` + html/path → 503 page.')],
                                    ['previews', __('Preview gating + protection rules.')],
                                    ['comment_widget', __('`enabled` — preview comment widget.')],
                                ] as [$key, $desc])
                                    <tr>
                                        <td class="whitespace-nowrap px-2.5 py-1.5 align-top font-mono text-brand-ink">{{ $key }}</td>
                                        <td class="px-2.5 py-1.5 leading-relaxed text-brand-moss">{{ $desc }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
            </details>

            {{-- Sessions --}}
            <x-workspace-panel-head
                dense
                class="border-b border-brand-ink/10"
                icon="heroicon-o-shield-check"
                :title="__('CLI authentications')"
                :note="__('Device-flow tokens (“:name”). Revoke to sign a machine out immediately.', ['name' => $cliTokenName])"
                :count="(string) $sessionCount"
            >
                @if ($organizations->count() > 1)
                    <x-slot:actions>
                        <select
                            wire:model.live="organization_id"
                            class="h-6 min-w-[10rem] rounded-md border-brand-ink/15 bg-white py-0 pl-2 pr-7 text-xs shadow-sm focus:border-brand-sage focus:ring-brand-sage"
                        >
                            @foreach ($organizations as $org)
                                <option value="{{ $org->id }}">{{ $org->name }}</option>
                            @endforeach
                        </select>
                    </x-slot:actions>
                @endif
            </x-workspace-panel-head>

            @if ($cliTokens->isEmpty())
                <div class="px-3 py-8 text-center sm:px-4">
                    <p class="text-sm font-medium text-brand-ink">{{ __('No CLI sessions yet') }}</p>
                    <p class="mx-auto mt-1 max-w-md text-xs leading-relaxed text-brand-moss">
                        {{ __('Run `dply login` from a terminal and approve the device to create the first session.') }}
                    </p>
                </div>
            @else
                <ul class="divide-y divide-brand-ink/10">
                    @foreach ($cliTokens as $token)
                        <li wire:key="cli-token-{{ $token->id }}" class="flex flex-col gap-2 px-3 py-2.5 sm:flex-row sm:items-center sm:justify-between sm:px-4">
                            <div class="min-w-0">
                                <p class="font-mono text-sm text-brand-ink">{{ $token->token_prefix }}…</p>
                                <p class="mt-0.5 text-xs text-brand-moss">
                                    {{ $token->user?->email ?? __('Unknown') }}
                                    · {{ $token->created_at?->diffForHumans() }}
                                    @if ($token->last_used_at)
                                        · {{ __('Last used :time', ['time' => $token->last_used_at->diffForHumans()]) }}
                                    @endif
                                </p>
                            </div>
                            <button
                                type="button"
                                wire:click="openConfirmActionModal('revokeCliToken', [@js((string) $token->id)], @js(__('Revoke this CLI session?')), @js(__('That machine loses API access immediately. Re-run `dply login` to reconnect.')), @js(__('Revoke session')), true)"
                                class="inline-flex h-7 shrink-0 items-center gap-1 rounded-md border border-rose-200 bg-rose-50 px-2 text-xs font-semibold text-rose-800 hover:bg-rose-100"
                            >
                                <x-heroicon-o-x-circle class="h-3.5 w-3.5" />
                                {{ __('Revoke') }}
                            </button>
                        </li>
                    @endforeach
                </ul>
            @endif
        @endif
    </x-profile-shell>

    @include('livewire.partials.confirm-action-modal')
</div>
