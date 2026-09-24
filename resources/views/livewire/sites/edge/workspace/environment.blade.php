@php
    $repoPublic = is_array($repoEnv['public'] ?? null) ? $repoEnv['public'] : [];
    $repoSecret = is_array($repoEnv['secret'] ?? null) ? $repoEnv['secret'] : [];
    $hasRepoEnv = $repoPublic !== [] || $repoSecret !== [];
@endphp

<div>
    @if ($site->isEdgePreview())
        <div class="px-5 py-8 text-center text-sm text-brand-moss sm:px-6">
            {{ __('Environment variables are managed on the parent Edge site.') }}
        </div>
    @else
        <section class="border-b border-brand-ink/10 px-5 py-4 sm:px-6">
            @include('livewire.sites.edge.workspace.partials.feature-guide', [
                'docSlug' => 'edge-environment',
                'what' => ($site->edgeMeta()['runtime_mode'] ?? '') === 'container'
                    ? __('Encrypted production secrets for this app. The next deploy puts each one in the container environment.')
                    : __('Encrypted production secrets for this Edge site — injected into the build and middleware/SSR workers on the next deploy.'),
                'steps' => [
                    __('Edit the KEY=value lines, then save.'),
                    __('Redeploy so the new values reach the app.'),
                ],
                'setupLinks' => [
                    [
                        'label' => __('Deploys'),
                        'href' => route('sites.show', ['server' => $server, 'site' => $site, 'section' => 'deploys']),
                    ],
                ],
                'tips' => [
                    __('Preview children inherit env from this parent — edit here, not on the preview site.'),
                    __('Declare secret names in dply.yaml under Advanced; put values only in the dashboard.'),
                ],
            ])
        </section>

        @include('livewire.sites.partials.linked-organization-secrets')

        @include('livewire.sites.partials.edge.environment-settings')

        @if ($missingSecrets !== [])
            <div class="border-b border-rose-200 bg-rose-50 px-5 py-3 text-xs text-rose-900 sm:px-6 dark:bg-rose-950/40 dark:text-raw-rose-200">
                {{ __(':count secret(s) declared in :file still need a dashboard value.', ['count' => count($missingSecrets), 'file' => $sourcePath]) }}
            </div>
        @endif

        <details class="group border-b border-brand-ink/10" @if ($hasRepoEnv || $missingSecrets !== []) open @endif>
            <summary class="flex cursor-pointer list-none items-center justify-between gap-3 bg-brand-sand/10 px-5 py-3.5 text-sm font-semibold text-brand-ink hover:bg-brand-sand/20 sm:px-6 [&::-webkit-details-marker]:hidden">
                <span class="inline-flex items-center gap-2">
                    {{ __('From :file', ['file' => $sourcePath]) }}
                    @if ($hasRepoEnv)
                        <span class="rounded-full bg-brand-sand/60 px-2 py-0.5 font-mono text-2xs font-semibold uppercase tracking-wide text-brand-moss">
                            {{ count($repoPublic) + count($repoSecret) }}
                        </span>
                    @endif
                </span>
                <x-heroicon-m-chevron-down class="h-4 w-4 text-brand-mist transition group-open:rotate-180" />
            </summary>

            <div class="border-t border-brand-ink/10 px-5 py-4 sm:px-6">
                <div class="mb-3 flex flex-wrap items-center justify-between gap-2">
                    <p class="text-xs text-brand-moss">
                        {{ __('Optional IaC declarations. Put secret values in the dashboard — only names belong in the repo.') }}
                    </p>
                    <a
                        href="{{ route('sites.edge.dply-yaml', ['server' => $site->server_id, 'site' => $site->id]) }}"
                        class="inline-flex shrink-0 items-center gap-1 rounded-lg border border-brand-ink/15 bg-white px-2.5 py-1 text-xs font-semibold text-brand-ink hover:bg-brand-sand/40"
                    >
                        <x-heroicon-o-arrow-down-tray class="h-3.5 w-3.5" aria-hidden="true" />
                        {{ __('Generate :file', ['file' => $sourcePath]) }}
                    </a>
                </div>

                @if ($hasRepoEnv)
                    <div class="space-y-3">
                        @if ($repoPublic !== [])
                            <div>
                                <p class="text-2xs font-semibold uppercase tracking-wide text-brand-mist">{{ __('Public') }}</p>
                                <ul class="mt-1.5 divide-y divide-brand-ink/8 rounded-lg border border-brand-ink/10">
                                    @foreach ($repoPublic as $name => $value)
                                        <li class="flex flex-wrap gap-x-3 gap-y-1 px-3 py-2 font-mono text-xs">
                                            <span class="font-semibold text-brand-ink">{{ $name }}</span>
                                            <span class="min-w-0 break-all text-brand-moss">{{ $value }}</span>
                                        </li>
                                    @endforeach
                                </ul>
                            </div>
                        @endif

                        @if ($repoSecret !== [])
                            <div>
                                <p class="text-2xs font-semibold uppercase tracking-wide text-brand-mist">{{ __('Secret names') }}</p>
                                <ul class="mt-1.5 divide-y divide-brand-ink/8 rounded-lg border border-brand-ink/10">
                                    @foreach ($repoSecret as $name)
                                        @php $isMissing = in_array($name, $missingSecrets, true); @endphp
                                        <li class="flex flex-wrap items-center gap-2 px-3 py-2 font-mono text-xs">
                                            <span class="{{ $isMissing ? 'text-rose-700' : 'text-brand-ink' }}">{{ $name }}</span>
                                            @if ($isMissing)
                                                <span class="rounded-full bg-rose-100 px-1.5 py-0.5 text-2xs font-semibold uppercase tracking-wide text-rose-900">{{ __('Missing') }}</span>
                                            @else
                                                <span class="rounded-full bg-emerald-100 px-1.5 py-0.5 text-2xs font-semibold uppercase tracking-wide text-emerald-900">{{ __('Set') }}</span>
                                            @endif
                                        </li>
                                    @endforeach
                                </ul>
                            </div>
                        @endif
                    </div>
                @else
                    <p class="text-sm text-brand-moss">{{ __('None declared in :file yet.', ['file' => $sourcePath]) }}</p>
                @endif

                <x-edge-yaml-example class="mt-4" :file="$sourcePath" :hint="__('Public values can live in the repo. Secret names only — set values in the dashboard.')">
env:
  public:
    APP_ENV: "production"
  secret:
    - "DATABASE_URL"
    - "APP_KEY"
                </x-edge-yaml-example>
            </div>
        </details>

        @can('update', $site)
            <x-unsaved-changes-bar
                :message="__('Save these environment changes, or redeploy to apply them now.')"
                saveAction="saveEdgeEnvText"
                :saveLabel="__('Save')"
                discardAction="discardEdgeEnv"
                extraAction="redeployEdgeEnv"
                :extraLabel="__('Redeploy')"
                targets="edgeEnvText"
                formPendingWire="pending"
                clientDirty
            />
        @endcan
    @endif

    @include('livewire.partials.confirm-action-modal')
</div>
