{{-- Build: a sentence about how a push becomes a deploy, then a row per setting that opens a dialog. --}}
@php
    use App\Models\EdgeDeployment;

    $repoConfig = null;
    if ($site->relationLoaded('edgeDeployments') && $site->edgeDeployments !== null) {
        $withConfig = $site->edgeDeployments->filter(fn (EdgeDeployment $d): bool => is_array($d->repo_config) && $d->repo_config !== []);
        $repoConfig = $withConfig->first(fn (EdgeDeployment $d): bool => $d->status === EdgeDeployment::STATUS_LIVE)?->repo_config
            ?? $withConfig->first()?->repo_config;
    }
    $repoBuild = is_array($repoConfig['build'] ?? null) ? $repoConfig['build'] : [];
    $repoFile = (string) ($repoConfig['source_path'] ?? 'dply.yaml');
    $warnings = (array) ($repoConfig['warnings'] ?? []);

    $canEdit = auth()->user()?->can('update', $site) ?? false;
    $command = filled($repoBuild['command'] ?? null) ? (string) $repoBuild['command'] : (string) $edgeBuildCommand;
    $output = trim(filled($repoBuild['output'] ?? null) ? (string) $repoBuild['output'] : (string) $edgeOutputDir, '/');
    $wholeOutput = in_array($output, ['', '.'], true);
    $root = trim((string) (filled($repoBuild['root'] ?? null) ? $repoBuild['root'] : (($edgeRepoRoot ?? $site->edgeRepoRoot()) ?: '')), '/');
    $showsSpa = ! in_array($edgeRuntimeMode ?? 'static', ['container', 'ssr'], true);
    $keep = (int) $buildForm->edge_releases_to_keep;
    $footerOn = (bool) ($site->edgeMeta()['deploy_footer']['enabled'] ?? false);
    $repoLine = trim(($edgeRepo ?: '—'));
    $row = 'flex min-h-12 w-full items-center gap-3 border-b border-brand-ink/10 py-3 text-left hover:bg-brand-sand/20 disabled:cursor-default disabled:hover:bg-transparent';
    $fromRepo = fn (string $key) => filled($repoBuild[$key] ?? null);
@endphp

