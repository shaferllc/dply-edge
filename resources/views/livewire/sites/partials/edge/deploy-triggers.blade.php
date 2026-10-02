{{-- Deploy triggers: one sentence about what starts a deploy, then a row per trigger; each opens a dialog. --}}
@php
    use App\Modules\Edge\Support\EdgePreviewPolicy;

    $hooks = (! $site->isEdgePreview()) ? $this->edgeDeployHooks() : collect();
    $canEdit = auth()->user()?->can('update', $site) ?? false;
    $previews = EdgePreviewPolicy::for($site);
    $lastEvent = $edgeWebhookLastEventAt ? \Illuminate\Support\Carbon::parse($edgeWebhookLastEventAt) : null;
    $lastHook = $hooks->filter(fn ($h) => $h->last_used_at)->sortByDesc('last_used_at')->first();
    $openHook = $openHookId ? $hooks->firstWhere('id', $openHookId) : null;
    $githubAccounts = collect($linkedSourceControlAccounts ?? [])->filter(fn ($a) => ($a['provider'] ?? '') === 'github');
    $accountLabel = $githubAccounts->firstWhere('id', $buildForm->edge_webhook_account_id)['label'] ?? null;
    $onDplyGit = \App\Modules\SourceControl\Services\DplyGit::siteUses($site);
    $dplyGitRemote = $onDplyGit ? (string) ($site->edgeMeta()['source']['repo'] ?? '') : null;
    $dplyGitMove = is_array($site->edgeMeta()['dply_git_move'] ?? null) ? $site->edgeMeta()['dply_git_move'] : null;
    $canMoveToDplyGit = ! $onDplyGit && $canEdit && \App\Modules\Edge\Support\EdgeContainerConnections::flagOn('git', $site->organization);
    $pushDeploys = ($edgeGithubWebhookConnected || $onDplyGit) && $edgeDeployOnPush;
    $buildUrl = route('sites.show', ['server' => $server, 'site' => $site, 'section' => 'build']);
    $row = 'flex min-h-12 w-full items-center gap-3 border-b border-brand-ink/10 py-3 text-left hover:bg-brand-sand/20';
    $close = fn (string $m) => '<button type="button" x-on:click="$dispatch(\'close-modal\', \''.$m.'\')" class="dply-icon-btn h-9 w-9 shrink-0" aria-label="'.e(__('Close')).'">'.svg('heroicon-o-x-mark', 'h-5 w-5', ['aria-hidden' => 'true'])->toHtml().'</button>';
    $copy = fn (string $value) => '<button type="button" x-data="{ c: false }" x-on:click="navigator.clipboard.writeText('.e(json_encode($value)).'); c = true; setTimeout(() => c = false, 1500)" class="shrink-0 rounded-md border border-brand-ink/15 px-2 py-1 text-xs font-medium text-brand-moss hover:bg-brand-sand/40"><span x-show="! c">'.e(__('Copy')).'</span><span x-show="c" x-cloak>'.e(__('Copied')).'</span></button>';
@endphp

