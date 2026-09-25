@php
    $gitConnected = $linkedSourceControlAccounts !== [];
    $repoChosen = trim($repo) !== '';
    $launched = $launchedDeploymentId !== '';
    $currentStep = $launched ? 3 : $wizardStep;
    $step1Done = $currentStep > 1;
    $step2Done = $currentStep > 2;
    $matchedAccount = collect($linkedSourceControlAccounts)->firstWhere('id', $source_control_account_id);
    $accountLabel = is_array($matchedAccount)
        ? (string) ($matchedAccount['label'] ?? '')
        : (string) ($linkedSourceControlAccounts[0]['label'] ?? '');
    $runtimeLabel = match ($form->runtime_mode) {
        'hybrid' => __('Hybrid'),
        'ssr' => __('Worker SSR'),
        'container' => __('Container'),
        default => __('Static'),
    };
    $frameworkSummary = trim((string) ($detectedPlan['framework'] ?? $detectedPlan['runtime'] ?? ''));
    $deployBlocked = ! $edgeEligible
        || trim($form->name) === ''
        || trim($repo) === ''
        || trim($branch) === ''
        || $runtimeDetectionPending
        || ($form->runtime_mode === 'hybrid' && trim($form->origin_url) === '');
@endphp

<div
    class="mx-auto max-w-xl px-4 py-10 sm:px-6"
    @if ($runtimeDetectionPending && ! $launched) wire:poll.2s="pollRuntimeDetection" @endif