<section class="space-y-8 px-5 py-8 sm:px-10 sm:py-10">
    <div>
        <p class="text-2xs font-semibold uppercase tracking-[0.16em] text-brand-mist">{{ __('Build') }}</p>
        <p class="mt-3 max-w-3xl text-2xl font-medium leading-snug tracking-tight text-brand-ink sm:text-3xl">
            @if ($edgeDeployOnPush)
                {{ __('Every push to') }} <span class="font-mono text-xl text-brand-sage sm:text-2xl">{{ $edgeBranch }}</span> {{ __('on :repo runs', ['repo' => $repoLine]) }}
            @else
                <span class="text-amber-600 dark:text-amber-300">{{ __('Pushes don’t deploy.') }}</span> {{ __('A deploy of') }} <span class="font-mono text-xl sm:text-2xl">{{ $edgeBranch }}</span> {{ __('runs') }}
            @endif
            <span class="font-mono text-xl sm:text-2xl" title="{{ $command }}">{{ \Illuminate\Support\Str::limit($command, 40) }}</span>{{ $root !== '' ? ' '.__('in :dir', ['dir' => $root.'/']) : '' }}
            @if ($wholeOutput)
                {{ __('and publishes the whole build folder.') }}
            @else
                {{ __('and publishes') }} <span class="font-mono text-xl sm:text-2xl">{{ $output }}/</span>.
            @endif
            {{ trans_choice('The last release is kept for rollback.|The last :count releases are kept for rollback.', $keep) }}
            @if ($warnings !== [])
                <span class="text-amber-600 dark:text-amber-300">{{ trans_choice(':file has :count warning.|:file has :count warnings.', count($warnings), ['file' => $repoFile]) }}</span>
            @endif
        </p>
    </div>

    <div>
        <p class="border-b border-brand-ink/10 pb-2 text-sm font-semibold text-brand-ink">{{ __('How it builds') }}</p>
        <div class="flex min-h-12 items-center gap-3 border-b border-brand-ink/10 py-3">
            <span class="flex-1 text-sm text-brand-ink sm:text-base">
                {{ __('The code comes from') }}
                @if ($edgeGithubRepoUrl)
                    <a href="{{ $edgeGithubRepoUrl }}" target="_blank" rel="noopener noreferrer" class="font-mono hover:underline">{{ $repoLine }}</a>
                @else
                    <span class="font-mono">{{ $repoLine }}</span>
                @endif
                <span class="font-mono">@ {{ $edgeBranch }}</span>
            </span>
            <span class="shrink-0 text-xs text-brand-moss">{{ __('Fixed') }}</span>
        </div>
        @foreach (array_filter([
            'push' => [$edgeDeployOnPush ? __('Pushes to :branch deploy automatically', ['branch' => $edgeBranch]) : __('Pushes to :branch don’t deploy', ['branch' => $edgeBranch]), $edgeDeployOnPush ? __('On') : __('Off'), false],
            'command' => [__('The build runs :cmd', ['cmd' => $command]), $fromRepo('command') ? $repoFile : __('Edit'), $fromRepo('command')],
            'output' => [$wholeOutput ? __('It publishes the whole build folder') : __('It publishes the :dir folder', ['dir' => $output]), $fromRepo('output') ? $repoFile : ($wholeOutput ? '.' : $output.'/'), $fromRepo('output')],
            'root' => [$root === '' ? __('It runs from the repository root') : __('It runs from :dir, a subfolder of the repository', ['dir' => $root.'/']), $fromRepo('root') ? $repoFile : ($root === '' ? '/' : $root.'/'), $fromRepo('root')],
            'spa' => $showsSpa ? [$edgeSpaFallback ? __('Unknown paths serve index.html (single-page app)') : __('Unknown paths return 404'), $edgeSpaFallback ? __('SPA') : __('404'), false] : null,
            'releases' => [trans_choice('The last release is kept for rollback|The last :count releases are kept for rollback', $keep), (string) $keep, false],
            'footer' => [$footerOn ? __('Pages show the live deploy id in their footer') : __('Pages don’t show the deploy id in their footer'), $footerOn ? __('On') : __('Off'), false],
        ]) as $key => [$sentence, $state, $repoOwned])
            <button type="button" @if ($canEdit) wire:click="openSetting('{{ $key }}')" @endif class="{{ $row }}" @disabled(! $canEdit)>
                <span class="min-w-0 flex-1 break-words text-sm text-brand-ink sm:text-base">{{ $sentence }}</span>
                <span @class(['shrink-0 font-mono text-xs', 'text-amber-600 dark:text-amber-300' => $repoOwned, 'text-brand-moss' => ! $repoOwned])>{{ $state }}</span>
                @if ($canEdit)
                    <x-heroicon-m-chevron-right class="h-4 w-4 shrink-0 text-brand-mist" aria-hidden="true" />
                @endif
            </button>
        @endforeach
        @if ($repoConfig !== null)
            <button type="button" x-on:click="$dispatch('open-modal', 'build-repo')" class="{{ $row }}">
                <span class="flex-1 text-sm text-brand-ink sm:text-base">
                    {{ $repoBuild === [] ? __(':file sets no build settings', ['file' => $repoFile]) : trans_choice(':file overrides :count setting on each deploy|:file overrides :count settings on each deploy', count($repoBuild), ['file' => $repoFile]) }}
                </span>
                <span @class(['shrink-0 text-xs', 'text-amber-600 dark:text-amber-300' => $warnings !== [], 'text-brand-moss' => $warnings === []])>{{ $warnings !== [] ? trans_choice(':count warning|:count warnings', count($warnings)) : $repoFile }}</span>
                <x-heroicon-m-chevron-right class="h-4 w-4 shrink-0 text-brand-mist" aria-hidden="true" />
            </button>
        @endif
        <p class="pt-3 text-xs text-brand-moss">{{ __('Changes apply on the next deploy.') }}</p>
    </div>