<section class="space-y-8 px-5 py-8 sm:px-10 sm:py-10">
    <div>
        <p class="text-2xs font-semibold uppercase tracking-[0.16em] text-brand-mist">{{ __('Deploy triggers') }}</p>
        <p class="mt-3 max-w-3xl text-2xl font-medium leading-snug tracking-tight text-brand-ink sm:text-3xl">
            @if ($edgeIsPreviewChild)
                {{ __('This is a preview. Pushes to its pull request update it.') }}
            @elseif ($pushDeploys && $onDplyGit)
                {{ __('Pushing to') }} <span class="font-mono text-brand-sage">{{ $edgeBranch }}</span> {{ __('on dply Git deploys to production.') }}
                @if ($previews['enabled']) {{ __('Other branches get a preview.') }} @endif
            @elseif ($onDplyGit)
                <span class="text-amber-600 dark:text-amber-300">{{ __('Pushes to dply Git don’t deploy:') }}</span> {{ __('Deploy on push is off in Build.') }}
            @elseif ($pushDeploys)
                {{ __('Pushing to') }} <span class="font-mono text-brand-sage">{{ $edgeBranch }}</span>@if ($edgeRepo) {{ __('on :repo', ['repo' => $edgeRepo]) }}@endif {{ __('deploys to production.') }}
                @if ($previews['enabled']) {{ __('Pull requests get a preview.') }} @endif
            @elseif ($edgeGithubWebhookConnected)
                <span class="text-amber-600 dark:text-amber-300">{{ __('GitHub is connected, but pushes don’t deploy:') }}</span> {{ __('Deploy on push is off in Build.') }}
            @else
                <span class="text-amber-600 dark:text-amber-300">{{ __('Pushes don’t deploy yet.') }}</span> {{ __('Connect GitHub, or deploy from the dashboard or CLI.') }}
            @endif
            @unless ($site->isEdgePreview())
                @if ($hooks->isNotEmpty())
                    <span class="text-brand-sage">{{ trans_choice(':count deploy hook|:count deploy hooks', $hooks->count()) }}</span>
                    {{ $hooks->count() === 1 ? __('can start a deploy too') : __('can start deploys too') }}{{ $lastHook ? __('; the last one fired :when.', ['when' => $lastHook->last_used_at->diffForHumans()]) : __('; none has fired yet.') }}
                @endif
            @endunless
        </p>
    </div>

    @if (! $edgeIsPreviewChild && ($onDplyGit || $canMoveToDplyGit))
        <div @if (($dplyGitMove['status'] ?? null) === 'moving') wire:poll.5s @endif>
            <p class="border-b border-brand-ink/10 pb-2 text-sm font-semibold text-brand-ink">{{ __('dply Git') }}</p>
            @if ($onDplyGit)
                <div class="flex min-h-12 items-center gap-3 border-b border-brand-ink/10 py-3">
                    <code class="min-w-0 flex-1 break-all font-mono text-xs text-brand-ink sm:text-sm">{{ $dplyGitRemote }}</code>
                    {!! $copy($dplyGitRemote) !!}
                </div>
                @if ($canEdit)
                    <button type="button" wire:click="createDplyGitToken" wire:loading.attr="disabled" wire:target="createDplyGitToken" class="{{ $row }} font-medium text-brand-sage">
                        <x-heroicon-m-key class="h-4 w-4 shrink-0" aria-hidden="true" />
                        <span class="flex-1 text-sm sm:text-base">{{ __('Create a push token') }}</span>
                    </button>
                    <button type="button" wire:click="openConfirmActionModal('revokeDplyGitTokens', [], @js(__('Revoke all push tokens')), @js(__('Revoke every push token for this app? Anyone pushing with one has to get a new token.')), @js(__('Revoke')), true)" class="{{ $row }} text-sm text-red-600 dark:text-red-400 sm:text-base">
                        <x-heroicon-m-no-symbol class="h-4 w-4 shrink-0" aria-hidden="true" />
                        <span class="flex-1">{{ __('Revoke all push tokens') }}</span>
                    </button>
                    @if ($movedFrom = $site->edgeMeta()['dply_git']['moved_from'] ?? null)
                        <button type="button" wire:click="confirmMoveOffDplyGit" class="{{ $row }} text-sm text-brand-ink sm:text-base">
                            <x-heroicon-m-arrow-uturn-left class="h-4 w-4 shrink-0 text-brand-moss" aria-hidden="true" />
                            <span class="flex-1">{{ __('Move back to :repo', ['repo' => $movedFrom]) }}</span>
                        </button>
                    @endif
                @endif
                <p class="pt-3 text-xs text-brand-moss">{{ __('Your code lives on dply. Push to :branch to deploy; push any other branch for a preview. Each person or agent can have their own token.', ['branch' => $edgeBranch]) }}</p>
                @if ($note = $site->edgeMeta()['dply_git']['history_note'] ?? null)
                    <p class="pt-2 text-xs text-amber-700 dark:text-amber-300">{{ $note }}</p>
                @endif
            @elseif (($dplyGitMove['status'] ?? null) === 'moving')
                <p class="flex items-center gap-2 border-b border-brand-ink/10 py-3 text-sm text-brand-moss">
                    <x-heroicon-o-arrow-path class="h-4 w-4 shrink-0 animate-spin" aria-hidden="true" />
                    {{ __('Copying every branch and tag to dply Git…') }}
                </p>
            @else
                @if (($dplyGitMove['status'] ?? null) === 'failed')
                    <p class="border-b border-brand-ink/10 py-3 text-sm text-red-600 dark:text-red-400">{{ __('The last move failed: :error', ['error' => $dplyGitMove['error'] ?? '']) }}</p>
                @endif
                <button type="button" wire:click="openConfirmActionModal('moveToDplyGit', [], @js(__('Move to dply Git')), @js(__('Copy every branch and tag of :repo to dply Git and deploy from there? The GitHub webhook is disconnected; your GitHub repo is left as it is.', ['repo' => $edgeRepo ?: __('this repository')])), @js(__('Move')), false)" class="{{ $row }} font-medium text-brand-sage">
                    <x-heroicon-m-arrow-right-circle class="h-4 w-4 shrink-0" aria-hidden="true" />
                    <span class="flex-1 text-sm sm:text-base">{{ __('Move this app’s code to dply Git') }}</span>
                    <span class="shrink-0 text-xs text-brand-moss">{{ __('Beta') }}</span>
                </button>
                <p class="pt-3 text-xs text-brand-moss">{{ __('Host the repository on dply instead of GitHub. git push deploys, branches get previews, and agents can push with their own tokens.') }}</p>
            @endif
        </div>
    @endif

    @if (! $edgeIsPreviewChild && ! $onDplyGit)
        <div>
            <p class="border-b border-brand-ink/10 pb-2 text-sm font-semibold text-brand-ink">{{ __('GitHub') }}</p>
            <button type="button" x-on:click="$dispatch('open-modal', 'github-trigger')" class="{{ $row }}">
                <span class="flex-1 text-sm text-brand-ink sm:text-base">
                    {{ $edgeGithubWebhookConnected
                        ? ($edgeRepo ? __('Pushes to :repo reach dply', ['repo' => $edgeRepo]) : __('GitHub pushes reach dply'))
                        : __('GitHub isn’t connected, so pushes don’t reach dply') }}
                </span>
                <span @class(['shrink-0 text-xs', 'text-brand-sage' => $edgeGithubWebhookConnected, 'text-amber-600 dark:text-amber-300' => ! $edgeGithubWebhookConnected])>
                    {{ $edgeGithubWebhookConnected ? ($lastEvent ? __('On · :when', ['when' => $lastEvent->diffForHumans(short: true)]) : __('On')) : __('Off') }}
                </span>
                <x-heroicon-m-chevron-right class="h-4 w-4 shrink-0 text-brand-mist" aria-hidden="true" />
            </button>
            <a href="{{ $buildUrl }}" wire:navigate class="{{ $row }}">
                <span class="flex-1 text-sm text-brand-ink sm:text-base">
                    {{ $edgeDeployOnPush ? __('A push to :branch deploys it', ['branch' => $edgeBranch]) : __('A push to :branch doesn’t deploy it', ['branch' => $edgeBranch]) }}
                </span>
                <span class="shrink-0 text-xs text-brand-moss">{{ __('Build settings') }}</span>
                <x-heroicon-m-chevron-right class="h-4 w-4 shrink-0 text-brand-mist" aria-hidden="true" />
            </a>
            <a href="{{ route('sites.show', ['server' => $server, 'site' => $site, 'section' => 'previews']) }}" wire:navigate class="{{ $row }}">
                <span class="flex-1 text-sm text-brand-ink sm:text-base">{{ $previews['enabled'] ? __('Each pull request gets its own preview') : __('Pull requests don’t get previews') }}</span>
                <span class="shrink-0 text-xs text-brand-moss">{{ __('Previews') }}</span>
                <x-heroicon-m-chevron-right class="h-4 w-4 shrink-0 text-brand-mist" aria-hidden="true" />
            </a>
        </div>
    @endif

    @unless ($site->isEdgePreview())
        <div>
            <p class="border-b border-brand-ink/10 pb-2 text-sm font-semibold text-brand-ink">{{ __('Deploy hooks') }}</p>
            @foreach ($hooks as $hook)
                <button type="button" wire:click="openHook(@js((string) $hook->id))" class="{{ $row }}" wire:key="hook-row-{{ $hook->id }}">
                    <span class="flex-1 text-sm text-brand-ink sm:text-base">
                        {{ $hook->last_used_at ? __('“:name” last fired :when', ['name' => $hook->name, 'when' => $hook->last_used_at->diffForHumans()]) : __('“:name” hasn’t fired yet', ['name' => $hook->name]) }}
                    </span>
                    <span class="shrink-0 font-mono text-xs text-brand-moss">{{ $hook->token_prefix }}…</span>
                    <x-heroicon-m-chevron-right class="h-4 w-4 shrink-0 text-brand-mist" aria-hidden="true" />
                </button>
            @endforeach
            @if ($canEdit)
                <button type="button" wire:click="openNewHook" class="{{ $row }} font-medium text-brand-sage">
                    <x-heroicon-m-plus class="h-4 w-4 shrink-0" aria-hidden="true" />
                    <span class="flex-1 text-sm sm:text-base">{{ __('Create a deploy hook') }}</span>
                </button>
            @elseif ($hooks->isEmpty())
                <p class="border-b border-brand-ink/10 py-3 text-sm text-brand-moss">{{ __('No deploy hooks.') }}</p>
            @endif
            <p class="pt-3 text-xs text-brand-moss">{{ __('A URL a CMS or script can POST to when content changes. Each POST rebuilds the production branch.') }}</p>
        </div>
    @endunless

    @unless ($edgeIsPreviewChild)
        <div>
            <p class="border-b border-brand-ink/10 pb-2 text-sm font-semibold text-brand-ink">{{ __('More') }}</p>
            @unless ($onDplyGit)
                <button type="button" x-on:click="$dispatch('open-modal', 'manual-webhook')" class="{{ $row }}">
                    <span class="flex-1 text-sm text-brand-ink sm:text-base">{{ __('Register the GitHub webhook yourself') }}</span>
                    <span class="shrink-0 text-xs text-brand-moss">{{ __('Manual') }}</span>
                    <x-heroicon-m-chevron-right class="h-4 w-4 shrink-0 text-brand-mist" aria-hidden="true" />
                </button>
            @endunless
            @if ($site->organization)
                <a href="{{ route('organizations.notification-channels', $site->organization) }}" wire:navigate class="{{ $row }}">
                    <span class="flex-1 text-sm text-brand-ink sm:text-base">{{ __('Get told when a deploy succeeds or fails') }}</span>
                    <span class="shrink-0 text-xs text-brand-moss">{{ __('Notifications') }}</span>
                    <x-heroicon-m-chevron-right class="h-4 w-4 shrink-0 text-brand-mist" aria-hidden="true" />
                </a>
            @endif
        </div>
    @endunless
