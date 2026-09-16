@php
    $hooks = (! $site->isEdgePreview()) ? $this->edgeDeployHooks() : collect();
@endphp

{{-- Primary: GitHub auto-deploy status + enable. Hooks next. Manual/notifications under Advanced. --}}
@if (! $edgeIsPreviewChild)
    <section class="border-b border-brand-ink/10">
        <div class="flex flex-wrap items-center justify-between gap-3 border-b border-brand-ink/10 bg-brand-sand/15 px-5 py-3 sm:px-6">
            <div class="min-w-0">
                <p class="text-2xs font-semibold uppercase tracking-[0.16em] text-brand-mist">{{ __('GitHub') }}</p>
                <p class="mt-0.5 text-sm font-semibold text-brand-ink">
                    {{ $edgeGithubWebhookConnected ? __('Auto-deploy connected') : __('Auto-deploy off') }}
                </p>
                @if ($edgeWebhookLastEventAt)
                    <p class="mt-0.5 text-xs text-brand-moss">{{ __('Last event :time', ['time' => $edgeWebhookLastEventAt]) }}</p>
                @endif
            </div>
            <div class="flex shrink-0 flex-wrap items-center gap-2">
                @if ($edgeGithubWebhookConnected)
                    <button
                        type="button"
                        wire:click="disableEdgeGithubWebhook"
                        wire:loading.attr="disabled"
                        wire:target="disableEdgeGithubWebhook"
                        class="inline-flex items-center gap-1.5 rounded-lg border border-rose-200 bg-white px-3 py-1.5 text-xs font-semibold text-rose-900 shadow-sm hover:bg-rose-50 dark:border-raw-rose-900/40 dark:bg-zinc-900 dark:text-rose-300"
                    >
                        {{ __('Disable') }}
                    </button>
                @else
                    <button
                        type="button"
                        wire:click="enableEdgeGithubWebhook"
                        wire:loading.attr="disabled"
                        wire:target="enableEdgeGithubWebhook"
                        class="inline-flex items-center gap-1.5 rounded-lg bg-brand-ink px-3 py-1.5 text-xs font-semibold text-white shadow-sm hover:bg-brand-ink/90 disabled:cursor-wait disabled:opacity-60"
                    >
                        <x-heroicon-o-bolt class="h-4 w-4" />
                        <span wire:loading.remove wire:target="enableEdgeGithubWebhook">{{ __('Enable') }}</span>
                        <span wire:loading wire:target="enableEdgeGithubWebhook">{{ __('Connecting…') }}</span>
                    </button>
                @endif
            </div>
        </div>

        <div class="space-y-3 px-5 py-4 sm:px-6">
            <label class="block text-sm">
                <span class="block text-xs font-semibold uppercase tracking-[0.12em] text-brand-mist">{{ __('Linked GitHub account') }}</span>
                <select
                    wire:model.live="buildForm.edge_webhook_account_id"
                    class="mt-1.5 w-full rounded-lg border border-brand-ink/15 bg-white px-3 py-2 text-sm shadow-sm focus:border-brand-ink focus:ring-1 focus:ring-brand-ink dark:border-brand-mist/20 dark:bg-zinc-900"
                >
                    <option value="">{{ __('Select a linked GitHub account…') }}</option>
                    @foreach (($linkedSourceControlAccounts ?? []) as $account)
                        @if (($account['provider'] ?? '') === 'github')
                            <option value="{{ $account['id'] }}">{{ $account['label'] }}</option>
                        @endif
                    @endforeach
                </select>
            </label>
            @unless ($edgeGithubWebhookConnected)
                <x-quick-deploy-oauth-hint provider="github" class="text-xs leading-relaxed text-brand-mist" />
            @endunless
        </div>
    </section>
@endif

