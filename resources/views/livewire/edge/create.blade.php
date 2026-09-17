<div class="mx-auto max-w-7xl px-4 py-8 sm:px-6 lg:px-8">
    <x-breadcrumb-trail :items="[
        ['label' => __('Dashboard'), 'href' => route('dashboard'), 'icon' => 'home'],
        ['label' => __('Projects'), 'href' => route('edge.index'), 'icon' => 'globe-alt'],
        ['label' => __('Create'), 'icon' => 'plus'],
    ]" />

    <x-livewire-validation-errors class="mt-4" />

    <form wire:submit="deploy" class="mt-4">
        <div class="grid gap-6 lg:grid-cols-[minmax(0,2fr)_minmax(18rem,1fr)] lg:items-start">
            <div class="min-w-0">
                <x-profile-shell
                    :title="__('Deploy an edge app')"
                    :description="__('Connect a static/SSG, Keel, or hybrid JS SSR repo — we build and publish to the edge.')"
                    icon="heroicon-o-globe-alt"
                >
                    <x-slot:actions>
                        <x-outline-link href="{{ route('edge.index') }}" wire:navigate>
                            <x-heroicon-o-arrow-left class="h-4 w-4 shrink-0 opacity-90" aria-hidden="true" />
                            {{ __('Back to Edge') }}
                        </x-outline-link>
                    </x-slot:actions>

                    <x-slot:stats>
                        <div class="flex flex-wrap gap-2 text-xs text-brand-moss">
                            <span class="inline-flex items-center gap-1.5 rounded-lg border border-brand-ink/10 bg-white/80 px-2.5 py-1 dark:border-brand-mist/25 dark:bg-zinc-800/80">
                                <x-heroicon-o-bolt class="h-3.5 w-3.5 text-brand-gold" aria-hidden="true" />
                                {{ __('Instant HTTPS') }}
                            </span>
                            <span class="inline-flex items-center gap-1.5 rounded-lg border border-brand-ink/10 bg-white/80 px-2.5 py-1 dark:border-brand-mist/25 dark:bg-zinc-800/80">
                                <x-heroicon-o-arrow-path class="h-3.5 w-3.5 text-brand-sage" aria-hidden="true" />
                                {{ __('Preview branches') }}
                            </span>
                            <span class="inline-flex items-center gap-1.5 rounded-lg border border-brand-ink/10 bg-white/80 px-2.5 py-1 dark:border-brand-mist/25 dark:bg-zinc-800/80">
                                <x-heroicon-o-cloud-arrow-up class="h-3.5 w-3.5 text-brand-forest dark:text-brand-sage" aria-hidden="true" />
                                {{ __('Deploy on push') }}
                            </span>
                        </div>
                    </x-slot:stats>

                    @if ($fakeEdgeActive)
                        <div data-testid="fake-edge-active-notice" class="flex flex-col gap-3 border-b border-brand-ink/10 bg-sky-50/70 px-5 py-3.5 text-sm text-sky-950 sm:flex-row sm:items-center sm:justify-between dark:border-sky-900/40 dark:bg-sky-950/30 dark:text-raw-sky-200 sm:px-6">
                            <div class="flex gap-3">
                                <span class="inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-xl bg-sky-100 text-sky-700 dark:bg-sky-900/50 dark:text-sky-300" aria-hidden="true">
                                    <x-heroicon-o-beaker class="h-5 w-5" />
                                </span>
                                <div>
                                    <p class="font-semibold">{{ __('Sandbox mode is on — no real edge account needed') }}</p>
                                    <p class="mt-0.5 text-sky-900/80 dark:text-raw-sky-200/80">{{ __('Builds land on a local sandbox with synthetic hostnames.') }}</p>
                                </div>
                            </div>
                            @if ($localSampleAppAvailable)
                                <button
                                    type="button"
                                    wire:click="loadSampleApp"
                                    wire:loading.attr="disabled"
                                    wire:target="loadSampleApp"
                                    data-testid="load-sample-edge-app"
                                    class="inline-flex shrink-0 items-center justify-center gap-2 rounded-xl bg-sky-900 px-3.5 py-2 text-sm font-semibold text-sky-50 transition-colors hover:bg-sky-800 disabled:opacity-60 dark:bg-raw-sky-200 dark:text-sky-950 dark:hover:bg-raw-sky-100"
                                >
                                    <x-heroicon-o-sparkles wire:loading.remove wire:target="loadSampleApp" class="h-4 w-4" aria-hidden="true" />
                                    <x-spinner wire:loading wire:target="loadSampleApp" size="sm" variant="cream" />
                                    <span wire:loading.remove wire:target="loadSampleApp">{{ __('Load sample app') }}</span>
                                    <span wire:loading wire:target="loadSampleApp">{{ __('Loading…') }}</span>
                                </button>
                            @endif
                        </div>
                    @elseif ($localSampleAppAvailable)
                        <div class="flex flex-col gap-3 border-b border-brand-ink/10 bg-brand-sand/25 px-5 py-3.5 text-sm sm:flex-row sm:items-center sm:justify-between sm:px-6 dark:bg-brand-sand/10">
                            <div class="flex gap-3">
                                <span class="inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-xl bg-brand-sage/15 text-brand-forest dark:text-brand-sage" aria-hidden="true">
                                    <x-heroicon-o-beaker class="h-5 w-5" />
                                </span>
                                <div>
                                    <p class="font-semibold text-brand-ink">{{ __('Local development') }}</p>
                                    <p class="mt-0.5 text-brand-moss">{{ __('Prefill a public Eleventy blog to try create → detect → deploy.') }}</p>
                                </div>
                            </div>
                            <button
                                type="button"
                                wire:click="loadSampleApp"
                                wire:loading.attr="disabled"
                                wire:target="loadSampleApp"
                                data-testid="load-sample-edge-app"
                                class="inline-flex shrink-0 items-center justify-center gap-2 rounded-xl bg-brand-ink px-3.5 py-2 text-sm font-semibold text-brand-cream transition-colors hover:bg-brand-forest disabled:opacity-60"
                            >
                                <x-heroicon-o-sparkles wire:loading.remove wire:target="loadSampleApp" class="h-4 w-4" aria-hidden="true" />
                                <x-spinner wire:loading wire:target="loadSampleApp" size="sm" variant="cream" />
                                <span wire:loading.remove wire:target="loadSampleApp">{{ __('Load sample app') }}</span>
                                <span wire:loading wire:target="loadSampleApp">{{ __('Loading…') }}</span>
                            </button>
                        </div>
                    @endif

                    {{-- 01 Source --}}
                    <section class="border-b border-brand-ink/10">
                        <div class="flex items-start gap-3 bg-brand-sand/15 px-5 py-3 sm:px-6">
                            <span class="inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-xl bg-brand-sage/15 text-sm font-bold text-brand-forest ring-1 ring-brand-sage/25 dark:bg-brand-sage/15 dark:text-brand-sage dark:ring-brand-sage/30">01</span>
                            <div class="min-w-0">
                                <h2 class="text-base font-semibold text-brand-ink">{{ __('Connect Git') }}</h2>
                                <p class="mt-0.5 text-sm text-brand-moss">{{ __('Repo, branch, and app name.') }}</p>
                            </div>
                        </div>
                        <div class="min-w-0 space-y-4 px-5 py-4 sm:px-6">
                            @if ($exampleApps !== [])
                                <div data-testid="edge-example-apps" class="rounded-xl border border-brand-gold/25 bg-brand-gold/10 px-3.5 py-3 dark:border-brand-gold/20 dark:bg-brand-gold/5">
                                    <div class="flex flex-col gap-2.5 sm:flex-row sm:items-start sm:justify-between">
                                        <div class="min-w-0">
                                            <p class="text-sm font-semibold text-brand-ink">{{ __('Or start from an example') }}</p>
                                            <p class="mt-0.5 text-xs text-brand-moss">{{ __('Public starters — no Git token needed. Keel is our house Node framework.') }}</p>
                                        </div>
                                        <a href="{{ route('edge.templates') }}" wire:navigate class="shrink-0 text-xs font-semibold text-brand-forest underline-offset-2 hover:underline dark:text-brand-sage">
                                            {{ __('Browse all templates') }} →
                                        </a>
                                    </div>
                                    <div class="mt-2.5 flex flex-wrap gap-2">
                                        @foreach ($exampleApps as $example)
                                            @php
                                                $exampleSlug = (string) ($example['slug'] ?? '');
                                                $exampleName = (string) ($example['name'] ?? $exampleSlug);
                                                $exampleFramework = (string) ($example['framework'] ?? '');
                                                $isKeel = $exampleFramework === 'keel' || $exampleSlug === 'keel-workers';
                                            @endphp
                                            <button
                                                type="button"
                                                wire:click="loadExampleApp('{{ $exampleSlug }}')"
                                                wire:loading.attr="disabled"
                                                wire:target="loadExampleApp"
                                                data-testid="edge-example-{{ $exampleSlug }}"
                                                title="{{ (string) ($example['description'] ?? $exampleName) }}"
                                                @class([
                                                    'inline-flex items-center gap-1.5 rounded-lg px-3 py-1.5 text-xs font-semibold transition disabled:opacity-60',
                                                    'bg-brand-ink text-brand-cream hover:bg-brand-forest' => $isKeel,
                                                    'border border-brand-ink/15 bg-white text-brand-ink shadow-sm hover:bg-brand-sand/40 dark:border-brand-mist/25 dark:bg-zinc-800/60 dark:hover:bg-raw-zinc-700' => ! $isKeel,
                                                ])
                                            >
                                                <span wire:loading.remove wire:target="loadExampleApp">{{ $exampleName }}</span>
                                                <span wire:loading wire:target="loadExampleApp">{{ __('Loading…') }}</span>
                                            </button>
                                        @endforeach
                                    </div>
                                </div>
                            @endif

                            <div class="flex flex-wrap items-center gap-3">
                                @if ($linkedSourceControlAccounts !== [])
                                    <div role="radiogroup" aria-label="{{ __('Where to find the repo') }}" class="inline-flex rounded-xl border border-brand-ink/10 bg-brand-cream/40 p-1 text-xs dark:border-brand-mist/20 dark:bg-zinc-800/60">
                                        <button
                                            type="button"
                                            role="radio"
                                            aria-checked="{{ $repo_source === 'connected' ? 'true' : 'false' }}"
                                            wire:click="$set('repo_source', 'connected')"
                                            class="inline-flex items-center gap-1.5 rounded-lg px-3 py-1.5 font-semibold transition {{ $repo_source === 'connected' ? 'bg-white text-brand-ink shadow-sm dark:bg-raw-zinc-700' : 'text-brand-moss hover:text-brand-ink' }}"
                                        >
                                            <x-heroicon-m-link class="h-4 w-4" aria-hidden="true" />
                                            {{ __('Pick from connected account') }}
                                        </button>
                                        <button
                                            type="button"
                                            role="radio"
                                            aria-checked="{{ $repo_source === 'manual' ? 'true' : 'false' }}"
                                            wire:click="$set('repo_source', 'manual')"
                                            class="inline-flex items-center gap-1.5 rounded-lg px-3 py-1.5 font-semibold transition {{ $repo_source === 'manual' ? 'bg-white text-brand-ink shadow-sm dark:bg-raw-zinc-700' : 'text-brand-moss hover:text-brand-ink' }}"
                                        >
                                            <x-heroicon-m-pencil-square class="h-4 w-4" aria-hidden="true" />
                                            {{ __('Enter manually') }}
                                        </button>
                                    </div>
                                @endif
                                <x-connect-provider-link>{{ __('Connect a provider') }} &rarr;</x-connect-provider-link>
                            </div>

                            @if ($linkedSourceControlAccounts === [])
                                <div class="rounded-xl border border-dashed border-brand-sage/30 bg-brand-sage/5 px-4 py-3 text-sm text-brand-moss dark:border-brand-sage/25 dark:bg-brand-sage/10">
                                    <p class="font-medium text-brand-ink">{{ __('Link GitHub, GitLab, or Bitbucket to browse repositories here.') }}</p>
                                    <p class="mt-1 text-xs">{{ __('You can still paste owner/repo manually, or connect an account to pick from a searchable list.') }}</p>
                                </div>
                            @endif

                            @if ($repo_source === 'connected' && $linkedSourceControlAccounts !== [])
                                <div class="grid gap-4 sm:grid-cols-2">
                                    <div>
                                        <x-input-label for="source_control_account_id" :value="__('Account')" />
                                        <select id="source_control_account_id" wire:model.live="source_control_account_id" class="dply-input mt-1 block w-full">
                                            @foreach ($linkedSourceControlAccounts as $account)
                                                <option value="{{ $account['id'] }}">{{ $account['label'] ?? $account['id'] }}</option>
                                            @endforeach
                                        </select>
                                    </div>
                                    <div>
                                        <x-input-label for="repository_selection" :value="__('Repository')" :required="true" />
                                        @if ($availableRepositories !== [])
                                            <x-repo-combobox
                                                :repositories="$availableRepositories"
                                                property="repository_selection"
                                                target="source_control_account_id"
                                                trigger-id="repository_selection"
                                                :selected="$repository_selection"
                                                :placeholder="__('Select a repository…')"
                                                class="mt-1"
                                            />
                                        @else
                                            <p class="mt-1 text-xs text-brand-mist">{{ __('No repositories returned for this account. Check the token or enter the repo manually.') }}</p>
                                        @endif
                                        <x-repo-access-hint :accounts="$linkedSourceControlAccounts" :selected="$source_control_account_id" />
                                        <x-input-error :messages="$errors->get('repository_selection')" class="mt-2" />
                                    </div>
                                </div>
                                @if ($repo !== '')
                                    <p class="text-xs text-brand-moss">{{ __('Will deploy :repo on branch :branch.', ['repo' => $repo, 'branch' => $branch]) }}</p>
                                @endif
                                <x-input-error :messages="$errors->get('repo')" class="mt-2" />
                            @else
                                <div class="grid gap-4 sm:grid-cols-[minmax(0,1.4fr)_minmax(0,0.6fr)]">
                                    <div>
                                        <x-input-label for="repo" :value="__('Git repository')" />
                                        <div class="relative mt-1">
                                            <span class="pointer-events-none absolute inset-y-0 start-0 flex items-center ps-3 text-brand-mist" aria-hidden="true">
                                                <x-heroicon-o-code-bracket class="h-4 w-4" />
                                            </span>
                                            <x-text-input id="repo" wire:model.live.debounce.500ms="repo" type="text" class="block w-full ps-10 font-mono text-sm" required placeholder="owner/repo" />
                                        </div>
                                        <p class="mt-1 text-xs text-brand-mist">{{ __('owner/repo or a full GitHub URL') }}</p>
                                        <x-input-error :messages="$errors->get('repo')" class="mt-2" />
                                    </div>
                                    <div>
                                        @php
                                            $refKind = $form->ref_kind ?? 'branch';
                                            $refLabel = match ($refKind) {
                                                'tag' => __('Tag'),
                                                'commit' => __('Commit'),
                                                default => __('Branch'),
                                            };
                                        @endphp
                                        <x-input-label :value="__('Ref')" />
                                        <div class="mt-1 flex flex-wrap items-center gap-2">
                                            <div class="inline-flex items-center gap-2 rounded-lg border border-brand-ink/15 bg-white px-3 py-1.5">
                                                @if ($refKind === 'tag')
                                                    <x-heroicon-o-tag class="h-4 w-4 text-brand-mist" />
                                                @elseif ($refKind === 'commit')
                                                    <x-heroicon-o-code-bracket-square class="h-4 w-4 text-brand-mist" />
                                                @else
                                                    <x-heroicon-o-arrow-trending-up class="h-4 w-4 text-brand-mist" />
                                                @endif
                                                <span class="text-2xs font-semibold uppercase tracking-wide text-brand-mist">{{ $refLabel }}</span>
                                                <span class="font-mono text-sm text-brand-ink">{{ $branch !== '' ? $branch : __('—') }}</span>
                                            </div>
                                            <button
                                                type="button"
                                                wire:click="{{ $refPickerOpen ? 'closeRefPicker' : 'openRefPicker' }}"
                                                class="inline-flex shrink-0 items-center gap-1.5 rounded-lg border border-brand-ink/15 bg-white px-3 py-1.5 text-xs font-semibold text-brand-ink transition hover:bg-brand-sand/40"
                                                title="{{ __('Browse branches, tags, and commits from the repo.') }}"
                                            >
                                                <x-heroicon-o-magnifying-glass class="h-4 w-4" />
                                                {{ $refPickerOpen ? __('Hide picker') : __('Change') }}
                                            </button>
                                        </div>
                                        <x-input-error :messages="$errors->get('branch')" class="mt-2" />
                                        <x-input-error :messages="$errors->get('form.ref_kind')" class="mt-2" />

                                        @if ($refPickerOpen)
                                            <div class="mt-3 overflow-hidden rounded-xl border border-brand-ink/10 bg-white shadow-sm">
                                                <div class="flex items-center justify-between gap-2 border-b border-brand-ink/10 px-3 py-2">
                                                    <div class="inline-flex rounded-md border border-brand-ink/15 bg-brand-sand/20 p-0.5 text-xs font-semibold">
                                                        @foreach ([
                                                            'branches' => __('Branches'),
                                                            'tags' => __('Tags'),
                                                            'commits' => __('Commits'),
                                                        ] as $tabKey => $tabLabel)
                                                            <button
                                                                type="button"
                                                                wire:click="setRefPickerTab('{{ $tabKey }}')"
                                                                class="rounded-md px-2.5 py-1 transition {{ $refPickerTab === $tabKey ? 'bg-brand-ink text-brand-cream shadow-sm' : 'text-brand-moss hover:text-brand-ink' }}"
                                                            >
                                                                {{ $tabLabel }}
                                                            </button>
                                                        @endforeach
                                                    </div>
                                                    <button type="button" wire:click="closeRefPicker" class="rounded-md p-1 text-brand-mist hover:bg-brand-sand/40 hover:text-brand-ink" title="{{ __('Close') }}">
                                                        <x-heroicon-o-x-mark class="h-4 w-4" />
                                                    </button>
                                                </div>
                                                <div class="border-b border-brand-ink/10 px-3 py-2">
                                                    <div class="relative">
                                                        <span class="pointer-events-none absolute inset-y-0 start-0 flex items-center ps-2.5 text-brand-mist" aria-hidden="true">
                                                            <x-heroicon-o-magnifying-glass class="h-4 w-4" />
                                                        </span>
                                                        <x-text-input
                                                            wire:model.live.debounce.300ms="refPickerSearch"
                                                            type="text"
                                                            class="block w-full ps-8 text-xs"
                                                            placeholder="{{ __('Filter…') }}"
                                                        />
                                                    </div>
                                                </div>
                                                <div class="max-h-64 overflow-y-auto">
                                                    @if ($refPickerLoading)
                                                        <div class="flex items-center justify-center gap-2 px-3 py-6 text-xs text-brand-moss">
                                                            <x-spinner size="sm" variant="ink" />
                                                            {{ __('Loading…') }}
                                                        </div>
                                                    @elseif ($refPickerError !== null)
                                                        <div class="px-3 py-4 text-xs text-rose-700">{{ $refPickerError }}</div>
                                                    @elseif ($refPickerResults === [])
                                                        <div class="px-3 py-6 text-center text-xs text-brand-mist">{{ __('No matches.') }}</div>
                                                    @else
                                                        <ul class="divide-y divide-brand-ink/8">
                                                            @php
                                                                $kindForRow = match ($refPickerTab) {
                                                                    'tags' => 'tag',
                                                                    'commits' => 'commit',
                                                                    default => 'branch',
                                                                };
                                                            @endphp
                                                            @foreach ($refPickerResults as $row)
                                                                @php
                                                                    $valueForSelect = $kindForRow === 'commit' ? ($row['sha'] ?? '') : ($row['label'] ?? '');
                                                                @endphp
                                                                <li>
                                                                    <button
                                                                        type="button"
                                                                        wire:click="selectRefPickerValue('{{ addslashes($valueForSelect) }}', '{{ $kindForRow }}')"
                                                                        class="flex w-full items-start gap-3 px-3 py-2 text-left text-xs hover:bg-brand-sand/30"
                                                                    >
                                                                        <div class="mt-0.5 shrink-0 text-brand-mist">
                                                                            @if ($kindForRow === 'branch')
                                                                                <x-heroicon-o-arrow-trending-up class="h-4 w-4" />
                                                                            @elseif ($kindForRow === 'tag')
                                                                                <x-heroicon-o-tag class="h-4 w-4" />
                                                                            @else
                                                                                <x-heroicon-o-code-bracket-square class="h-4 w-4" />
                                                                            @endif
                                                                        </div>
                                                                        <div class="min-w-0 flex-1">
                                                                            <p class="truncate font-mono text-xs text-brand-ink">{{ $row['label'] ?? '' }}</p>
                                                                            @if (! empty($row['meta']))
                                                                                <p class="mt-0.5 truncate text-xs text-brand-moss">{{ $row['meta'] }}</p>
                                                                            @endif
                                                                        </div>
                                                                        @if (! empty($row['sha']))
                                                                            <span class="shrink-0 font-mono text-2xs text-brand-mist">{{ substr($row['sha'], 0, 7) }}</span>
                                                                        @endif
                                                                    </button>
                                                                </li>
                                                            @endforeach
                                                        </ul>
                                                    @endif
                                                </div>
                                            </div>
                                        @endif
                                    </div>
                                </div>
                            @endif

                            @if ($repo_source === 'connected' && $linkedSourceControlAccounts !== [])
                                <div class="max-w-xs">
                                    <x-input-label for="edge_branch_override" :value="__('Branch')" />
                                    <div class="relative mt-1">
                                        <span class="pointer-events-none absolute inset-y-0 start-0 flex items-center ps-3 text-brand-mist" aria-hidden="true">
                                            <x-heroicon-o-arrow-trending-up class="h-4 w-4" />
                                        </span>
                                        <x-text-input id="edge_branch_override" wire:model.live.debounce.500ms="branch" type="text" class="block w-full ps-10 font-mono text-sm" required />
                                    </div>
                                    <x-input-error :messages="$errors->get('branch')" class="mt-2" />
                                </div>
                            @endif

                            <div class="max-w-md">
                                <x-input-label for="name" :value="__('App name')" />
                                @php
                                    $nameTargets = 'repo,branch,repository_selection,source_control_account_id,repo_source,detectFromRepository';
                                @endphp
                                <div class="relative mt-1">
                                    <x-text-input
                                        id="name"
                                        wire:model.live="form.name"
                                        type="text"
                                        class="block w-full pr-10"
                                        required
                                        placeholder="marketing-site"
                                    />
                                    <div
                                        wire:loading.delay
                                        wire:target="{{ $nameTargets }}"
                                        class="pointer-events-none absolute right-3 top-1/2 -translate-y-1/2"
                                        aria-label="{{ __('Detecting…') }}"
                                    >
                                        <x-spinner size="sm" variant="muted" />
                                    </div>
                                </div>
                                <p class="mt-1 text-xs text-brand-mist">{{ __('Auto-filled from the repo name — edit anytime.') }}</p>
                                <x-input-error :messages="$errors->get('form.name')" class="mt-2" />
                            </div>
                        </div>
                    </section>

                    @php
                        $detectTargets = 'detectFromRepository,repo,branch,repository_selection,source_control_account_id,repo_source';
                        $hasDetectionResult = $detectedPlan !== []
                            && (
                                ! empty($detectedPlan['error'])
                                || ! empty($detectedPlan['no_match'])
                                || ! empty($detectedPlan['runtime'])
                                || ($detectedPlan['kind'] ?? '') === 'serverless'
                            );
                        $showDetectionPanel = $runtimeDetectionPending || $hasDetectionResult;
                        $runtimeLabel = match ($form->runtime_mode) {
                            'hybrid' => __('Hybrid'),
                            'ssr' => __('Worker SSR'),
                            default => __('Static / SSG'),
                        };
                        $buildSummary = trim((string) $form->build_command) !== ''
                            ? $form->build_command
                            : __('Detected / default');
                        $outputSummary = trim((string) $form->output_dir) !== '' ? $form->output_dir : 'dist';
                        $frameworkSummary = trim((string) ($detectedPlan['framework'] ?? $detectedPlan['runtime'] ?? ''));
                        $advancedDefaultOpen = $form->delivery_mode === 'byo'
                            || $errors->has('form.build_command')
                            || $errors->has('form.output_dir')
                            || $errors->has('form.edge_provider_credential_id');
                    @endphp

                    {{-- 02 Auto-detected settings --}}
                    <section
                        x-data="{ advancedOpen: @js($advancedDefaultOpen) }"
                        class="border-b border-brand-ink/10"
                        @if ($runtimeDetectionPending) wire:poll.2s="pollRuntimeDetection" @endif
                    >
                        <div class="flex items-start gap-3 bg-brand-sand/15 px-5 py-3 sm:px-6">
                            <span class="inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-xl bg-brand-sage/15 text-sm font-bold text-brand-forest ring-1 ring-brand-sage/25 dark:bg-brand-sage/15 dark:text-brand-sage dark:ring-brand-sage/30">02</span>
                            <div class="min-w-0 flex-1">
                                <div class="flex flex-wrap items-start justify-between gap-3">
                                    <div>
                                        <h2 class="text-base font-semibold text-brand-ink">{{ __('Build & delivery') }}</h2>
                                        <p class="mt-0.5 text-sm text-brand-moss">{{ __('Detected from your repo. Override only if needed.') }}</p>
                                    </div>
                                    <button
                                        type="button"
                                        wire:click="detectFromRepository"
                                        wire:loading.attr="disabled"
                                        wire:target="{{ $detectTargets }}"
                                        class="inline-flex shrink-0 items-center gap-1.5 rounded-lg border border-brand-ink/10 bg-white/80 px-3 py-1.5 text-xs font-semibold text-brand-moss transition-colors hover:border-brand-sage/40 hover:text-brand-forest disabled:cursor-wait disabled:opacity-60 dark:border-brand-mist/25 dark:bg-zinc-800 dark:hover:text-brand-sage"
                                    >
                                        <x-heroicon-o-arrow-path wire:loading.remove wire:target="{{ $detectTargets }}" class="h-3.5 w-3.5" aria-hidden="true" />
                                        <x-spinner wire:loading wire:target="{{ $detectTargets }}" size="sm" variant="muted" />
                                        <span wire:loading.remove wire:target="{{ $detectTargets }}">{{ __('Re-detect') }}</span>
                                        <span wire:loading wire:target="{{ $detectTargets }}">{{ __('Detecting…') }}</span>
                                    </button>
                                </div>
                            </div>
                        </div>
                        <div class="min-w-0 space-y-4 px-5 py-4 sm:px-6">
                            @if (trim($repo) === '')
                                <p class="rounded-xl border border-dashed border-brand-ink/12 bg-brand-cream/30 px-4 py-3 text-sm text-brand-moss dark:border-brand-mist/20 dark:bg-zinc-800/40">
                                    {{ __('Connect a repository to auto-detect framework, build command, and delivery mode.') }}
                                </p>
                            @elseif ($runtimeDetectionPending)
                                <div class="flex items-center gap-2 rounded-xl border border-brand-ink/10 bg-brand-cream/40 px-4 py-3 text-sm text-brand-moss dark:border-brand-mist/20 dark:bg-zinc-800/50">
                                    <x-spinner size="sm" variant="ink" />
                                    {{ __('Scanning repository…') }}
                                </div>
                            @else
                                <div class="flex flex-wrap items-center gap-2 text-sm">
                                    @if ($frameworkSummary !== '')
                                        <span class="inline-flex items-center rounded-lg border border-brand-sage/30 bg-brand-sage/10 px-2.5 py-1 text-xs font-semibold text-brand-forest dark:text-brand-sage">{{ $frameworkSummary }}</span>
                                    @endif
                                    <span class="inline-flex items-center rounded-lg border border-brand-ink/10 bg-white px-2.5 py-1 text-xs font-semibold text-brand-ink dark:border-brand-mist/25 dark:bg-zinc-800">{{ $runtimeLabel }}</span>
                                    <span class="font-mono text-xs text-brand-moss">{{ $buildSummary }}</span>
                                    <span class="text-brand-mist" aria-hidden="true">→</span>
                                    <span class="font-mono text-xs text-brand-moss">{{ $outputSummary }}</span>
                                </div>
                            @endif

                            @if ($showDetectionPanel && (! empty($detectedPlan['error']) || ! empty($detectedPlan['no_match'])))
                                <div class="relative">
                                    @include('livewire.partials._runtime-detection-panel')
                                </div>
                            @endif

                            @if (! $edgeEligible && filled($edgeIneligibleMessage))
                                <div class="rounded-xl border border-amber-200/80 bg-amber-50/80 px-4 py-3 text-xs text-amber-950 dark:border-raw-amber-900/40 dark:bg-raw-amber-950/30 dark:text-raw-amber-100">
                                    <p class="font-semibold">{{ __('Not an Edge workload') }}</p>
                                    <p class="mt-1 leading-relaxed">{{ $edgeIneligibleMessage }}</p>
                                    @if (filled($edgeAlternativeRoute) && \Illuminate\Support\Facades\Route::has($edgeAlternativeRoute))
                                        <a
                                            href="{{ route($edgeAlternativeRoute) }}"
                                            wire:navigate
                                            class="mt-3 inline-flex items-center gap-1.5 text-xs font-semibold text-amber-950 underline decoration-amber-700/40 underline-offset-2 transition-colors hover:text-amber-900 dark:text-raw-amber-50 dark:hover:text-raw-white"
                                        >
                                            {{ $edgeAlternativeLabel ?: __('Open alternative') }}
                                            <x-heroicon-m-arrow-right class="h-3.5 w-3.5" aria-hidden="true" />
                                        </a>
                                    @endif
                                </div>
                            @endif

                            @if ($monorepoDetected && $monorepoPackages !== [])
                                <div class="rounded-xl border border-brand-sage/25 bg-brand-sage/5 px-4 py-3 dark:border-brand-sage/20 dark:bg-brand-sage/10">
                                    <p class="text-sm font-semibold text-brand-ink">{{ __('Monorepo detected') }}</p>
                                    <p class="mt-0.5 text-xs text-brand-moss">{{ __('Pick the package directory to build.') }}</p>
                                    <fieldset class="mt-3 space-y-2">
                                        <legend class="sr-only">{{ __('Package directory') }}</legend>
                                        @foreach ($monorepoPackages as $package)
                                            <label class="flex items-start gap-3 rounded-xl border border-brand-ink/10 bg-white px-3 py-2.5 text-sm dark:border-brand-mist/20 dark:bg-zinc-900">
                                                <input
                                                    type="radio"
                                                    wire:model.live="form.repo_root"
                                                    value="{{ $package['path'] }}"
                                                    class="mt-0.5 border-brand-ink/20 text-brand-sage focus:ring-brand-sage/40"
                                                />
                                                <span>
                                                    <span class="font-mono text-brand-ink">{{ $package['path'] !== '' ? $package['path'] : '/' }}</span>
                                                    <span class="mt-0.5 block text-xs text-brand-moss">{{ $package['label'] }}</span>
                                                </span>
                                            </label>
                                        @endforeach
                                    </fieldset>
                                </div>
                            @endif

                            @if ($form->runtime_mode === 'hybrid')
                                <div class="rounded-xl border border-brand-sage/30 bg-brand-sage/8 px-4 py-3 dark:border-brand-sage/25 dark:bg-brand-sage/10">
                                        <x-input-label for="origin_url" :value="__('SSR origin URL')" />
                                        @if ($ssrDetected)
                                            <p class="mt-1 text-xs text-brand-moss">{{ __('Server-rendered app detected — enter the live URL of its origin.') }}</p>
                                        @endif
                                        <x-text-input id="origin_url" wire:model.live="form.origin_url" type="url" class="mt-2 block w-full font-mono text-sm" placeholder="https://my-app.example.com" required />
                                        <x-input-error :messages="$errors->get('form.origin_url')" class="mt-2" />
                                </div>
                            @endif

                            <button
                                type="button"
                                x-on:click="advancedOpen = ! advancedOpen"
                                class="inline-flex items-center gap-1.5 text-xs font-semibold text-brand-moss transition-colors hover:text-brand-forest dark:hover:text-brand-sage"
                            >
                                <span x-text="advancedOpen ? '{{ __('Hide advanced') }}' : '{{ __('Advanced settings') }}'"></span>
                                <x-heroicon-m-chevron-down class="h-3.5 w-3.5 transition-transform" x-bind:class="advancedOpen ? 'rotate-180' : ''" aria-hidden="true" />
                            </button>

                            <div x-show="advancedOpen" x-collapse class="space-y-5 border-t border-brand-ink/10 pt-4 dark:border-brand-mist/15">
                                <div class="grid gap-4 sm:grid-cols-2">
                                    <div>
                                        <x-input-label for="build_command" :value="__('Build command')" />
                                        <x-text-input id="build_command" wire:model.live="form.build_command" type="text" class="mt-1 block w-full font-mono text-sm" placeholder="npm ci && npm run build" />
                                        <x-input-error :messages="$errors->get('form.build_command')" class="mt-2" />
                                    </div>
                                    <div>
                                        <x-input-label for="output_dir" :value="__('Output directory')" />
                                        <x-text-input id="output_dir" wire:model.live="form.output_dir" type="text" class="mt-1 block w-full font-mono text-sm" placeholder="dist" />
                                        <x-input-error :messages="$errors->get('form.output_dir')" class="mt-2" />
                                    </div>
                                </div>

                                <div>
                                    <p class="text-xs font-semibold uppercase tracking-[0.14em] text-brand-moss">{{ __('Delivery mode') }}</p>
                                    @php
                                        $deliveryModes = [
                                            ['value' => 'static', 'label' => __('Static / SSG'), 'body' => __('CDN-only. Most sites.')],
                                            ['value' => 'hybrid', 'label' => __('Hybrid'), 'body' => __('Static on Edge + Cloud/HTTPS origin for SSR.')],
                                            [
                                                'value' => 'ssr',
                                                'label' => __('Worker SSR'),
                                                'body' => $ssrAvailable
                                                    ? __('Keel / Next.js on the edge — no origin. $:fee/mo platform fee.', ['fee' => number_format($edgeSsrFee, 2)])
                                                    : ($ssrUnavailableReason ?: __('Unavailable — use Hybrid.')),
                                                'disabled' => ! $ssrAvailable,
                                            ],
                                            [
                                                'value' => 'container',
                                                'label' => __('Container'),
                                                'body' => $ssrAvailable
                                                    ? __('PHP (Laravel) or Rails on Cloudflare Containers. Uses your Dockerfile, or dply generates one. Pro and Team.')
                                                    : ($ssrUnavailableReason ?: __('Unavailable on this install.')),
                                                'disabled' => ! $ssrAvailable,
                                            ],
                                        ];
                                    @endphp
                                    <div class="mt-2 grid gap-2" role="radiogroup" aria-label="{{ __('Delivery mode') }}">
                                        @foreach ($deliveryModes as $mode)
                                            <label
                                                @class([
                                                    'relative flex cursor-pointer gap-3 rounded-xl border px-3 py-2.5 transition-colors',
                                                    'border-brand-sage/40 bg-brand-sage/5 dark:border-brand-sage/35 dark:bg-brand-sage/10' => $form->runtime_mode === $mode['value'],
                                                    'border-brand-ink/10 bg-white/70 hover:border-brand-sage/30 dark:border-brand-mist/20 dark:bg-zinc-900/40' => $form->runtime_mode !== $mode['value'] && empty($mode['disabled']),
                                                    'cursor-not-allowed opacity-60' => ! empty($mode['disabled']),
                                                ])
                                            >
                                                <input
                                                    type="radio"
                                                    wire:model.live="form.runtime_mode"
                                                    value="{{ $mode['value'] }}"
                                                    class="mt-0.5 text-brand-sage focus:ring-brand-sage/40"
                                                    @disabled(! empty($mode['disabled']))
                                                />
                                                <span class="min-w-0">
                                                    <span class="text-sm font-semibold text-brand-ink">{{ $mode['label'] }}</span>
                                                    <span class="mt-0.5 block text-xs text-brand-moss">{{ $mode['body'] }}</span>
                                                </span>
                                            </label>
                                        @endforeach
                                    </div>
                                </div>

                                <div>
                                    <p class="text-xs font-semibold uppercase tracking-[0.14em] text-brand-moss">{{ __('Hosting') }}</p>
                                    <div role="radiogroup" aria-label="{{ __('Edge delivery mode') }}" class="mt-2 grid gap-2 sm:grid-cols-2">
                                        <label class="relative flex cursor-pointer rounded-xl border p-3 transition-colors {{ $form->delivery_mode === 'managed' ? 'border-brand-sage/40 bg-brand-sage/5 dark:border-brand-sage/35 dark:bg-brand-sage/10' : 'border-brand-ink/10 bg-brand-cream/30 hover:border-brand-sage/30 dark:border-brand-mist/20 dark:bg-zinc-800/40' }}">
                                            <input type="radio" wire:model.live="form.delivery_mode" value="managed" class="mt-0.5 text-brand-sage focus:ring-brand-sage/40" />
                                            <span class="ms-3">
                                                <span class="block text-sm font-semibold text-brand-ink">{{ __('Dply Edge (managed)') }}</span>
                                                <span class="mt-0.5 block text-xs text-brand-moss">{{ __('Default') }}</span>
                                            </span>
                                        </label>
                                        <label class="relative flex cursor-pointer rounded-xl border p-3 transition-colors {{ $form->delivery_mode === 'byo' ? 'border-brand-sage/40 bg-brand-sage/5 dark:border-brand-sage/35 dark:bg-brand-sage/10' : 'border-brand-ink/10 bg-brand-cream/30 hover:border-brand-sage/30 dark:border-brand-mist/20 dark:bg-zinc-800/40' }}">
                                            <input type="radio" wire:model.live="form.delivery_mode" value="byo" class="mt-0.5 text-brand-sage focus:ring-brand-sage/40" />
                                            <span class="ms-3">
                                                <span class="block text-sm font-semibold text-brand-ink">{{ __('Your own account') }}</span>
                                                <span class="mt-0.5 block text-xs text-brand-moss">{{ __('BYO CDN') }}</span>
                                            </span>
                                        </label>
                                    </div>

                                    @if ($form->delivery_mode === 'byo')
                                        <div class="mt-3 rounded-xl border border-brand-sage/25 bg-brand-sage/5 px-4 py-3 dark:border-brand-sage/20 dark:bg-brand-sage/10">
                                            @if ($cloudflareCredentials->isEmpty())
                                                <p class="text-sm font-semibold text-brand-ink">{{ __('No CDN account connected') }}</p>
                                                <p class="mt-1 text-xs text-brand-moss">{{ __('Add a Cloudflare API token with Workers and R2 permissions.') }}</p>
                                                <button
                                                    type="button"
                                                    wire:click="openCloudflareCredentialModal"
                                                    class="mt-3 inline-flex items-center gap-2 rounded-xl bg-brand-ink px-3.5 py-2 text-sm font-semibold text-brand-cream transition-colors hover:bg-brand-forest"
                                                >
                                                    <x-heroicon-o-key class="h-4 w-4" aria-hidden="true" />
                                                    {{ __('Add Cloudflare token') }}
                                                </button>
                                            @else
                                                <div class="flex flex-wrap items-center justify-between gap-2">
                                                    <x-input-label for="edge_provider_credential_id" :value="__('CDN account')" />
                                                    <button type="button" wire:click="openCloudflareCredentialModal" class="text-xs font-semibold text-brand-forest hover:underline dark:text-brand-sage">
                                                        {{ __('Add another') }}
                                                    </button>
                                                </div>
                                                <select id="edge_provider_credential_id" wire:model="form.edge_provider_credential_id" class="dply-input mt-2 block w-full" required>
                                                    <option value="">{{ __('Select a connected account…') }}</option>
                                                    @foreach ($cloudflareCredentials as $credential)
                                                        <option value="{{ $credential->id }}">{{ $credential->name }}</option>
                                                    @endforeach
                                                </select>
                                                <x-input-error :messages="$errors->get('form.edge_provider_credential_id')" class="mt-2" />
                                            @endif
                                        </div>
                                    @endif
                                </div>

                                <div class="grid gap-2 sm:grid-cols-2">
                                    <label class="flex cursor-pointer gap-3 rounded-xl border border-brand-ink/10 bg-brand-cream/30 p-3 dark:border-brand-mist/20 dark:bg-zinc-800/40">
                                        <input type="checkbox" wire:model="form.spa_fallback" class="mt-0.5 rounded border-brand-ink/20 text-brand-sage focus:ring-brand-sage/40" />
                                        <span>
                                            <span class="block text-sm font-semibold text-brand-ink">{{ __('SPA fallback') }}</span>
                                            <span class="mt-0.5 block text-xs text-brand-moss">{{ __('index.html for unknown paths') }}</span>
                                        </span>
                                    </label>
                                    <label class="flex cursor-pointer gap-3 rounded-xl border border-brand-ink/10 bg-brand-cream/30 p-3 dark:border-brand-mist/20 dark:bg-zinc-800/40">
                                        <input type="checkbox" wire:model="form.deploy_on_push" class="mt-0.5 rounded border-brand-ink/20 text-brand-sage focus:ring-brand-sage/40" />
                                        <span>
                                            <span class="block text-sm font-semibold text-brand-ink">{{ __('Deploy on push') }}</span>
                                            <span class="mt-0.5 block text-xs text-brand-moss">{{ __('Rebuild on production-branch push') }}</span>
                                        </span>
                                    </label>
                                </div>
                            </div>
                        </div>
                    </section>

                    <x-slot:footer>
                        <div class="flex flex-col-reverse gap-3 sm:flex-row sm:items-center sm:justify-between">
                            <a
                                href="{{ route('edge.index') }}"
                                wire:navigate
                                class="inline-flex items-center justify-center gap-1.5 text-sm font-medium text-brand-moss transition-colors hover:text-brand-ink"
                            >
                                <x-heroicon-m-arrow-left class="h-4 w-4" aria-hidden="true" />
                                {{ __('Back to Edge') }}
                            </a>
                            <div class="flex w-full flex-col gap-2 sm:w-auto sm:flex-row sm:items-center sm:justify-end">
                                @php
                                    $deployBlocked = $ssrDetected && trim($form->origin_url) === '' && $form->runtime_mode !== 'hybrid';
                                    $hybridMissingOrigin = $form->runtime_mode === 'hybrid' && trim($form->origin_url) === '';
                                    $missingName = trim($form->name) === '';
                                    $missingRepo = trim($repo) === '';
                                    $missingBranch = trim($branch) === '';
                                    $edgeDeployDisabled = ! $edgeEligible || $deployBlocked || $hybridMissingOrigin
                                        || $missingName || $missingRepo || $missingBranch;
                                    $deployLabel = __('Deploy edge app');
                                    $detectTargets = 'deploy,detectFromRepository,repo,branch,repository_selection,source_control_account_id,repo_source';
                                @endphp
                                <x-primary-button
                                    type="submit"
                                    wire:loading.attr="disabled"
                                    wire:target="{{ $detectTargets }}"
                                    :disabled="$edgeDeployDisabled"
                                    class="w-full sm:w-auto disabled:cursor-not-allowed disabled:opacity-50 disabled:hover:bg-brand-ink disabled:shadow-none"
                                >
                                    <span wire:loading.remove wire:target="deploy" class="inline-flex items-center gap-2 whitespace-nowrap">
                                        <x-heroicon-o-rocket-launch class="inline-block h-4 w-4 shrink-0 align-middle" aria-hidden="true" />
                                        {{ $deployLabel }}
                                    </span>
                                    <span wire:loading wire:target="deploy" class="inline-flex items-center justify-center gap-2 whitespace-nowrap">
                                        <x-spinner variant="cream" />
                                        {{ __('Queueing…') }}
                                    </span>
                                </x-primary-button>
                            </div>
                        </div>
                    </x-slot:footer>
                </x-profile-shell>
            </div>

            @include('livewire.edge.partials.create-sidebar', [
                'edgeFee' => $edgeFee,
                'edgePlatformFee' => $edgePlatformFee,
            ])
        </div>
    </form>

    <livewire:credentials.add-provider-credential-modal capability="cdn" default-provider="cloudflare" />

</div>