</section>

{{-- GitHub auto-deploy --}}
<x-modal name="github-trigger" maxWidth="xl" overlayClass="bg-brand-ink/40" focusable>
    <div class="space-y-5 p-6 sm:p-7">
        <div class="flex items-start justify-between gap-4">
            <div>
                <h2 class="text-lg font-semibold text-brand-ink">{{ __('Deploy from GitHub') }}</h2>
                <p class="mt-0.5 text-sm text-brand-moss">
                    {{ $edgeGithubWebhookConnected
                        ? ($lastEvent ? __('Connected. GitHub last sent an event :when.', ['when' => $lastEvent->diffForHumans()]) : __('Connected. No events from GitHub yet.'))
                        : __('dply adds a webhook to the repository so pushes and pull requests reach it.') }}
                </p>
            </div>
            {!! $close('github-trigger') !!}
        </div>
        <x-sheet.field :label="__('Through this GitHub account')" for="gh-account">
            <select id="gh-account" wire:model.live="buildForm.edge_webhook_account_id" class="dply-input mt-0" @disabled(! $canEdit)>
                <option value="">{{ __('Select a linked GitHub account…') }}</option>
                @foreach ($githubAccounts as $account)
                    <option value="{{ $account['id'] }}">{{ $account['label'] }}</option>
                @endforeach
            </select>
        </x-sheet.field>
        @unless ($edgeGithubWebhookConnected)
            <x-quick-deploy-oauth-hint provider="github" class="text-xs leading-relaxed text-brand-mist" />
        @endunless
        @if ($canEdit)
            <div class="flex justify-end gap-2">
                @if ($edgeGithubWebhookConnected)
                    <x-sheet.button type="button" variant="danger" wire:click="disableEdgeGithubWebhook" wire:loading.attr="disabled" wire:target="disableEdgeGithubWebhook">{{ __('Disconnect') }}</x-sheet.button>
                @else
                    <x-sheet.button type="button" variant="primary" wire:click="enableEdgeGithubWebhook" wire:loading.attr="disabled" wire:target="enableEdgeGithubWebhook">
                        <span wire:loading.remove wire:target="enableEdgeGithubWebhook">{{ __('Connect') }}</span>
                        <span wire:loading wire:target="enableEdgeGithubWebhook">{{ __('Connecting…') }}</span>
                    </x-sheet.button>
                @endif
            </div>
        @endif
    </div>