</section>

{{-- One setting --}}
<x-modal name="build-setting" maxWidth="xl" overlayClass="bg-brand-ink/40" focusable>
    @if ($editing !== '')
        @php
            [$title, $help] = match ($editing) {
                'push' => [__('Deploy on push'), __('A push to :branch starts a production deploy. Turned off, deploys start only from the dashboard, a deploy hook or the CLI.', ['branch' => $edgeBranch])],
                'command' => [__('Build command'), __('Runs in the build folder before publishing, for example npm ci && npm run build.')],
                'output' => [__('Output folder'), __('The folder your build writes the site into, relative to the build folder.')],
                'root' => [__('Repository root'), __('For a monorepo: the subfolder the build runs in. Pushes that don’t touch it skip deploying. Leave empty for the whole repository.')],
                'spa' => [__('Unknown paths'), __('A single-page app handles its own routes, so unknown paths should load index.html.')],
                'releases' => [__('Releases to keep'), __('Older releases are deleted from storage and can’t be rolled back to.')],
                default => [__('Deploy id in the footer'), __('Prints the live deployment id at the bottom of HTML pages, handy when checking what’s live.')],
            };
            $repoKey = ['command' => 'command', 'output' => 'output', 'root' => 'root'][$editing] ?? null;
        @endphp
        <form wire:submit="saveSetting" class="space-y-5 p-6 sm:p-7">
            <div class="flex items-start justify-between gap-4">
                <div>
                    <h2 class="text-lg font-semibold text-brand-ink">{{ $title }}</h2>
                    <p class="mt-0.5 text-sm text-brand-moss">{{ $help }}</p>
                </div>
                <button type="button" wire:click="closeSetting" class="dply-icon-btn h-9 w-9 shrink-0" aria-label="{{ __('Close') }}">
                    <x-heroicon-o-x-mark class="h-5 w-5" aria-hidden="true" />
                </button>
            </div>

            @switch($editing)
                @case('push')
                    <x-sheet.toggle wire:model="buildForm.edge_deploy_on_push" :label="__('Deploy when :branch changes', ['branch' => $edgeBranch])" />
                    @break
                @case('command')
                    <input type="text" wire:model="buildForm.edge_build_command" aria-label="{{ __('Build command') }}" autocomplete="off" spellcheck="false" class="dply-input mt-0 font-mono text-sm" />
                    @break
                @case('output')
                    <input type="text" wire:model="buildForm.edge_output_dir" aria-label="{{ __('Output folder') }}" placeholder="dist" autocomplete="off" spellcheck="false" class="dply-input mt-0 font-mono text-sm" />
                    @break
                @case('root')
                    <input type="text" wire:model="buildForm.edge_repo_root" aria-label="{{ __('Repository root') }}" placeholder="apps/web" autocomplete="off" spellcheck="false" class="dply-input mt-0 font-mono text-sm" />
                    @break
                @case('spa')
                    <div class="grid gap-2">
                        @foreach (['true' => [__('Serve index.html'), __('For single-page apps like React or Vue.')], 'false' => [__('Return 404'), __('Shows your 404 page. For sites where every page is a real file.')]] as $value => [$t, $h])
                            @php $on = $buildForm->edge_spa_fallback === ($value === 'true'); @endphp
                            <button type="button" wire:click="$set('buildForm.edge_spa_fallback', {{ $value }})" @class(['flex items-start gap-3 rounded-xl border px-4 py-3 text-left', 'border-brand-sage' => $on, 'border-brand-ink/10' => ! $on]) aria-pressed="{{ $on ? 'true' : 'false' }}">
                                <span @class(['mt-1 h-3.5 w-3.5 shrink-0 rounded-full border', 'border-brand-sage bg-brand-sage' => $on, 'border-brand-ink/30' => ! $on])></span>
                                <span><span class="block text-sm font-semibold text-brand-ink">{{ $t }}</span><span class="block text-xs text-brand-moss">{{ $h }}</span></span>
                            </button>
                        @endforeach
                    </div>
                    @break
                @case('releases')
                    <input type="number" min="1" max="50" wire:model="buildForm.edge_releases_to_keep" aria-label="{{ __('Releases to keep') }}" class="dply-input mt-0 w-28" />
                    @break
                @default
                    <x-sheet.toggle wire:model="buildForm.edge_deploy_footer_enabled" :label="__('Show the deploy id in the page footer')" />
            @endswitch

            @foreach (['buildForm.edge_build_command', 'buildForm.edge_output_dir', 'buildForm.edge_repo_root', 'buildForm.edge_releases_to_keep'] as $field)
                @error($field) <x-sheet.note tone="danger">{{ $message }}</x-sheet.note> @enderror
            @endforeach

            @if ($repoKey && $fromRepo($repoKey))
                <x-sheet.note>{{ __(':file sets :value, which wins on each deploy. Change it there, or remove it to use this value.', ['file' => $repoFile, 'value' => (string) $repoBuild[$repoKey]]) }}</x-sheet.note>
            @endif

            <div class="flex justify-end gap-2">
                <x-sheet.button type="button" wire:click="closeSetting">{{ __('Cancel') }}</x-sheet.button>
                <x-sheet.button type="submit" variant="primary" wire:loading.attr="disabled" wire:target="saveSetting">{{ __('Save') }}</x-sheet.button>
            </div>
        </form>
    @endif