>
    <x-livewire-validation-errors class="mb-6" />

    <h1 class="text-2xl font-semibold tracking-tight text-brand-ink">{{ __('Create an app') }}</h1>
    <p class="mt-1 text-sm text-brand-moss">{{ __('Connect a repository. We detect the stack and deploy it.') }}</p>

    <div
        wire:loading.flex
        wire:target="nextStep"
        class="fixed inset-0 z-[80] items-center justify-center bg-black/50"
    >
        <div class="flex items-center gap-3 rounded-2xl px-5 py-4 text-sm font-semibold shadow-xl" style="background:#ffffff;color:#18181b">
            <x-spinner size="sm" variant="zinc" style="color:#3f3f46" />
            {{ $currentStep === 1 ? __('Loading repositories…') : __('Continuing to the next step…') }}
        </div>
    </div>
    <div
        wire:loading.flex
        wire:target="reloadRepositories"
        class="fixed inset-0 z-[80] items-center justify-center bg-black/50"
    >
        <div class="flex items-center gap-3 rounded-2xl px-5 py-4 text-sm font-semibold shadow-xl" style="background:#ffffff;color:#18181b">
            <x-spinner size="sm" variant="zinc" style="color:#3f3f46" />
            {{ __('Loading repositories…') }}
        </div>
    </div>

    <form wire:submit="deploy" class="mt-8">
        <ol>
            <li class="relative pb-8 ps-10">
                <span class="absolute start-0 top-0 flex h-6 w-6 items-center justify-center rounded-full {{ $step1Done ? 'bg-emerald-600 text-white' : 'border border-brand-ink/20 bg-white text-brand-moss dark:bg-zinc-900' }}">
                    @if ($step1Done)
                        <x-heroicon-s-check class="h-3.5 w-3.5" />
                    @endif
                </span>
                <span class="absolute start-[11px] top-6 bottom-0 w-px bg-brand-ink/10" aria-hidden="true"></span>
                <p class="text-sm {{ $currentStep === 1 ? 'font-semibold text-brand-ink' : 'text-brand-moss' }}">
                    {{ __('Step 1 of 3') }} · {{ __('Connect your source control') }}
                </p>
                @if ($step1Done && $accountLabel !== '')
                    <p class="mt-0.5 text-xs text-brand-moss">{{ $accountLabel }}</p>
                @endif
                @if ($currentStep === 1)
                    <div class="mt-4 space-y-4">
                        @if ($gitConnected)
                            <ul class="divide-y divide-brand-ink/10 overflow-hidden rounded-xl border border-brand-ink/10">
                                @foreach ($linkedSourceControlAccounts as $account)
                                    @php
                                        $accountConnected = ($account['connected'] ?? false) === true;
                                        $accountSelected = $source_control_account_id === $account['id'];
                                    @endphp
                                    <li>
                                        <button
                                            type="button"
                                            wire:click="selectSourceControlAccount('{{ $account['id'] }}')"
                                            aria-pressed="{{ $accountSelected ? 'true' : 'false' }}"
                                            @class([
                                                'flex w-full items-center justify-between gap-3 px-3 py-2 text-left text-sm text-brand-ink',
                                                'bg-brand-sand/40' => $accountSelected,
                                                'hover:bg-brand-sand/30' => ! $accountSelected,
                                            ])
                                        >
                                            <span class="flex min-w-0 items-center gap-2">
                                                <span @class([
                                                    'flex h-4 w-4 shrink-0 items-center justify-center rounded-full border',
                                                    'border-brand-ink bg-brand-ink text-brand-cream' => $accountSelected,
                                                    'border-brand-ink/30' => ! $accountSelected,
                                                ]) aria-hidden="true">
                                                    @if ($accountSelected)
                                                        <span class="h-1.5 w-1.5 rounded-full bg-brand-cream"></span>
                                                    @endif
                                                </span>
                                                <span class="truncate font-medium">{{ $account['label'] }}</span>
                                            </span>
                                            <span @class([
                                                'shrink-0 text-xs font-semibold',
                                                'text-brand-moss' => $accountConnected,
                                                'text-rose-600' => ! $accountConnected,
                                            ])>{{ $accountConnected ? __('Connected') : __('Disconnected') }}</span>
                                        </button>
                                    </li>
                                @endforeach
                            </ul>
                        @endif
                        <x-connect-provider-link @class([
                            '!inline-flex !items-center !rounded-xl !px-4 !py-2 !text-sm !font-semibold !no-underline',
                            '!bg-brand-ink !text-brand-cream hover:!bg-brand-forest' => ! $gitConnected,
                            '!border !border-brand-ink/15 !bg-transparent !text-brand-ink hover:!bg-brand-sand/30' => $gitConnected,
                        ])>
                            {{ $gitConnected ? __('Connect another account') : __('Connect GitHub, GitLab, or Bitbucket') }}
                        </x-connect-provider-link>
                        <div>
                            <x-input-label for="repo" :value="__('Or paste a repository')" />
                            <x-text-input id="repo" wire:model.live.debounce.500ms="repo" type="text" class="mt-1 block w-full font-mono text-sm" placeholder="owner/repo" />
                            <p class="mt-1 text-xs text-brand-mist">{{ __('owner/repo or a full GitHub URL') }}</p>
                            <x-input-error :messages="$errors->get('repo')" class="mt-2" />
                        </div>
                        <x-primary-button type="button" wire:click="nextStep" :disabled="! $gitConnected && ! $repoChosen">
                            {{ __('Next') }}
                        </x-primary-button>
                    </div>
                @endif
            </li>

            <li class="relative pb-8 ps-10">
                <span class="absolute start-0 top-0 flex h-6 w-6 items-center justify-center rounded-full {{ $step2Done ? 'bg-emerald-600 text-white' : ($currentStep === 2 ? 'border-2 border-brand-ink bg-white dark:bg-zinc-900' : 'border border-brand-ink/15 bg-white dark:bg-zinc-900') }}">
                    @if ($step2Done)
                        <x-heroicon-s-check class="h-3.5 w-3.5 text-white" />
                    @endif
                </span>
                <span class="absolute start-[11px] top-6 bottom-0 w-px bg-brand-ink/10" aria-hidden="true"></span>
                <p class="text-sm {{ $currentStep === 2 ? 'font-semibold text-brand-ink' : 'text-brand-moss' }}">
                    {{ __('Step 2 of 3') }} · {{ __('Select a repository') }}
                </p>
                @if ($currentStep === 2)
                    <div class="mt-4 space-y-3">
                        @if ($accountLabel !== '')
                            <div class="flex items-center justify-between gap-3">
                                <p class="text-sm text-brand-ink">{{ $accountLabel }}</p>
                                <button type="button" wire:click="reloadRepositories" class="text-xs font-semibold text-brand-forest underline hover:text-brand-ink dark:text-brand-sage">{{ __('Reload') }}</button>
                            </div>
                        @endif

                        @if ($repo_source === 'connected')
                            @if ($repositoryLoadError)
                                <p class="text-sm font-medium text-rose-600">{{ $repositoryLoadError }}</p>
                                <a href="{{ route('profile.source-control') }}" wire:navigate class="text-xs font-semibold text-brand-forest underline hover:text-brand-ink dark:text-brand-sage">{{ __('Open Source control') }}</a>
                            @elseif ($availableRepositories !== [])
                                <x-repo-combobox
                                    :repositories="$availableRepositories"
                                    property="repository_selection"
                                    target="reloadRepositories"
                                    trigger-id="repository_selection"
                                    :selected="$repository_selection"
                                    :placeholder="__('Select a repository…')"
                                />
                            @else
                                <p class="text-sm text-brand-moss">{{ __('No repositories returned for this account. Paste the repository URL instead.') }}</p>
                            @endif
                            @if (! $repositoryLoadError)
                                <x-repo-access-hint :accounts="$linkedSourceControlAccounts" :selected="$source_control_account_id" />
                            @endif
                        @endif
                        <div>
                            <x-input-label for="repo" :value="__('Repository URL')" />
                            <x-text-input id="repo" wire:model.live.debounce.500ms="repo" type="text" class="mt-1 block w-full font-mono text-sm" placeholder="https://github.com/owner/repo" />
                            <p class="mt-1 text-xs text-brand-mist">{{ __('A GitHub, GitLab, or Bitbucket URL, or owner/name.') }}</p>
                        </div>
                        <x-input-error :messages="$errors->get('repo')" class="mt-2" />
                        <x-primary-button type="button" wire:click="nextStep" :disabled="! $repoChosen">
                            {{ __('Next') }}
                        </x-primary-button>
                    </div>
                @elseif ($step2Done)
                    <p class="mt-0.5 font-mono text-xs text-brand-moss">{{ $repo }}</p>
                @endif
            </li>

            <li class="relative ps-10">
                <span class="absolute start-0 top-0 flex h-6 w-6 items-center justify-center rounded-full {{ $currentStep === 3 ? 'border-2 border-brand-ink bg-white dark:bg-zinc-900' : 'border border-brand-ink/15 bg-white dark:bg-zinc-900' }}">
                    @if ($launched)
                        <span class="h-2 w-2 rounded-full bg-brand-sage"></span>
                    @endif
                </span>
                <p class="text-sm {{ $currentStep === 3 ? 'font-semibold text-brand-ink' : 'text-brand-moss' }}">
                    {{ __('Step 3 of 3') }} · {{ __('Create your application') }}
                </p>

                @if ($launched)
                    <div class="mt-4 space-y-4" wire:init="loadRepoRefs">
                        @include('livewire.edge.partials.repo-ref-select')
                        <x-secondary-button type="button" wire:click="redeploySelectedRef" wire:loading.attr="disabled" wire:target="redeploySelectedRef">
                            <span wire:loading.remove wire:target="redeploySelectedRef">{{ __('Deploy this branch or tag') }}</span>
                            <span wire:loading wire:target="redeploySelectedRef">{{ __('Deploying…') }}</span>
                        </x-secondary-button>
                        <livewire:edge.build-journey
                            :deployment-id="$launchedDeploymentId"
                            :log-only="true"
                            :key="'create-build-'.$launchedDeploymentId"
                        />
                    </div>
                @elseif ($currentStep === 3)
                    <div class="mt-4 space-y-4">
                        <p class="text-sm text-brand-moss">{{ __('Name it. Pick the branch or tag to deploy.') }}</p>
                        @include('livewire.edge.partials.repo-ref-select')
                        <div>
                            <x-input-label for="name" :value="__('App name')" />
                            <x-text-input id="name" wire:model.live="form.name" type="text" class="mt-1 block w-full" required placeholder="marketing-site" />
                            <x-input-error :messages="$errors->get('form.name')" class="mt-2" />
                        </div>

                        @if ($runtimeDetectionPending)
                            <p class="flex items-center gap-2 text-sm text-brand-moss">
                                <x-spinner size="sm" variant="ink" />
                                {{ __('Detecting how to build this…') }}
                            </p>
                        @elseif ($frameworkSummary !== '')
                            <p class="text-sm text-brand-moss">{{ $frameworkSummary }} · {{ $runtimeLabel }}</p>
                        @endif

                        @if ($monorepoDetected && count($monorepoPackages) > 1)
                            <fieldset class="space-y-2">
                                <legend class="text-sm font-semibold text-brand-ink">{{ __('Which package should we deploy?') }}</legend>
                                @foreach ($monorepoPackages as $package)
                                    <label class="flex items-start gap-3 rounded-xl border border-brand-ink/10 px-3 py-2 text-sm dark:border-brand-mist/20">
                                        <input type="radio" wire:model.live="form.repo_root" value="{{ $package['path'] }}" class="mt-0.5 text-brand-sage focus:ring-brand-sage/40" />
                                        <span>
                                            <span class="font-mono text-brand-ink">{{ $package['path'] !== '' ? $package['path'] : '/' }}</span>
                                            <span class="mt-0.5 block text-xs text-brand-moss">{{ $package['label'] }}</span>
                                        </span>
                                    </label>
                                @endforeach
                            </fieldset>
                        @endif

                        @if ($form->runtime_mode === 'hybrid')
                            <div>
                                <x-input-label for="origin_url" :value="__('Origin URL')" />
                                <x-text-input id="origin_url" wire:model.live="form.origin_url" type="url" class="mt-1 block w-full font-mono text-sm" placeholder="https://my-app.example.com" required />
                                <p class="mt-1 text-xs text-brand-moss">{{ __('This app renders on a server. Paste the URL it already runs at.') }}</p>
                                <x-input-error :messages="$errors->get('form.origin_url')" class="mt-2" />
                            </div>
                        @endif

                        @if (! $edgeEligible && filled($edgeIneligibleMessage))
                            <div class="rounded-xl border border-amber-200/80 bg-amber-50 px-4 py-3 text-sm text-amber-950 dark:border-amber-900/40 dark:bg-amber-950/30 dark:text-amber-100">
                                <p>{{ $edgeIneligibleMessage }}</p>
                            </div>
                        @endif

                        <x-primary-button type="submit" wire:loading.attr="disabled" wire:target="deploy" :disabled="$deployBlocked">
                            <span wire:loading.remove wire:target="deploy">{{ __('Deploy') }}</span>
                            <span wire:loading wire:target="deploy">{{ __('Deploying…') }}</span>
                        </x-primary-button>
                    </div>
                @endif
            </li>
        </ol>
    </form>
</div>