</x-modal>

{{-- One deploy hook --}}
<x-modal name="deploy-hook" maxWidth="xl" overlayClass="bg-brand-ink/40" focusable>
    @if ($openHook)
        <div class="space-y-5 p-6 sm:p-7">
            <div class="flex items-start justify-between gap-4">
                <div>
                    <h2 class="text-lg font-semibold text-brand-ink">“{{ $openHook->name }}”</h2>
                    <p class="mt-0.5 text-sm text-brand-moss">
                        {{ $openHook->last_used_at ? __('Last fired :when.', ['when' => $openHook->last_used_at->diffForHumans()]) : __('Hasn’t fired yet.') }}
                        {{ __('Created :when.', ['when' => $openHook->created_at?->diffForHumans()]) }}
                    </p>
                </div>
                {!! $close('deploy-hook') !!}
            </div>
            <p class="text-sm text-brand-moss">{{ __('Its URL starts with :prefix… and was shown once when it was created. Lost it? Revoke this hook and create a new one.', ['prefix' => $openHook->token_prefix]) }}</p>
            @if ($canEdit)
                <div class="flex justify-end">
                    <x-sheet.button type="button" variant="danger" wire:click="revokeOpenHook" wire:confirm="{{ __('Revoke this hook? Its URL stops working immediately.') }}">{{ __('Revoke') }}</x-sheet.button>
                </div>
            @endif
        </div>
    @endif
