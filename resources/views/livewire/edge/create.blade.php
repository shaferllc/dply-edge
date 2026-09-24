@php
    $gitConnected = $linkedSourceControlAccounts !== [];
    $repoChosen = trim($repo) !== '';
    $launched = $launchedDeploymentId !== '';
    $step1Done = $gitConnected || $repoChosen;
    $step2Done = $repoChosen;
    $currentStep = $launched ? 3 : ($step2Done ? 3 : ($step1Done ? 2 : 1));
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
                        <x-connect-provider-link class="!inline-flex !items-center !rounded-xl !bg-brand-ink !px-4 !py-2 !text-sm !font-semibold !text-brand-cream !no-underline hover:!bg-brand-forest">
                            {{ __('Connect GitHub, GitLab, or Bitbucket') }}
                        </x-connect-provider-link>
                        <div>
                            <x-input-label for="repo" :value="__('Or paste a repository')" />
                            <x-text-input id="repo" wire:model.live.debounce.500ms="repo" type="text" class="mt-1 block w-full font-mono text-sm" placeholder="owner/repo" />
                            <p class="mt-1 text-xs text-brand-mist">{{ __('owner/repo or a full GitHub URL') }}</p>
                            <x-input-error :messages="$errors->get('repo')" class="mt-2" />
                        </div>
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
                        @if (count($linkedSourceControlAccounts) > 1)
                            <div>
                                <x-input-label for="source_control_account_id" :value="__('Account')" />
                                <select id="source_control_account_id" wire:model.live="source_control_account_id" class="dply-input mt-1 block w-full">
                                    @foreach ($linkedSourceControlAccounts as $account)
                                        <option value="{{ $account['id'] }}">{{ $account['label'] ?? $account['id'] }}</option>
                                    @endforeach
                                </select>
                            </div>
                        @endif

                        @if ($repo_source === 'connected')
                            @if ($availableRepositories !== [])
                                <x-repo-combobox
                                    :repositories="$availableRepositories"
                                    property="repository_selection"
                                    target="source_control_account_id"
                                    trigger-id="repository_selection"
                                    :selected="$repository_selection"
                                    :placeholder="__('Select a repository…')"
                                />
                            @else
                                <p class="text-sm text-brand-moss">{{ __('No repositories returned for this account. Paste the repository URL instead.') }}</p>
                            @endif
                            <x-repo-access-hint :accounts="$linkedSourceControlAccounts" :selected="$source_control_account_id" />
                            <button type="button" wire:click="$set('repo_source', 'manual')" class="text-xs font-semibold text-brand-forest hover:underline dark:text-brand-sage">
                                {{ __('Paste a URL instead') }}
                            </button>
                        @else
                            <x-text-input id="repo" wire:model.live.debounce.500ms="repo" type="text" class="block w-full font-mono text-sm" placeholder="owner/repo" />
                            <p class="text-xs text-brand-mist">{{ __('owner/repo or a full GitHub URL') }}</p>
                            @if ($gitConnected)
                                <button type="button" wire:click="$set('repo_source', 'connected')" class="text-xs font-semibold text-brand-forest hover:underline dark:text-brand-sage">
                                    {{ __('Pick from your account') }}
                                </button>
                            @endif
                        @endif
                        <x-input-error :messages="$errors->get('repo')" class="mt-2" />
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
                    <div class="mt-4">
                        <livewire:edge.build-journey
                            :deployment-id="$launchedDeploymentId"
                            :log-only="true"
                            :key="'create-build-'.$launchedDeploymentId"
                        />
                    </div>
                @elseif ($currentStep === 3)
                    <div class="mt-4 space-y-4">
                        <p class="text-sm text-brand-moss">{{ __('Name it. Everything else is detected.') }}</p>
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
