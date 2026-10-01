<div class="dply-page-shell space-y-4 pt-6">
    <x-breadcrumb-trail :items="[
        ['label' => __('Dashboard'), 'href' => route('dashboard'), 'icon' => 'home'],
        ['label' => __('Projects'), 'href' => route('dashboard'), 'icon' => 'globe-alt'],
        ['label' => __('Messages'), 'icon' => 'paper-airplane'],
    ]" />

    <x-profile-shell
        :title="__('Messages')"
        :description="__('An HTTP message queue for your apps: publish to a URL and dply delivers it, signed, with delays, retries, callbacks, a dead-letter queue and cron schedules.')"
        icon="heroicon-o-paper-airplane"
    >
        <x-input-error :messages="$errors->get('messages')" class="px-4 pt-2" />

        <div class="space-y-4 p-4">
            @unless ($configured)
                <div class="rounded-xl border border-amber-500/30 bg-amber-500/5 p-3 text-sm text-brand-ink">{{ __('Messages is not deployed on this dply yet (DPLY_MESSAGES_URL and DPLY_MESSAGES_OPERATOR_TOKEN).') }}</div>
            @elseif ($account === null)
                <div class="rounded-xl border border-brand-ink/10 bg-white p-4 dark:bg-zinc-900">
                    <p class="text-sm text-brand-moss">{{ __('Turn Messages on for this organization to get signing keys and make tokens. Billed per 100,000 messages published, at :price.', ['price' => $price]) }}</p>
                    <x-primary-button class="mt-3" wire:click="enable" wire:loading.attr="disabled">{{ __('Turn on Messages') }}</x-primary-button>
                </div>
            @else
                {{-- Env for publishing and verifying: tokens are shown once, keys on demand. --}}
                <div class="rounded-xl border border-brand-ink/10 bg-white p-4 dark:bg-zinc-900" x-data="{ keys: null, async show() { this.keys = await $wire.signingKeys() } }">
                    <p class="text-xs font-semibold uppercase tracking-[0.14em] text-brand-moss">{{ __('Environment') }}</p>
                    <pre class="mt-2 overflow-x-auto rounded-lg bg-brand-sand/40 p-3 font-mono text-xs text-brand-ink dark:bg-zinc-950"><span>MESSAGES_URL={{ $url }}</span>