</x-modal>

{{-- Create a deploy hook: name it, then show the URL once --}}
<x-modal name="deploy-hook-new" maxWidth="xl" overlayClass="bg-brand-ink/40" focusable>
    <div class="space-y-5 p-6 sm:p-7">
        @if ($edge_just_minted_deploy_hook_url === null)
            <form wire:submit="mintEdgeDeployHook" class="space-y-5">
                <div class="flex items-start justify-between gap-4">
                    <div>
                        <h2 class="text-lg font-semibold text-brand-ink">{{ __('Create a deploy hook') }}</h2>
                        <p class="mt-0.5 text-sm text-brand-moss">{{ __('Name it after what will call it, so you can tell hooks apart later.') }}</p>
                    </div>
                    {!! $close('deploy-hook-new') !!}
                </div>
                <x-sheet.field :label="__('Name')" for="hook-name">
                    <input id="hook-name" type="text" wire:model="edge_new_deploy_hook_name" placeholder="Sanity publish" class="dply-input mt-0" autocomplete="off" />
                </x-sheet.field>
                <div class="flex justify-end gap-2">
                    <x-sheet.button type="button" x-on:click="$dispatch('close-modal', 'deploy-hook-new')">{{ __('Cancel') }}</x-sheet.button>
                    <x-sheet.button type="submit" variant="primary" wire:loading.attr="disabled" wire:target="mintEdgeDeployHook">{{ __('Create') }}</x-sheet.button>
                </div>
            </form>
        @else
            <div class="flex items-start justify-between gap-4">
                <div>
                    <h2 class="text-lg font-semibold text-brand-ink">{{ __('Your hook is ready') }}</h2>
                    <p class="mt-0.5 text-sm text-amber-700 dark:text-amber-300">{{ __('Copy the URL now. It won’t be shown again.') }}</p>
                </div>
            </div>
            <div class="flex items-center gap-2 rounded-lg bg-brand-sand/30 px-3 py-2">
                <code class="min-w-0 flex-1 break-all font-mono text-xs text-brand-ink">{{ $edge_just_minted_deploy_hook_url }}</code>
                {!! $copy($edge_just_minted_deploy_hook_url) !!}
            </div>
            <div>
                <p class="text-sm font-semibold text-brand-ink">{{ __('Try it') }}</p>
                <div class="mt-2 flex items-start gap-2 rounded-lg bg-zinc-950 px-3 py-2">
                    <code class="min-w-0 flex-1 break-all font-mono text-xs text-zinc-200">curl -X POST {{ $edge_just_minted_deploy_hook_url }}</code>
                    {!! $copy('curl -X POST '.$edge_just_minted_deploy_hook_url) !!}
                </div>
                <p class="mt-2 text-xs text-brand-moss">{{ __('Each POST rebuilds the production branch. In your CMS, paste the URL wherever it asks for a webhook or build hook.') }}</p>
            </div>
            <div class="flex justify-end">
                <x-sheet.button type="button" variant="primary" wire:click="dismissEdgeDeployHookUrl" x-on:click="$dispatch('close-modal', 'deploy-hook-new')">{{ __('I’ve copied it') }}</x-sheet.button>
            </div>
        @endif
    </div>