</x-modal>

{{-- dply.yaml build keys --}}
@if ($repoConfig !== null)
    <x-modal name="build-repo" maxWidth="xl" overlayClass="bg-brand-ink/40" focusable>
        <div class="space-y-4 p-6 sm:p-7">
            <div class="flex items-start justify-between gap-4">
                <div>
                    <h2 class="text-lg font-semibold text-brand-ink">{{ __('Build settings in :file', ['file' => $repoFile]) }}</h2>
                    <p class="mt-0.5 text-sm text-brand-moss">{{ __('Read from the last deploy. They win over the dashboard on each deploy. Routing rules live under Routing.') }}</p>
                </div>
                <button type="button" x-on:click="$dispatch('close-modal', 'build-repo')" class="dply-icon-btn h-9 w-9 shrink-0" aria-label="{{ __('Close') }}">
                    <x-heroicon-o-x-mark class="h-5 w-5" aria-hidden="true" />
                </button>
            </div>
            @if ($repoBuild !== [])
                <dl class="divide-y divide-brand-ink/10 border-y border-brand-ink/10 text-sm">
                    @foreach ($repoBuild as $key => $value)
                        <div class="flex justify-between gap-3 py-2.5">
                            <dt class="font-mono text-brand-moss">build.{{ $key }}</dt>
                            <dd class="break-all text-right font-mono text-brand-ink">{{ is_scalar($value) ? $value : json_encode($value) }}</dd>
                        </div>
                    @endforeach
                </dl>
            @else
                <p class="text-sm text-brand-moss">{{ __('No build: block, so the dashboard settings are used.') }}</p>
            @endif
            @if ($warnings !== [])
                <x-sheet.note>
                    <span class="font-semibold">{{ __('Warnings from the last deploy') }}</span>
                    <ul class="mt-1 list-disc space-y-0.5 pl-4 font-mono text-xs">
                        @foreach ($warnings as $warning)
                            <li>{{ $warning }}</li>
                        @endforeach
                    </ul>
                </x-sheet.note>
            @endif
            <x-edge-yaml-example :file="$repoFile">build:
  command: "npm ci && npm run build"
  output: "dist"
  root: "apps/web"</x-edge-yaml-example>
        </div>
    </x-modal>
@endif