@if (! $site->isEdgePreview())
    <section class="border-b border-brand-ink/10">
        <div class="border-b border-brand-ink/10 bg-brand-sand/15 px-5 py-3 sm:px-6">
            <p class="text-2xs font-semibold uppercase tracking-[0.16em] text-brand-mist">{{ __('Deploy hooks') }}</p>
            <p class="mt-0.5 text-xs text-brand-moss">{{ __('POST URLs for CMS publish → redeploy.') }}</p>
        </div>

        @if ($edge_just_minted_deploy_hook_url !== null)
            <div class="border-b border-emerald-300/60 bg-emerald-50 px-5 py-3 text-sm text-emerald-950 dark:border-raw-emerald-900/40 dark:bg-raw-emerald-950/30 dark:text-raw-emerald-100 sm:px-6">
                <p class="font-semibold">{{ __('Copy this URL now — it won’t be shown again.') }}</p>
                <div class="mt-2 flex flex-wrap items-center gap-2" x-data="{ copied: false }">
                    <code class="min-w-0 flex-1 break-all rounded-lg bg-white px-3 py-2 font-mono text-xs text-brand-ink shadow-sm dark:bg-zinc-900">{{ $edge_just_minted_deploy_hook_url }}</code>
                    <button
                        type="button"
                        class="rounded-lg border border-emerald-300/60 bg-white px-2.5 py-1.5 text-xs font-semibold text-emerald-900"
                        @click="navigator.clipboard.writeText(@js($edge_just_minted_deploy_hook_url)); copied = true; setTimeout(() => copied = false, 2000)"
                    >
                        <span x-show="!copied">{{ __('Copy') }}</span>
                        <span x-show="copied" x-cloak>{{ __('Copied') }}</span>
                    </button>
                    <button type="button" wire:click="dismissEdgeDeployHookUrl" class="text-xs font-semibold text-emerald-900 hover:underline dark:text-raw-emerald-200">{{ __('Dismiss') }}</button>
                </div>
            </div>
        @endif

        @can('update', $site)
            <form wire:submit.prevent="mintEdgeDeployHook" class="flex flex-wrap items-end gap-2 border-b border-brand-ink/10 px-5 py-4 sm:px-6">
                <label class="min-w-[14rem] flex-1">
                    <span class="block text-xs font-semibold uppercase tracking-[0.12em] text-brand-mist">{{ __('Name') }}</span>
                    <input
                        type="text"
                        wire:model="edge_new_deploy_hook_name"
                        placeholder="Sanity prod publish"
                        class="mt-1 block w-full rounded-lg border border-brand-ink/15 bg-white px-3 py-2 text-sm text-brand-ink shadow-sm focus:border-brand-sage focus:ring-1 focus:ring-brand-sage dark:border-brand-mist/20 dark:bg-zinc-900"
                    />
                </label>
                <button
                    type="submit"
                    wire:loading.attr="disabled"
                    wire:target="mintEdgeDeployHook"
                    class="rounded-lg bg-brand-ink px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-brand-ink/90 disabled:cursor-wait disabled:opacity-60"
                >
                    <span wire:loading.remove wire:target="mintEdgeDeployHook">{{ __('Create') }}</span>
                    <span wire:loading wire:target="mintEdgeDeployHook">{{ __('Creating…') }}</span>
                </button>
            </form>
        @endcan

        @if ($hooks->isEmpty())
            <div class="px-5 py-5 text-center text-sm text-brand-moss sm:px-6">{{ __('No deploy hooks yet.') }}</div>
        @else
            <ul class="divide-y divide-brand-ink/8">
                @foreach ($hooks as $hook)
                    <li class="flex flex-wrap items-center justify-between gap-3 px-5 py-3 sm:px-6" wire:key="edge-hook-{{ $hook->id }}">
                        <div class="min-w-0">
                            <p class="text-sm font-medium text-brand-ink">{{ $hook->name }}</p>
                            <p class="mt-0.5 font-mono text-xs text-brand-moss">
                                {{ $hook->token_prefix }}…
                                @if ($hook->last_used_at)
                                    · {{ $hook->last_used_at->diffForHumans() }}
                                @else
                                    · {{ __('never fired') }}
                                @endif
                            </p>
                        </div>
                        @can('update', $site)
                            <button
                                type="button"
                                wire:click="openConfirmActionModal('revokeEdgeDeployHook', @js([(string) $hook->id]), @js(__('Revoke deploy hook')), @js(__('Revoke this deploy hook? The URL will stop working immediately.')), @js(__('Revoke')), true)"
                                class="text-xs font-medium text-rose-700 hover:text-rose-900 dark:text-rose-400"
                            >
                                {{ __('Revoke') }}
                            </button>
                        @endcan
                    </li>
                @endforeach
            </ul>
        @endif
    </section>