<span>MESSAGES_TOKEN=</span><span class="text-brand-mist">{{ __('(a token below)') }}</span>
<span>MESSAGES_SIGNING_KEY=</span><span x-text="keys ? keys.current : '••••'"></span>
<span>MESSAGES_NEXT_SIGNING_KEY=</span><span x-text="keys ? keys.next : '••••'"></span></pre>
                    <div class="mt-2 flex flex-wrap items-center gap-2">
                        <x-secondary-button x-show="! keys" x-on:click="show()">{{ __('Show signing keys') }}</x-secondary-button>
                        <x-secondary-button wire:click="rotateKeys" wire:confirm="{{ __('Rotate the keys? The next key becomes current. Receivers that check both keep working once they have the new pair.') }}">{{ __('Rotate keys') }}</x-secondary-button>
                    </div>
                    <p class="mt-2 text-xs text-brand-mist">{{ __('Receivers verify each delivery with the current key, then the next one, so rotating needs no downtime: update the two keys where you verify, then rotate.') }}</p>
                </div>

                <div class="rounded-xl border border-brand-ink/10 bg-white p-4 dark:bg-zinc-900"
                    x-data="{ made: null, error: '', async make() { this.error = ''; const r = await $wire.createToken(); if (r.error) { this.error = r.error; return } this.made = r; $wire.set('label', '') } }">
                    <p class="text-xs font-semibold uppercase tracking-[0.14em] text-brand-moss">{{ __('Tokens') }}</p>
                    <div class="mt-2 flex flex-wrap items-center gap-2">
                        <x-text-input wire:model="label" type="text" maxlength="60" class="min-w-0 flex-1 text-sm" placeholder="{{ __('Token name, like Production app') }}" aria-label="{{ __('Token name') }}" />
                        <x-primary-button type="button" x-on:click="make()">{{ __('Create token') }}</x-primary-button>
                    </div>
                    <p x-show="error" x-cloak x-text="error" class="mt-1 text-xs text-rose-700 dark:text-rose-300"></p>
                    <template x-if="made">
                        <div class="mt-3 rounded-lg border border-amber-500/30 bg-amber-500/5 p-3">
                            <p class="text-xs font-semibold text-brand-ink">{{ __('Copy the token now. It is not shown again.') }}</p>
                            <div class="mt-1 flex items-center gap-2 font-mono text-xs">
                                <span class="min-w-0 break-all text-brand-ink" x-text="made.secret"></span>
                                <button type="button" x-on:click="navigator.clipboard.writeText(made.secret)" class="shrink-0 font-sans font-semibold text-brand-sage hover:underline">{{ __('Copy') }}</button>
                            </div>
                            <button type="button" x-on:click="made = null; $wire.$refresh()" class="mt-2 text-xs font-semibold text-brand-sage hover:underline">{{ __('Done, I copied it') }}</button>
                        </div>
                    </template>
                    @if ($tokens->isNotEmpty())
                        <ul class="mt-3 divide-y divide-brand-ink/10 rounded-lg border border-brand-ink/10 text-sm dark:divide-brand-mist/15 dark:border-brand-mist/15">
                            @foreach ($tokens as $token)
                                <li class="flex items-center justify-between gap-3 px-3 py-2" wire:key="messages-token-{{ $token->id }}">
                                    <span><span class="font-semibold text-brand-ink">{{ $token->label }}</span> <span class="font-mono text-xs text-brand-moss">…{{ $token->last4 }} · {{ $token->created_at?->diffForHumans() }}</span></span>
                                    <button type="button" wire:click="revokeToken('{{ $token->id }}')" wire:confirm="{{ __('Revoke :name? Apps using it stop publishing.', ['name' => $token->label]) }}" class="text-xs font-semibold text-red-600">{{ __('Revoke') }}</button>
                                </li>
                            @endforeach
                        </ul>
                    @endif
                </div>

                <div class="rounded-xl border border-brand-ink/10 bg-white p-4 dark:bg-zinc-900">
                    <p class="text-xs font-semibold uppercase tracking-[0.14em] text-brand-moss">{{ __('Use it') }}</p>
                    <pre class="mt-2 overflow-x-auto rounded-lg bg-brand-sand/40 p-3 font-mono text-xs text-brand-ink dark:bg-zinc-950">{{ "curl -X POST \"\$MESSAGES_URL/v2/publish/https://example.com/api/job\" \\\n  -H \"Authorization: Bearer \$MESSAGES_TOKEN\" \\\n  -H \"Content-Type: application/json\" \\\n  -H \"Dply-Delay: 10m\" -H \"Dply-Retries: 3\" \\\n  -d '{\"id\":42}'\n\n# Every day at 9:00 UTC\ncurl -X POST \"\$MESSAGES_URL/v2/schedules/https://example.com/api/report\" \\\n  -H \"Authorization: Bearer \$MESSAGES_TOKEN\" \\\n  -H \"Dply-Cron: 0 9 * * *\"" }}</pre>
                    <p class="mt-2 text-xs text-brand-mist">{{ __('Each delivery carries a Dply-Signature header: a JWT (HS256) signed with the current signing key, whose sub is the URL and whose body claim is the base64url SHA-256 of the body. Check it with the current key, then the next one. Other headers: Dply-Callback, Dply-Failure-Callback, Dply-Method, Dply-Timeout, Dply-Deduplication-Id, Dply-Forward-*.') }}</p>
                    <p class="mt-2 text-xs text-brand-mist">{{ __('Delivery is at least once, so make receivers safe to run twice. Cron is 5 fields, in UTC. Messages can wait up to 7 days and retry up to 5 times.') }}</p>
                </div>

                <div class="rounded-xl border border-brand-ink/10 bg-white p-4 text-sm dark:bg-zinc-900">
                    <p class="text-xs font-semibold uppercase tracking-[0.14em] text-brand-moss">{{ __('This month') }}</p>
                    <p class="mt-2 text-brand-ink">{{ __(':n messages · $:cost', ['n' => number_format($usage['messages']), 'cost' => number_format($usage['cents'] / 100, 2)]) }}</p>
                    <p class="text-xs text-brand-mist">{{ __(':price per 100,000 published messages (schedule runs and callbacks count), from your plan’s included usage credit first. Collected hourly.', ['price' => $price]) }}</p>
                </div>
            @endunless
        </div>
    </x-profile-shell>
</div>