</x-modal>

{{-- dply Git push token: shown once --}}
<x-modal name="dply-git-token" maxWidth="xl" overlayClass="bg-brand-ink/40" focusable>
    @if ($dplyGitToken && $dplyGitRemote)
        <div class="space-y-5 p-6 sm:p-7">
            <div>
                <h2 class="text-lg font-semibold text-brand-ink">{{ __('Your push token') }}</h2>
                <p class="mt-0.5 text-sm text-amber-700 dark:text-amber-300">{{ __('Copy it now. It won’t be shown again, and it expires in 30 days.') }}</p>
            </div>
            <div class="flex items-center gap-2 rounded-lg bg-brand-sand/30 px-3 py-2">
                <code class="min-w-0 flex-1 break-all font-mono text-xs text-brand-ink">{{ $dplyGitToken }}</code>
                {!! $copy($dplyGitToken) !!}
            </div>
            @php
                $authedRemote = preg_replace('#^https://#', 'https://x:'.rawurlencode($dplyGitToken).'@', $dplyGitRemote);
                $commands = "git remote add dply {$authedRemote}\ngit push dply {$edgeBranch}";
            @endphp
            <div>
                <p class="text-sm font-semibold text-brand-ink">{{ __('Push from your machine') }}</p>
                <div class="mt-2 flex items-start gap-2 rounded-lg bg-brand-sand/30 px-3 py-2">
                    <code class="min-w-0 flex-1 whitespace-pre-wrap break-all font-mono text-xs text-brand-ink">{{ $commands }}</code>
                    {!! $copy($commands) !!}
                </div>
                <p class="mt-2 text-xs text-brand-moss">{{ __('The token sits in the remote URL, so keep it out of shared machines. Or run dply git token in the CLI.') }}</p>
            </div>
            <div class="flex justify-end">
                <x-sheet.button type="button" variant="primary" wire:click="dismissDplyGitToken" x-on:click="$dispatch('close-modal', 'dply-git-token')">{{ __('I’ve copied it') }}</x-sheet.button>
            </div>
        </div>
    @endif
</x-modal>

{{-- Manual webhook --}}
<x-modal name="manual-webhook" maxWidth="xl" overlayClass="bg-brand-ink/40" focusable>
    <div class="space-y-5 p-6 sm:p-7">
        <div class="flex items-start justify-between gap-4">
            <div>
                <h2 class="text-lg font-semibold text-brand-ink">{{ __('Register the GitHub webhook yourself') }}</h2>
                <p class="mt-0.5 text-sm text-brand-moss">{{ __('Only if you can’t connect an account. In the repository, open Settings → Webhooks → Add webhook, choose application/json, and send push and pull request events.') }}</p>
            </div>
            {!! $close('manual-webhook') !!}
        </div>
        <div>
            <p class="text-xs font-semibold text-brand-ink">{{ __('Payload URL') }}</p>
            <div class="mt-1 flex items-center gap-2 rounded-lg bg-brand-sand/30 px-3 py-2">
                <code class="min-w-0 flex-1 break-all font-mono text-xs text-brand-ink">{{ $site->edgeGithubHookUrl() }}</code>
                {!! $copy($site->edgeGithubHookUrl()) !!}
            </div>
        </div>
        @if ($site->webhook_secret && $canEdit)
            <div x-data="{ show: false }">
                <p class="text-xs font-semibold text-brand-ink">{{ __('Secret') }}</p>
                <div class="mt-1 flex items-center gap-2 rounded-lg bg-brand-sand/30 px-3 py-2">
                    <code class="min-w-0 flex-1 break-all font-mono text-xs text-brand-ink" x-text="show ? @js($site->webhook_secret) : '••••••••••••••••'"></code>
                    <button type="button" x-on:click="show = ! show" class="shrink-0 text-xs font-medium text-brand-moss hover:underline" x-text="show ? @js(__('Hide')) : @js(__('Show'))"></button>
                    {!! $copy($site->webhook_secret) !!}
                </div>
            </div>
        @endif
    </div>
</x-modal>
