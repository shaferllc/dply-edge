{{-- Environment: a sentence about what the app gets on its next deploy, a row per source, each opening a dialog. --}}
@php
    $repoPublic = is_array($repoEnv['public'] ?? null) ? $repoEnv['public'] : [];
    $repoSecret = is_array($repoEnv['secret'] ?? null) ? $repoEnv['secret'] : [];
    $canEdit = auth()->user()?->can('update', $site) ?? false;
    $linked = method_exists($this, 'linkedOrganizationSecretRows') ? collect($this->linkedOrganizationSecretRows()) : collect();
    $injected = collect($resourceInjections)->reject(fn ($r) => $r['overridden']);
    $mine = count($envKeys);
    $total = $mine + $linked->count() + $injected->count() + count($repoPublic);
    $isContainer = ($site->edgeMeta()['runtime_mode'] ?? '') === 'container';
    $row = 'flex min-h-12 w-full items-center gap-3 border-b border-brand-ink/10 py-3 text-left hover:bg-brand-sand/20';
    $close = fn (string $m) => '<button type="button" x-on:click="$dispatch(\'close-modal\', \''.$m.'\')" class="dply-icon-btn h-9 w-9 shrink-0" aria-label="'.e(__('Close')).'">'.svg('heroicon-o-x-mark', 'h-5 w-5', ['aria-hidden' => 'true'])->toHtml().'</button>';
    $keysList = fn ($keys, int $max = 3) => collect($keys)->take($max)->implode(', ').(count($keys) > $max ? ' '.__('and :n more', ['n' => count($keys) - $max]) : '');
@endphp