@endif

@if (! $edgeIsPreviewChild)
    <details class="group border-b border-brand-ink/10">
        <summary class="flex cursor-pointer list-none items-center justify-between gap-3 bg-brand-sand/10 px-5 py-3.5 text-sm font-semibold text-brand-ink hover:bg-brand-sand/20 sm:px-6 [&::-webkit-details-marker]:hidden">
            <span>{{ __('Advanced') }}</span>
            <x-heroicon-m-chevron-down class="h-4 w-4 text-brand-mist transition group-open:rotate-180" />
        </summary>

        <div class="space-y-5 border-t border-brand-ink/10 px-5 py-5 sm:px-6" x-data="{ copiedHook: false, copiedSecret: false }">
            <div>
                <p class="text-2xs font-semibold uppercase tracking-[0.16em] text-brand-mist">{{ __('Manual webhook') }}</p>
                <p class="mt-1 text-xs text-brand-moss">{{ __('Use this if you register the GitHub webhook yourself.') }}</p>
                <div class="mt-3 space-y-3">
                    <div>
                        <p class="text-2xs font-semibold uppercase tracking-[0.16em] text-brand-mist">{{ __('Payload URL') }}</p>
                        <div class="mt-1.5 flex flex-wrap items-center gap-2">
                            <input type="text" readonly value="{{ $site->edgeGithubHookUrl() }}" class="block min-w-0 flex-1 rounded-lg border border-brand-ink/15 bg-brand-sand/20 px-3 py-2 font-mono text-xs text-brand-ink" onclick="this.select()" />
                            <button
                                type="button"
                                class="inline-flex items-center gap-1 rounded-lg border border-brand-ink/10 bg-white px-3 py-2 text-xs font-medium text-brand-moss hover:bg-brand-sand/40"
                                @click="navigator.clipboard.writeText(@js($site->edgeGithubHookUrl())); copiedHook = true; setTimeout(() => copiedHook = false, 2000)"
                            >
                                <span x-show="!copiedHook">{{ __('Copy') }}</span>
                                <span x-show="copiedHook" x-cloak>{{ __('Copied') }}</span>
                            </button>
                        </div>
                    </div>
                    @if ($site->webhook_secret)
                        <div>
                            <p class="text-2xs font-semibold uppercase tracking-[0.16em] text-brand-mist">{{ __('Secret') }}</p>
                            <div class="mt-1.5 flex flex-wrap items-center gap-2">
                                <input type="password" readonly value="{{ $site->webhook_secret }}" class="block min-w-0 flex-1 rounded-lg border border-brand-ink/15 bg-brand-sand/20 px-3 py-2 font-mono text-xs text-brand-ink" onclick="this.select()" />
                                <button
                                    type="button"
                                    class="inline-flex items-center gap-1 rounded-lg border border-brand-ink/10 bg-white px-3 py-2 text-xs font-medium text-brand-moss hover:bg-brand-sand/40"
                                    @click="navigator.clipboard.writeText(@js($site->webhook_secret)); copiedSecret = true; setTimeout(() => copiedSecret = false, 2000)"
                                >
                                    <span x-show="!copiedSecret">{{ __('Copy') }}</span>
                                    <span x-show="copiedSecret" x-cloak>{{ __('Copied') }}</span>
                                </button>
                            </div>
                        </div>
                    @endif
                </div>
            </div>

            @if ($site->organization)
                <div class="border-t border-brand-ink/10 pt-4">
                    <p class="text-2xs font-semibold uppercase tracking-[0.16em] text-brand-mist">{{ __('Notifications') }}</p>
                    <p class="mt-1 text-xs text-brand-moss">{{ __('Succeeded / failed Edge deploys use org notification channels.') }}</p>
                    <a
                        href="{{ route('organizations.notification-channels', $site->organization) }}"
                        wire:navigate
                        class="mt-2 inline-flex items-center gap-1 text-xs font-semibold text-brand-sage hover:underline"
                    >
                        {{ __('Manage channels') }} →
                    </a>
                </div>
            @endif
        </div>
    </details>
@endif
