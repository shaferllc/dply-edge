@php
    $sampleHtml = '<form method="POST" action="'.$sampleAction.'">'."\n"
        .'  <label>Name <input type="text" name="name" required></label>'."\n"
        .'  <label>Email <input type="email" name="email" required></label>'."\n"
        .'  <label>Message <textarea name="message" required></textarea></label>'."\n"
        .'  <!-- Honeypot: leave empty; hide from humans -->'."\n"
        .'  <input type="text" name="'.$sampleHoneypot.'" tabindex="-1" autocomplete="off" style="position:absolute;left:-9999px" aria-hidden="true">'."\n";
    if ($sampleRequireBot) {
        $sampleHtml .= '  <!-- Require bot check: add Turnstile widget (Bot protection) -->'."\n"
            .'  <div class="cf-turnstile" data-sitekey="YOUR_TURNSTILE_SITE_KEY"></div>'."\n"
            .'  <script src="https://challenges.cloudflare.com/turnstile/v0/api.js" async defer></script>'."\n";
    }
    $sampleHtml .= '  <button type="submit">Send</button>'."\n"
        .'</form>';
@endphp

<div>
    <section class="border-b border-brand-ink/10 px-5 py-4 sm:px-6">
        @include('livewire.sites.edge.workspace.partials.feature-guide', [
            'docSlug' => 'edge-forms',
            'what' => __('Edge Forms turns a path on your live Edge hostname into a mail-backed endpoint. Visitors POST; the Edge Worker checks spam defenses, then Dply emails you the fields — no app server or serverless function.'),
            'steps' => [
                __('Enable Edge forms and add an endpoint (path + inbox), or click an example below.'),
                __('Save — delivery republishes so the Worker starts accepting POSTs on that path.'),
                __('In your site HTML, POST to the same path on your Edge hostname (see HTML example).'),
                __('Match the honeypot input name to the dashboard. Optional: turn on Bot protection + Require bot check and include Turnstile.'),
            ],
            'setupLinks' => [
                [
                    'label' => __('Bot protection setup'),
                    'href' => route('sites.show', ['server' => $server, 'site' => $site, 'section' => 'bot-protection']),
                ],
                [
                    'label' => __('Rate limits (optional)'),
                    'href' => route('sites.show', ['server' => $server, 'site' => $site, 'section' => 'rate-limits']),
                ],
            ],
            'tips' => [
                __('Flow: browser → Edge Worker (honeypot / Turnstile) → signed ingest on dply → org outbound mail.'),
                __('JSON POSTs work too (application/json). HTML forms get a simple “Thanks” page; JSON gets {"ok":true}.'),
                __('Repo config (dply.yaml) lives under Advanced.'),
                __('Use one endpoint per form. Pair busy paths with Rate limits to cut abuse.'),
            ],
        ])

        @include('livewire.sites.edge.workspace.partials.managed-only-banner', ['managedDelivery' => $managedDelivery])

        <div class="mt-4 space-y-4">
            <label class="flex items-start gap-3">
                <input type="checkbox" wire:model.live="enabled" class="mt-0.5 rounded border-brand-ink/20 text-brand-sage" @disabled(! $managedDelivery) />
                <span class="text-sm font-medium text-brand-ink">{{ __('Enable Edge forms') }}</span>
            </label>

            <div class="rounded-xl border border-brand-ink/10 bg-brand-sand/20 px-3 py-3 dark:bg-brand-sand/10 sm:px-4">
                <p class="text-xs font-semibold uppercase tracking-[0.16em] text-brand-sage">{{ __('Examples') }}</p>
                <p class="mt-1 text-xs leading-relaxed text-brand-moss">{{ __('Click to add a starter endpoint (path + honeypot). Set Email to, then Save.') }}</p>
                <div class="mt-3 flex flex-wrap gap-2">
                    @foreach ($examples as $example)
                        <button
                            type="button"
                            wire:click="addExample('{{ $example['key'] }}')"
                            @disabled(! $managedDelivery)
                            class="inline-flex items-center gap-1.5 rounded-lg border border-brand-ink/15 bg-white px-2.5 py-1.5 text-xs font-semibold text-brand-ink shadow-sm transition hover:border-brand-sage/40 hover:bg-brand-sage/5 disabled:cursor-not-allowed disabled:opacity-50 dark:bg-zinc-900"
                            title="{{ $example['hint'] }}"
                        >
                            {{ $example['label'] }}
                            <span class="font-normal text-brand-mist">+</span>
                        </button>
                    @endforeach
                </div>
            </div>

            @foreach ($endpoints as $i => $endpoint)
                <div class="space-y-3 rounded-xl border border-brand-ink/10 p-3" wire:key="form-{{ $i }}">
                    <div class="grid gap-3 sm:grid-cols-2">
                        <div>
                            <x-input-label :value="__('Path')" />
                            <x-text-input wire:model.live="endpoints.{{ $i }}.path" type="text" class="mt-1 block w-full font-mono text-sm" @disabled(! $managedDelivery) />
                            <p class="mt-1 text-xs text-brand-moss">{{ __('POST path on your Edge hostname, e.g. /contact or /api/support.') }}</p>
                        </div>
                        <div>
                            <x-input-label :value="__('Email to')" />
                            <x-text-input wire:model="endpoints.{{ $i }}.to_email" type="email" class="mt-1 block w-full text-sm" @disabled(! $managedDelivery) />
                            <p class="mt-1 text-xs text-brand-moss">{{ __('Inbox that receives each submission (org mail must be configured).') }}</p>
                        </div>
                    </div>
                    <div class="grid gap-3 sm:grid-cols-2">
                        <div>
                            <x-input-label :value="__('Honeypot field')" />
                            <x-text-input wire:model.live="endpoints.{{ $i }}.honeypot" type="text" class="mt-1 block w-full font-mono text-sm" @disabled(! $managedDelivery) />
                            <p class="mt-1 text-xs text-brand-moss">{{ __('Hidden input name in your HTML. If filled, the Worker drops the POST as spam.') }}</p>
                        </div>
                        <div class="pt-1">
                            <label class="flex items-start gap-2 text-sm text-brand-ink">
                                <input type="checkbox" wire:model.live="endpoints.{{ $i }}.require_turnstile" class="mt-0.5 rounded border-brand-ink/20 text-brand-sage" @disabled(! $managedDelivery) />
                                <span>
                                    <span class="font-medium">{{ __('Require bot check') }}</span>
                                    <span class="mt-0.5 block text-xs text-brand-moss">{{ __('Needs Bot protection keys. Form must include a Turnstile token (cf-turnstile-response).') }}</span>
                                </span>
                            </label>
                        </div>
                    </div>
                    @if (count($endpoints) > 1)
                        <button type="button" wire:click="removeEndpoint({{ $i }})" class="text-xs font-semibold text-red-600">{{ __('Remove') }}</button>
                    @endif
                </div>
            @endforeach

            <div class="flex flex-wrap items-center justify-between gap-3">
                <div class="flex flex-wrap items-center gap-3">
                    <button type="button" wire:click="addEndpoint" class="text-sm font-semibold text-brand-sage" @disabled(! $managedDelivery)>{{ __('Add endpoint') }}</button>
                    <button
                        type="button"
                        x-on:click="$dispatch('open-modal', 'edge-forms-html-example')"
                        class="inline-flex items-center gap-1.5 text-sm font-semibold text-brand-sage hover:underline"
                    >
                        <x-heroicon-o-code-bracket class="h-4 w-4" aria-hidden="true" />
                        {{ __('HTML example') }}
                    </button>
                </div>
                <x-primary-button type="button" wire:click="save" @disabled(! $managedDelivery)>{{ __('Save') }}</x-primary-button>
            </div>
        </div>
    </section>

    <x-modal
        name="edge-forms-html-example"
        :show="false"
        maxWidth="2xl"
        overlayClass="bg-brand-ink/40"
        panelClass="dply-modal-panel overflow-hidden shadow-xl flex max-h-[min(90vh,720px)] flex-col"
        focusable
    >
        <div class="shrink-0 border-b border-brand-ink/10 px-5 py-4 sm:px-6">
            <p class="text-xs font-semibold uppercase tracking-[0.16em] text-brand-sage">{{ __('HTML example') }}</p>
            <p class="mt-1 text-sm text-brand-moss">
                {{ __('Built from your first endpoint') }}
                @if ($liveHostname)
                    <span class="font-mono text-brand-ink">({{ $liveHostname }}{{ $samplePath }})</span>
                @endif
                — {{ __('copy into your site or a Snippet.') }}
            </p>
        </div>
        <div class="min-h-0 flex-1 overflow-y-auto px-5 py-4 sm:px-6" x-data="{ copied: false }">
            <div class="mb-2 flex justify-end">
                <button
                    type="button"
                    class="inline-flex items-center gap-1.5 rounded-lg border border-brand-ink/15 bg-brand-sand/30 px-2.5 py-1.5 text-xs font-semibold text-brand-ink hover:bg-brand-sand/50"
                    @click="navigator.clipboard.writeText(@js($sampleHtml)); copied = true; setTimeout(() => copied = false, 2000)"
                >
                    <x-heroicon-o-clipboard class="h-3.5 w-3.5" aria-hidden="true" />
                    <span x-show="!copied">{{ __('Copy') }}</span>
                    <span x-cloak x-show="copied">{{ __('Copied') }}</span>
                </button>
            </div>
            <pre class="overflow-x-auto rounded-lg border border-brand-ink/10 bg-brand-sand/15 p-3 font-mono text-xs leading-relaxed text-brand-ink dark:bg-zinc-950"><code>{{ $sampleHtml }}</code></pre>
        </div>
        <div class="flex shrink-0 items-center justify-end border-t border-brand-ink/10 px-5 py-3 sm:px-6">
            <button
                type="button"
                x-on:click="$dispatch('close-modal', 'edge-forms-html-example')"
                class="rounded-lg border border-brand-ink/15 bg-white px-3 py-1.5 text-sm font-medium text-brand-ink shadow-sm hover:bg-brand-sand/40"
            >
                {{ __('Close') }}
            </button>
        </div>
    </x-modal>

    @php
        $hasRepoForms = $repoForms !== [];
        $repoEndpointCount = count(is_array($repoForms['endpoints'] ?? null) ? $repoForms['endpoints'] : []);
    @endphp
    <x-edge-yaml-advanced
        :site="$site"
        :file="$sourcePath"
        :has-repo="$hasRepoForms"
        :repo-badge="$repoEndpointCount > 0 ? (string) $repoEndpointCount : null"
        :hint="__('Commit at the repo root. Dashboard Save overrides this section.')"
    >
        <x-slot:status>
            @if ($hasRepoForms)
                <dl class="grid grid-cols-1 gap-y-1.5 text-xs sm:grid-cols-[8rem_1fr]">
                    <dt class="text-brand-mist">{{ __('Enabled') }}</dt>
                    <dd class="text-brand-moss">{{ ($repoForms['enabled'] ?? false) ? __('Yes') : __('No') }}</dd>
                    @if ($repoEndpointCount > 0)
                        <dt class="text-brand-mist">{{ __('Endpoints') }}</dt>
                        <dd class="text-brand-moss">{{ $repoEndpointCount }}</dd>
                    @endif
                </dl>
                <p class="mt-2 text-xs text-brand-mist">{{ __('Dashboard values override the repo when both are set.') }}</p>
            @else
                <p class="text-sm text-brand-moss">{{ __('None declared in :file yet.', ['file' => $sourcePath]) }}</p>
            @endif
        </x-slot:status>
forms:
  enabled: true
  endpoints:
    - path: /contact
      to_email: you@example.com
      honeypot: company
      require_turnstile: true
    </x-edge-yaml-advanced>
</div>