<div>
    @if ($site->isEdgePreview())
        <div class="px-5 py-8 text-center text-sm text-brand-moss sm:px-6">
            {{ __('Environment variables are managed on the parent Edge site.') }}
        </div>
    @else
        <section class="border-b border-brand-ink/10 px-5 py-4 sm:px-6">
            @include('livewire.sites.edge.workspace.partials.feature-guide', [
                'docSlug' => 'environment-variables',
                'what' => $isContainer
                    ? __('Encrypted production secrets for this app. The next deploy puts each one in the container environment.')
                    : __('Encrypted production secrets for this Edge site — injected into the build and middleware/SSR workers on the next deploy.'),
                'steps' => [
                    __('Open “variables you set” to edit them as KEY=value lines, then save and redeploy so the new values reach the app.'),
                ],
                'tips' => [
                    __('Previews inherit these variables and linked secrets on their next build — edit here, not on the preview site.'),
                    __('Declare secret names in dply.yaml; put their values only here.'),
                ],
            ])
        </section>

        <section class="space-y-8 px-5 py-8 sm:px-10 sm:py-10">
            <div>
                <p class="text-2xs font-semibold uppercase tracking-[0.16em] text-brand-mist">{{ __('Environment') }}</p>
                <p class="mt-3 max-w-3xl text-2xl font-medium leading-snug tracking-tight text-brand-ink sm:text-3xl">
                    @if ($total === 0)
                        {{ __('Your app gets no variables yet.') }}
                    @else
                        {{ __('Your app gets') }} <span class="text-brand-sage">{{ trans_choice(':count variable|:count variables', $total) }}</span> {{ __('on its next deploy:') }}
                        {{ collect([
                            $mine > 0 ? trans_choice(':count you set|:count you set', $mine) : null,
                            $linked->isNotEmpty() ? trans_choice(':count org secret|:count org secrets', $linked->count()) : null,
                            $injected->isNotEmpty() ? __(':count from its resources', ['count' => $injected->count()]) : null,
                            $repoPublic !== [] ? __(':count from :file', ['count' => count($repoPublic), 'file' => $sourcePath]) : null,
                        ])->filter()->join(', ', ' '.__('and').' ') }}.
                    @endif
                    @if ($missingSecrets !== [])
                        <span class="text-rose-600 dark:text-rose-300">{{ $keysList($missingSecrets, 2) }}</span>
                        {{ trans_choice('is declared in :file but has no value.|are declared in :file but have no value.', count($missingSecrets), ['file' => $sourcePath]) }}
                    @endif
                    @if ($pending)
                        <span class="text-amber-600 dark:text-amber-300">{{ __('You have unsaved changes.') }}</span>
                    @endif
                </p>
            </div>

            <div>
                <p class="border-b border-brand-ink/10 pb-2 text-sm font-semibold text-brand-ink">{{ __('Variables') }}</p>
                <button type="button" x-on:click="$dispatch('open-modal', 'env-edit')" class="{{ $row }}">
                    <span class="flex-1 text-sm text-brand-ink sm:text-base">
                        {{ $mine > 0 ? trans_choice(':count variable you set for this app|:count variables you set for this app', $mine) : __('No variables set for this app yet') }}
                        @if ($mine > 0) <span class="font-mono text-xs text-brand-moss">· {{ $keysList($envKeys) }}</span>@endif
                    </span>
                    <span class="shrink-0 text-xs text-brand-moss">{{ $canEdit ? __('Edit') : __('View') }}</span>
                    <x-heroicon-m-chevron-right class="h-4 w-4 shrink-0 text-brand-mist" aria-hidden="true" />
                </button>
                @foreach ($missingSecrets as $key)
                    <button type="button" @if ($canEdit) wire:click="openEnvForKey(@js($key))" @endif class="{{ $row }}" @disabled(! $canEdit)>
                        <span class="flex-1 text-sm text-brand-ink sm:text-base"><span class="font-mono">{{ $key }}</span> {{ __('is declared in :file and needs a value', ['file' => $sourcePath]) }}</span>
                        <span class="shrink-0 text-xs text-rose-600 dark:text-rose-300">{{ __('Missing') }}</span>
                        <x-heroicon-m-chevron-right class="h-4 w-4 shrink-0 text-brand-mist" aria-hidden="true" />
                    </button>
                @endforeach
                <button type="button" x-on:click="$dispatch('open-modal', 'env-secrets')" class="{{ $row }}">
                    <span class="flex-1 text-sm text-brand-ink sm:text-base">
                        {{ $linked->isEmpty() ? __('No org secrets linked') : trans_choice(':count org secret linked|:count org secrets linked', $linked->count()) }}
                        @if ($linked->isNotEmpty()) <span class="font-mono text-xs text-brand-moss">· {{ $keysList($linked->pluck('key')) }}</span>@endif
                    </span>
                    <span class="shrink-0 text-xs text-brand-moss">{{ __('Vault') }}</span>
                    <x-heroicon-m-chevron-right class="h-4 w-4 shrink-0 text-brand-mist" aria-hidden="true" />
                </button>
                @if ($resourceInjections !== [])
                    <button type="button" x-on:click="$dispatch('open-modal', 'env-resources')" class="{{ $row }}">
                        <span class="flex-1 text-sm text-brand-ink sm:text-base">
                            {{ trans_choice(':count added from resources|:count added from resources', $injected->count()) }}
                            <span class="font-mono text-xs text-brand-moss">· {{ $keysList($injected->pluck('key')) }}</span>
                        </span>
                        <span class="shrink-0 text-xs text-brand-moss">{{ __('Automatic') }}</span>
                        <x-heroicon-m-chevron-right class="h-4 w-4 shrink-0 text-brand-mist" aria-hidden="true" />
                    </button>
                @endif
                <button type="button" x-on:click="$dispatch('open-modal', 'env-yaml')" class="{{ $row }}">
                    <span class="flex-1 text-sm text-brand-ink sm:text-base">
                        {{ ($repoPublic === [] && $repoSecret === []) ? __('Nothing declared in :file', ['file' => $sourcePath]) : trans_choice(':count declared in :file|:count declared in :file', count($repoPublic) + count($repoSecret), ['file' => $sourcePath]) }}
                    </span>
                    <span class="shrink-0 text-xs text-brand-moss">{{ $sourcePath }}</span>
                    <x-heroicon-m-chevron-right class="h-4 w-4 shrink-0 text-brand-mist" aria-hidden="true" />
                </button>
                @if ($canEdit && method_exists($this, 'openLinkOrganizationSecretModal'))
                    <button type="button" wire:click="openLinkOrganizationSecretModal" class="{{ $row }} font-medium text-brand-sage">
                        <x-heroicon-m-plus class="h-4 w-4 shrink-0" aria-hidden="true" />
                        <span class="flex-1 text-sm sm:text-base">{{ __('Link a secret from the org vault') }}</span>
                    </button>
                @endif
            </div>
        </section>

        {{-- This app's variables --}}
        <x-modal name="env-edit" maxWidth="3xl" overlayClass="bg-brand-ink/40" focusable>
            <div class="space-y-4 p-6 sm:p-7">
                <div class="flex items-start justify-between gap-4">
                    <div>
                        <h2 class="text-lg font-semibold text-brand-ink">{{ __('This app’s variables') }}</h2>
                        <p class="mt-0.5 text-sm text-brand-moss">{{ $canEdit ? __('One KEY=value per line. Stored encrypted and sent only to your app. They apply on the next deploy.') : __('Values are hidden. People who can edit this app see and change them.') }}</p>
                    </div>
                    {!! $close('env-edit') !!}
                </div>
                @if ($canEdit)
                    <textarea wire:model="edgeEnvText" rows="16" spellcheck="false" aria-label="{{ __('Variables') }}" placeholder="APP_DEBUG=false" class="dply-input mt-0 font-mono text-xs leading-5"></textarea>
                    @error('edgeEnvText') <x-sheet.note tone="danger">{{ $message }}</x-sheet.note> @enderror
                    <div class="flex flex-wrap justify-end gap-2">
                        <x-sheet.button type="button" wire:click="discardEdgeEnv" x-on:click="$dispatch('close-modal', 'env-edit')">{{ __('Cancel') }}</x-sheet.button>
                        <x-sheet.button type="button" wire:click="saveEdgeEnvText" wire:loading.attr="disabled" wire:target="saveEdgeEnvText">{{ __('Save') }}</x-sheet.button>
                        <x-sheet.button type="button" variant="primary" wire:click="redeployEdgeEnv" wire:loading.attr="disabled" wire:target="redeployEdgeEnv">
                            <span wire:loading.remove wire:target="redeployEdgeEnv">{{ __('Save and redeploy') }}</span>
                            <span wire:loading wire:target="redeployEdgeEnv">{{ __('Deploying…') }}</span>
                        </x-sheet.button>
                    </div>
                @else
                    <ul class="divide-y divide-brand-ink/10 border-y border-brand-ink/10 font-mono text-sm text-brand-ink">
                        @forelse ($envKeys as $key)
                            <li class="py-2">{{ $key }}</li>
                        @empty
                            <li class="py-2 font-sans text-brand-moss">{{ __('No variables set yet.') }}</li>
                        @endforelse
                    </ul>
                @endif
            </div>
        </x-modal>

        {{-- Linked org secrets --}}
        <x-modal name="env-secrets" maxWidth="xl" overlayClass="bg-brand-ink/40" focusable>
            <div class="space-y-4 p-6 sm:p-7">
                <div class="flex items-start justify-between gap-4">
                    <div>
                        <h2 class="text-lg font-semibold text-brand-ink">{{ __('Org secrets linked to this app') }}</h2>
                        <p class="mt-0.5 text-sm text-brand-moss">{{ __('Kept in the org vault and injected on the next deploy. They can’t be read back.') }}</p>
                    </div>
                    {!! $close('env-secrets') !!}
                </div>
                <ul class="divide-y divide-brand-ink/10 border-y border-brand-ink/10">
                    @forelse ($linked as $secret)
                        <li class="flex items-center gap-3 py-2.5" wire:key="env-linked-{{ $secret['id'] }}">
                            <span class="min-w-0 flex-1">
                                <span class="block truncate font-mono text-sm text-brand-ink">{{ $secret['key'] }}</span>
                                <span class="block text-xs text-brand-moss">
                                    {{ collect([
                                        filled($secret['notes'] ?? null) ? $secret['notes'] : null,
                                        $secret['overrides_site'] ? __('wins over the same key in this app’s variables') : null,
                                        $secret['binding_owned'] ? __('a connected resource sets this key; it wins unless this app’s variables override it') : null,
                                    ])->filter()->implode(' · ') }}
                                </span>
                            </span>
                            @if ($canEdit)
                                <button type="button" x-on:click="$dispatch('close-modal', 'env-secrets')" wire:click="openConfirmActionModal('unlinkOrganizationSecret', @js([$secret['id']]), @js(__('Unlink secret')), @js(__('Unlink :key from this site? The key drops on the next deploy. The org secret is kept.', ['key' => $secret['key']])), @js(__('Unlink')), true)" class="shrink-0 text-xs font-medium text-rose-600 hover:underline dark:text-rose-300">{{ __('Unlink') }}</button>
                            @endif
                        </li>
                    @empty
                        <li class="py-3 text-sm text-brand-moss">{{ __('No secrets linked. Link one from the org vault, or paste a new one.') }}</li>
                    @endforelse
                </ul>
                @if ($canEdit && method_exists($this, 'openLinkOrganizationSecretModal'))
                    <div class="flex justify-end">
                        <x-sheet.button type="button" variant="primary" x-on:click="$dispatch('close-modal', 'env-secrets')" wire:click="openLinkOrganizationSecretModal">{{ __('Link or paste a secret') }}</x-sheet.button>
                    </div>
                @endif
            </div>
        </x-modal>

        {{-- Added from resources --}}
        @if ($resourceInjections !== [])
            <x-modal name="env-resources" maxWidth="xl" overlayClass="bg-brand-ink/40" focusable>
                <div class="space-y-4 p-6 sm:p-7">
                    <div class="flex items-start justify-between gap-4">
                        <div>
                            <h2 class="text-lg font-semibold text-brand-ink">{{ __('Added from resources') }}</h2>
                            <p class="mt-0.5 text-sm text-brand-moss">{{ __('Set on the next deploy from the app’s size, database and connections. A line in this app’s variables replaces the same key.') }}</p>
                        </div>
                        {!! $close('env-resources') !!}
                    </div>
                    <ul class="divide-y divide-brand-ink/10 border-y border-brand-ink/10">
                        @foreach ($resourceInjections as $inj)
                            <li class="flex flex-wrap items-baseline justify-between gap-x-3 gap-y-1 py-2 font-mono text-xs" wire:key="env-resource-{{ $inj['key'] }}">
                                <span @class(['text-brand-ink', 'line-through opacity-60' => $inj['overridden']])>{{ $inj['key'] }}={{ $inj['value'] }}</span>
                                <span class="font-sans text-brand-moss">{{ $inj['from'] }}@if ($inj['overridden']) · {{ __('replaced by your value') }}@endif</span>
                            </li>
                        @endforeach
                    </ul>
                    <a href="{{ route('sites.show', ['server' => $server, 'site' => $site, 'section' => 'general']) }}" wire:navigate class="text-sm font-medium text-brand-sage hover:underline">{{ __('Change resources on Overview') }}</a>
                </div>
            </x-modal>
        @endif

        {{-- dply.yaml --}}
        <x-modal name="env-yaml" maxWidth="xl" overlayClass="bg-brand-ink/40" focusable>
            <div class="space-y-4 p-6 sm:p-7">
                <div class="flex items-start justify-between gap-4">
                    <div>
                        <h2 class="text-lg font-semibold text-brand-ink">{{ __('Declared in :file', ['file' => $sourcePath]) }}</h2>
                        <p class="mt-0.5 text-sm text-brand-moss">{{ __('Public values can live in the repo. For secrets, list only the names there and set the values here.') }}</p>
                    </div>
                    {!! $close('env-yaml') !!}
                </div>
                @if ($repoPublic !== [] || $repoSecret !== [])
                    <ul class="divide-y divide-brand-ink/10 border-y border-brand-ink/10 font-mono text-xs">
                        @foreach ($repoPublic as $name => $value)
                            <li class="flex flex-wrap justify-between gap-2 py-2"><span class="text-brand-ink">{{ $name }}={{ $value }}</span><span class="font-sans text-brand-moss">{{ __('public') }}</span></li>
                        @endforeach
                        @foreach ($repoSecret as $name)
                            @php $isMissing = in_array($name, $missingSecrets, true); @endphp
                            <li class="flex flex-wrap justify-between gap-2 py-2">
                                <span class="{{ $isMissing ? 'text-rose-600 dark:text-rose-300' : 'text-brand-ink' }}">{{ $name }}</span>
                                <span class="font-sans {{ $isMissing ? 'text-rose-600 dark:text-rose-300' : 'text-brand-sage' }}">{{ $isMissing ? __('secret · no value yet') : __('secret · set') }}</span>
                            </li>
                        @endforeach
                    </ul>
                @else
                    <p class="text-sm text-brand-moss">{{ __('None declared yet.') }}</p>
                @endif
                <x-edge-yaml-example :file="$sourcePath">env:
  public:
    APP_ENV: "production"
  secret:
    - "DATABASE_URL"
    - "APP_KEY"</x-edge-yaml-example>
                <a href="{{ route('sites.edge.dply-yaml', ['server' => $site->server_id, 'site' => $site->id]) }}" class="inline-flex items-center gap-1 text-sm font-medium text-brand-sage hover:underline">{{ __('Generate :file', ['file' => $sourcePath]) }}</a>
            </div>
        </x-modal>

        @if (method_exists($this, 'openLinkOrganizationSecretModal'))
            @include('livewire.sites.partials.link-organization-secret-modal')
        @endif
    @endif

    @include('livewire.partials.confirm-action-modal')
</div>
