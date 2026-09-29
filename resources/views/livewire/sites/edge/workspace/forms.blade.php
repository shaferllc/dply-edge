<div>
    <section class="border-b border-brand-ink/10 px-5 py-4 sm:px-6">
        @include('livewire.sites.edge.workspace.partials.feature-guide', [
            'docSlug' => 'forms',
            'what' => __('Edge Forms turns a path on your live Edge hostname into a mail-backed endpoint. Visitors POST; the Edge Worker checks spam defenses, then Dply emails you the fields and lists them below — no app server or serverless function.'),
            'steps' => [
                __('Add a form: pick a starter or a blank form, then set its path and inbox.'),
                __('Save — delivery republishes so the Worker starts accepting POSTs on that path.'),
                __('In your site HTML, POST to the same path on your Edge hostname (copy the HTML from the form’s settings).'),
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
        @php
            $canConfigure = $managedDelivery && auth()->user()?->can('update', $site);
        @endphp

    </section>

    @php
        $count = count($endpoints);
        $inboxes = collect($endpoints)->pluck('to_email')->filter()->unique()->values();
        $lastSubmission = $submissions->first();
        $editing = $editingEndpoint !== null ? ($endpoints[$editingEndpoint] ?? null) : null;
        $field = 'mt-1 block w-full rounded-lg border border-brand-ink/15 bg-white px-3 py-2 text-sm text-brand-ink focus:border-brand-sage focus:ring-brand-sage dark:bg-zinc-900';
    @endphp

    <section class="space-y-8 border-b border-brand-ink/10 px-5 py-8 sm:px-10 sm:py-10" x-data="{ submission: null }">
        <div>
            <p class="text-2xs font-semibold uppercase tracking-[0.16em] text-brand-mist">{{ __('Forms') }}</p>
            <p class="mt-3 max-w-3xl text-2xl font-medium leading-snug tracking-tight text-brand-ink sm:text-3xl">
                @if (! $managedDelivery)
                    {{ __('Forms need Dply-hosted Edge delivery.') }}
                @elseif ($count === 0)
                    {{ __('No forms yet. Add one and your site can take submissions without a backend — they’re emailed to you.') }}
                @elseif (! $enabled)
                    {{ trans_choice(':count form is set up, but forms are off, so POSTs are not accepted.|:count forms are set up, but forms are off, so POSTs are not accepted.', $count) }}
                @else
                    <span class="text-brand-sage">{{ trans_choice(':count form|:count forms', $count) }}</span>
                    {{ trans_choice('accepts submissions and emails them to :to.|accept submissions and email them to :to.', $count, ['to' => $inboxes->count() === 1 ? $inboxes->first() : __('your inboxes')]) }}
                @endif
                @if ($lastSubmission)
                    {{ __('The last one came in :ago.', ['ago' => $lastSubmission->created_at->diffForHumans()]) }}
                @endif
            </p>
        </div>

        <div>
            <div class="flex items-center justify-between gap-3 border-b border-brand-ink/10 pb-2">
                <p class="text-sm font-semibold text-brand-ink">{{ __('Endpoints') }}</p>
                <button type="button" wire:click="openPicker" @disabled(! $canConfigure) class="inline-flex min-h-9 items-center gap-1 text-sm font-medium text-brand-sage hover:underline disabled:opacity-50">
                    <x-heroicon-m-plus class="h-4 w-4" aria-hidden="true" />{{ __('Add a form') }}
                </button>
            </div>
            @if ($count === 0)
                <p class="border-b border-brand-ink/10 py-4 text-sm text-brand-moss">{{ __('Nothing set up yet.') }}</p>
            @else
                <ul>
                    @foreach ($endpoints as $i => $endpoint)
                        <li class="border-b border-brand-ink/10" wire:key="form-row-{{ $i }}">
                            <button type="button" wire:click="editEndpoint({{ $i }})" class="flex min-h-12 w-full items-center gap-3 py-3 text-left hover:bg-brand-sand/20">
                                <span class="flex-1 text-sm text-brand-ink sm:text-base">
                                    {{ __('POSTs to') }} <span class="font-mono">{{ $endpoint['path'] }}</span>
                                    {{ __('go to :to', ['to' => $endpoint['to_email'] ?: __('nobody yet')]) }}
                                </span>
                                <span class="text-xs text-brand-moss">{{ $endpoint['require_turnstile'] ? __('Bot check') : __('Honeypot only') }}</span>
                                <x-heroicon-m-chevron-right class="h-4 w-4 shrink-0 text-brand-mist" aria-hidden="true" />
                            </button>
                        </li>
                    @endforeach
                </ul>
            @endif
            <label class="flex min-h-12 cursor-pointer items-center gap-3 border-b border-brand-ink/10 py-3">
                <span class="flex-1 text-sm text-brand-ink sm:text-base">{{ __('Accept form submissions on this site') }}</span>
                <input type="checkbox" wire:model.live="enabled" @disabled(! $canConfigure) class="h-4 w-4 rounded border-brand-ink/30 text-brand-forest focus:ring-brand-forest" />
            </label>
        </div>

        <div>
            <p class="border-b border-brand-ink/10 pb-2 text-sm font-semibold text-brand-ink">{{ __('Recent submissions') }}</p>
            @if ($submissions->isEmpty())
                <p class="border-b border-brand-ink/10 py-4 text-sm text-brand-moss">{{ __('None yet. Each accepted submission is emailed to its endpoint’s inbox and listed here.') }}</p>
            @else
                <ul>
                    @foreach ($submissions as $submission)
                        @php
                            $fields = collect((array) $submission->fields)->map(fn ($v) => is_scalar($v) ? (string) $v : json_encode($v));
                            $who = $fields->get('name') ?: $fields->get('email') ?: __('Submission');
                            $gist = $fields->except(['name', 'email'])->first() ?? $fields->get('email');
                            $payload = [
                                'path' => $submission->path,
                                'who' => $who,
                                'fields' => $fields->map(fn ($v, $k) => ['k' => (string) $k, 'v' => \Illuminate\Support\Str::limit($v, 5000)])->values(),
                                'when' => $submission->created_at->toDayDateTimeString(),
                                'ago' => $submission->created_at->diffForHumans(),
                            ];
                        @endphp
                        <li class="border-b border-brand-ink/10" wire:key="submission-{{ $submission->id }}">
                            <button type="button" x-on:click="submission = @js($payload); $dispatch('open-modal', 'edge-form-submission')" class="flex min-h-12 w-full items-center gap-3 py-3 text-left hover:bg-brand-sand/20">
                                <span class="w-28 shrink-0 truncate font-mono text-xs text-brand-mist">{{ $submission->path }}</span>
                                <span class="min-w-0 flex-1 truncate text-sm text-brand-ink">
                                    {{ $who }}@if ($gist && $gist !== $who) <span class="text-brand-moss">— {{ \Illuminate\Support\Str::limit($gist, 80) }}</span>@endif
                                </span>
                                <time datetime="{{ $submission->created_at->toIso8601String() }}" class="shrink-0 text-xs text-brand-mist">{{ $submission->created_at->diffForHumans() }}</time>
                            </button>
                        </li>
                    @endforeach
                </ul>
            @endif
        </div>

        <x-modal name="edge-form-submission" maxWidth="2xl" overlayClass="bg-brand-ink/40" focusable>
            <template x-if="submission">
                <div class="space-y-5 p-6 sm:p-7">
                    <div class="flex items-start justify-between gap-4">
                        <div class="min-w-0 space-y-1">
                            <code class="font-mono text-xs text-brand-sage" x-text="submission.path"></code>
                            <h2 class="text-lg font-semibold text-brand-ink" x-text="submission.who"></h2>
                        </div>
                        <button type="button" x-on:click="$dispatch('close-modal', 'edge-form-submission')" class="dply-icon-btn h-9 w-9" aria-label="{{ __('Close') }}">
                            <x-heroicon-o-x-mark class="h-5 w-5" aria-hidden="true" />
                        </button>
                    </div>
                    <dl class="space-y-3">
                        <template x-for="f in submission.fields" :key="f.k">
                            <div>
                                <dt class="text-2xs font-semibold uppercase tracking-[0.14em] text-brand-mist" x-text="f.k"></dt>
                                <dd class="mt-1 whitespace-pre-wrap break-words text-sm text-brand-ink" x-text="f.v"></dd>
                            </div>
                        </template>
                    </dl>
                    <p class="text-xs text-brand-moss"><span x-text="submission.when"></span> · <span x-text="submission.ago"></span></p>
                </div>
            </template>
        </x-modal>
    </section>

    <x-modal name="edge-form-endpoint" maxWidth="2xl" overlayClass="bg-brand-ink/40" focusable>
        <div class="space-y-5 p-6 sm:p-7">
            <div class="flex items-start justify-between gap-4">
                <h2 class="text-lg font-semibold text-brand-ink">
                    @if ($pickingEndpoint || ! $editing)
                        {{ __('Add a form') }}
                    @else
                        <span class="font-mono">{{ $editing['path'] ?: '/' }}</span>
                    @endif
                </h2>
                <button type="button" wire:click="closeEndpoint" class="dply-icon-btn h-9 w-9" aria-label="{{ __('Close') }}">
                    <x-heroicon-o-x-mark class="h-5 w-5" aria-hidden="true" />
                </button>
            </div>

            @if ($pickingEndpoint || ! $editing)
                <div class="grid gap-2 sm:grid-cols-2">
                    @foreach ($examples as $example)
                        <button type="button" wire:click="addExample('{{ $example['key'] }}')" class="flex min-h-14 flex-col items-start justify-center rounded-lg border border-brand-ink/10 px-4 py-2 text-left hover:border-brand-sage/50 hover:bg-brand-sage/5">
                            <span class="text-sm font-semibold text-brand-ink">{{ $example['label'] }} <span class="font-mono text-xs font-normal text-brand-mist">{{ $example['path'] }}</span></span>
                            <span class="text-xs text-brand-mist">{{ $example['hint'] }}</span>
                        </button>
                    @endforeach
                    <button type="button" wire:click="addEndpoint" class="flex min-h-14 flex-col items-start justify-center rounded-lg border border-dashed border-brand-ink/20 px-4 py-2 text-left hover:border-brand-sage/50">
                        <span class="text-sm font-semibold text-brand-ink">{{ __('Blank form') }}</span>
                        <span class="text-xs text-brand-mist">{{ __('Set the path and inbox yourself') }}</span>
                    </button>
                </div>
            @else
                @php $i = $editingEndpoint; @endphp
                <div class="space-y-4" wire:key="form-edit-{{ $i }}">
                    <div class="grid gap-4 sm:grid-cols-2">
                        <div>
                            <x-input-label for="form-path" :value="__('Path')" />
                            <input id="form-path" type="text" wire:model.live.debounce.400ms="endpoints.{{ $i }}.path" class="{{ $field }} font-mono" />
                            <p class="mt-1 text-xs text-brand-mist">{{ __('POST path on your Edge hostname, e.g. /contact.') }}</p>
                            <x-input-error :messages="$errors->get('endpoints.'.$i.'.path')" class="mt-1" />
                        </div>
                        <div>
                            <x-input-label for="form-to" :value="__('Email to')" />
                            <input id="form-to" type="email" wire:model="endpoints.{{ $i }}.to_email" class="{{ $field }}" />
                            <p class="mt-1 text-xs text-brand-mist">{{ __('Org mail must be configured.') }}</p>
                            <x-input-error :messages="$errors->get('endpoints.'.$i.'.to_email')" class="mt-1" />
                        </div>
                        <div>
                            <x-input-label for="form-honeypot" :value="__('Honeypot field')" />
                            <input id="form-honeypot" type="text" wire:model.live.debounce.400ms="endpoints.{{ $i }}.honeypot" class="{{ $field }} font-mono" />
                            <p class="mt-1 text-xs text-brand-mist">{{ __('Hidden input name. If filled, the POST is dropped as spam.') }}</p>
                            <x-input-error :messages="$errors->get('endpoints.'.$i.'.honeypot')" class="mt-1" />
                        </div>
                        <label class="flex items-start gap-2 pt-6 text-sm text-brand-ink">
                            <input type="checkbox" wire:model.live="endpoints.{{ $i }}.require_turnstile" class="mt-0.5 h-4 w-4 rounded border-brand-ink/30 text-brand-forest focus:ring-brand-forest" />
                            <span>
                                <span class="font-medium">{{ __('Require bot check') }}</span>
                                <span class="mt-0.5 block text-xs text-brand-mist">{{ __('Needs Bot protection keys and a Turnstile widget in the form.') }}</span>
                            </span>
                        </label>
                    </div>

                    @if ($editingSampleHtml)
                        <details class="group rounded-lg border border-brand-ink/10" x-data="{ copied: false }">
                            <summary class="flex min-h-11 cursor-pointer list-none items-center justify-between gap-3 px-3 text-sm font-medium text-brand-ink [&::-webkit-details-marker]:hidden">
                                <span class="inline-flex items-center gap-1.5"><x-heroicon-o-code-bracket class="h-4 w-4" aria-hidden="true" />{{ __('HTML for this form') }}</span>
                                <x-heroicon-m-chevron-down class="h-4 w-4 text-brand-mist transition group-open:rotate-180" />
                            </summary>
                            <div class="border-t border-brand-ink/10 p-3">
                                <div class="mb-2 flex justify-end">
                                    <button type="button" class="inline-flex items-center gap-1.5 rounded-lg border border-brand-ink/15 px-2.5 py-1.5 text-xs font-semibold text-brand-ink hover:bg-brand-sand/40" x-on:click="navigator.clipboard.writeText(@js($editingSampleHtml)); copied = true; setTimeout(() => copied = false, 2000)">
                                        <x-heroicon-o-clipboard class="h-3.5 w-3.5" aria-hidden="true" />
                                        <span x-show="! copied">{{ __('Copy') }}</span>
                                        <span x-cloak x-show="copied">{{ __('Copied') }}</span>
                                    </button>
                                </div>
                                <pre class="max-h-56 overflow-auto rounded-md bg-brand-sand/15 p-3 font-mono text-xs leading-relaxed text-brand-ink dark:bg-zinc-950"><code>{{ $editingSampleHtml }}</code></pre>
                            </div>
                        </details>
                    @endif
                </div>

                <div class="flex items-center justify-between gap-2">
                    <button type="button" wire:click="removeEditingEndpoint" class="text-sm font-semibold text-red-600 hover:underline">{{ __('Remove form') }}</button>
                    <span class="flex gap-2">
                        <button type="button" wire:click="closeEndpoint" class="rounded-lg border border-brand-ink/15 px-4 py-2 text-sm font-medium text-brand-ink hover:bg-brand-sand/40">{{ __('Cancel') }}</button>
                        <x-primary-button type="button" wire:click="saveEndpoint" wire:loading.attr="disabled" wire:target="saveEndpoint">{{ __('Save') }}</x-primary-button>
                    </span>
                </div>
            @endif
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
