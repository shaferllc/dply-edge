<div x-on:close-modal.window="@js($modalName) === $event.detail ? $wire.hidePatEntry() : null">
<x-modal :name="$modalName" :show="false" maxWidth="md" focusable>
    <div class="border-b border-brand-ink/10 px-6 py-5">
        <p class="text-xs font-semibold uppercase tracking-[0.18em] text-brand-sage">{{ __('Source control') }}</p>
        <h2 class="mt-2 text-xl font-semibold text-brand-ink">{{ __('Connect a repository provider') }}</h2>
        <p class="mt-2 text-sm leading-6 text-brand-moss">
            @if ($showPatForm)
                {{ __('Paste a personal access token to browse private repositories without OAuth. Tokens are validated against the provider before they are saved.') }}
            @else
                {{ __('Link GitHub, GitLab, or Bitbucket to browse and deploy private repositories — via OAuth or a personal access token.') }}
            @endif
        </p>
    </div>

    <div class="space-y-4 px-6 py-6">
        @if ($showPatForm)
            <div class="space-y-4">
                <div>
                    <span class="block text-xs font-medium text-brand-moss mb-2">{{ __('Provider') }}</span>
                    <div class="flex flex-wrap gap-2" role="radiogroup" aria-label="{{ __('Git provider') }}">
                        @foreach ($patProviders as $provider)
                            <button
                                type="button"
                                role="radio"
                                aria-checked="{{ $addingPatProvider === $provider['id'] ? 'true' : 'false' }}"
                                wire:click="startAddPat('{{ $provider['id'] }}')"
                                @class([
                                    'inline-flex items-center gap-2 rounded-xl border px-3 py-2 text-sm font-semibold transition',
                                    'border-brand-sage/40 bg-brand-sage/10 text-brand-ink' => $addingPatProvider === $provider['id'],
                                    'border-brand-ink/10 bg-white text-brand-moss hover:border-brand-sage/30 hover:text-brand-ink' => $addingPatProvider !== $provider['id'],
                                ])
                            >
                                <x-oauth-provider-icon :provider="$provider['id']" size="h-4 w-4" />
                                {{ $provider['name'] }}
                            </button>
                        @endforeach
                    </div>
                </div>

                @if ($addingPatProvider !== null)
                    <p class="text-xs leading-relaxed text-brand-moss">
                        @if ($addingPatProvider === 'github')
                            {{ __('Classic PATs need repo and admin:repo_hook scopes. Fine-grained tokens need Contents (Read), Metadata (Read), and Webhooks (Read & Write) for the target repositories.') }}
                        @elseif ($addingPatProvider === 'gitlab')
                            {{ __('Token needs the api scope. Group-scoped tokens cover every project under that group.') }}
                        @else
                            {{ __('App password or workspace access token with repository:read and webhook permissions.') }}
                        @endif
                    </p>

                    <div class="grid gap-4 sm:grid-cols-2">
                        <div>
                            <label class="block text-xs font-medium text-brand-moss mb-1" for="connect-pat-label">{{ __('Label (optional)') }}</label>
                            <x-text-input id="connect-pat-label" wire:model="patLabel" class="block w-full text-sm" placeholder="{{ __('e.g. machine user, work account') }}" />
                            @error('patLabel') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-brand-moss mb-1" for="connect-pat-token">{{ __('Token') }}</label>
                            <x-text-input id="connect-pat-token" type="password" wire:model="patToken" class="block w-full text-sm font-mono" autocomplete="off" />
                            @error('patToken') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                        </div>
                    </div>

                    @if ($addingPatProvider !== 'bitbucket')
                        <div>
                            <label class="block text-xs font-medium text-brand-moss mb-1" for="connect-pat-base">
                                {{ $addingPatProvider === 'github'
                                    ? __('API base URL (optional, for GitHub Enterprise)')
                                    : __('API base URL (optional, for self-hosted GitLab)') }}
                            </label>
                            <x-text-input
                                id="connect-pat-base"
                                wire:model="patApiBaseUrl"
                                class="block w-full text-sm font-mono"
                                placeholder="{{ $addingPatProvider === 'github' ? 'https://github.example.com/api/v3' : 'https://gitlab.example.com' }}"
                            />
                            @error('patApiBaseUrl') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                        </div>
                    @endif
                @endif
            </div>
        @else
            <div class="space-y-2">
                @forelse ($oauthProviders as $provider)
                    <a href="{{ route('oauth.redirect', ['provider' => $provider['id']]) }}"
                       x-on:click.prevent="window.location.href = @js(route('oauth.redirect', ['provider' => $provider['id']])) + '?return_to=' + encodeURIComponent(window.location.pathname + window.location.search)"
                       class="flex items-center gap-3 rounded-xl border border-brand-ink/10 bg-white px-4 py-3 transition hover:border-brand-sage/40 hover:bg-brand-sand/30">
                        <x-oauth-provider-icon :provider="$provider['id']" size="h-6 w-6" />
                        <span class="text-sm font-semibold text-brand-ink">{{ __('Connect :name', ['name' => $provider['name']]) }}</span>
                        <x-heroicon-o-arrow-right class="ml-auto h-4 w-4 text-brand-moss" />
                    </a>
                @empty
                    <div class="rounded-xl border border-brand-gold/30 bg-brand-gold/10 px-4 py-3 text-sm text-brand-ink">
                        {{ __('No Git OAuth providers are enabled. Paste a personal access token below instead.') }}
                    </div>
                @endforelse
            </div>

            <div class="relative py-1">
                <div class="absolute inset-0 flex items-center" aria-hidden="true">
                    <div class="w-full border-t border-brand-ink/10"></div>
                </div>
                <div class="relative flex justify-center">
                    <span class="bg-white px-2 text-xs text-brand-moss">{{ __('or') }}</span>
                </div>
            </div>

            <button
                type="button"
                wire:click="showPatEntry"
                class="flex w-full items-center gap-3 rounded-xl border border-brand-ink/10 bg-white px-4 py-3 text-left transition hover:border-brand-sage/40 hover:bg-brand-sand/30"
            >
                <span class="flex h-6 w-6 shrink-0 items-center justify-center rounded-lg bg-brand-sage/10 text-brand-forest" aria-hidden="true">
                    <x-heroicon-o-key class="h-4 w-4" />
                </span>
                <span class="text-sm font-semibold text-brand-ink">{{ __('Paste a personal access token') }}</span>
                <x-heroicon-o-arrow-right class="ml-auto h-4 w-4 text-brand-moss" />
            </button>
        @endif

        <p class="text-xs leading-5 text-brand-moss">
            {{ __('Already connected accounts are managed under Settings → Source control.') }}
            @unless ($showPatForm)
                {{ __('Connecting from here returns you to this page once authorized.') }}
            @endunless
        </p>
    </div>

    <div class="flex justify-end gap-2 border-t border-brand-ink/10 px-6 py-4">
        @if ($showPatForm)
            <button type="button" wire:click="hidePatEntry"
                    class="inline-flex items-center rounded-xl border-2 border-brand-ink/15 bg-white px-4 py-2 text-sm font-semibold text-brand-ink hover:border-brand-sage/40">
                {{ __('Back') }}
            </button>
            <button type="button" wire:click="savePat"
                    class="inline-flex items-center rounded-xl border border-transparent bg-brand-ink px-4 py-2 text-sm font-semibold text-brand-cream hover:bg-brand-forest">
                {{ __('Validate and save') }}
            </button>
        @else
            <button type="button" x-on:click="$dispatch('close-modal', @js($modalName))"
                    class="inline-flex items-center rounded-xl border-2 border-brand-ink/15 bg-white px-4 py-2 text-sm font-semibold text-brand-ink hover:border-brand-sage/40">
                {{ __('Close') }}
            </button>
        @endif
    </div>
</x-modal>
</div>
