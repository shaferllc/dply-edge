@php
    $messagesConnection = collect($connections)->firstWhere('host', $messagesHost);
    $messagesAsleep = is_array($messagesConnection) && $messagesConnection['asleep'];
    $messagesToken = $messagesHost !== '' ? \App\Modules\Edge\Services\Messages\EdgeMessages::appToken($site) : null;
    $messagesPre = 'overflow-x-auto rounded-xl bg-brand-sand/40 p-3.5 font-mono text-xs text-brand-ink dark:bg-zinc-950';
    $messagesPrice = \App\Modules\Billing\Support\UsagePrice::dollars(\App\Modules\Billing\Support\UsagePrice::rate('messages_millicents_per_hundred_thousand'));
@endphp
<x-sheet name="resources-messages" :show="$messagesHost !== ''" maxWidth="3xl" focusable>
    <x-sheet.header :eyebrow="__('Background work')" :title="__('Messages')" close-wire="$set('messagesHost', '')">
        {{ __('Publish an HTTP message and dply delivers it to a URL, signed: later, on a cron schedule, with retries and callbacks.') }}
    </x-sheet.header>

    <x-sheet.body>
        @if ($messagesAsleep)
            <x-sheet.note>{{ __('Asleep. The next deploy leaves the MESSAGES_* values out, so the app cannot publish. Schedules it made keep running.') }}</x-sheet.note>
        @endif

        <x-sheet.section :title="__('Environment')">
            <div x-data="{ token: null, keys: null, async show() { this.token = await $wire.messagesToken(); this.keys = await $wire.messagesSigningKeys() } }" class="grid gap-2">
                <pre class="{{ $messagesPre }}"><span>MESSAGES_URL={{ \App\Modules\Edge\Services\Messages\EdgeMessages::url() }}</span>
<span>MESSAGES_TOKEN=</span><span x-text="token ?? '…{{ $messagesToken?->last4 }}'"></span>
<span>MESSAGES_SIGNING_KEY=</span><span x-text="keys ? keys.current : '••••'"></span>
<span>MESSAGES_NEXT_SIGNING_KEY=</span><span x-text="keys ? keys.next : '••••'"></span></pre>
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <p class="min-w-0 flex-1 text-xs text-brand-moss">{{ __('Set on the app by the next deploy. The token is this app’s own; the signing keys are shared by the organization.') }}</p>
                    <x-sheet.button x-show="! token" x-on:click="show()">{{ __('Show') }}</x-sheet.button>
                </div>
            </div>
        </x-sheet.section>

        <x-sheet.section :title="__('Use it')">
            <pre class="{{ $messagesPre }}">{{ "curl -X POST \"\$MESSAGES_URL/v2/publish/https://example.com/api/job\" \\\n  -H \"Authorization: Bearer \$MESSAGES_TOKEN\" \\\n  -H \"Content-Type: application/json\" \\\n  -H \"Dply-Delay: 10m\" -H \"Dply-Retries: 3\" \\\n  -d '{\"id\":42}'\n\n# Every day at 9:00 UTC\ncurl -X POST \"\$MESSAGES_URL/v2/schedules/https://example.com/api/report\" \\\n  -H \"Authorization: Bearer \$MESSAGES_TOKEN\" \\\n  -H \"Dply-Cron: 0 9 * * *\"" }}</pre>
            <p class="mt-2 text-xs text-brand-moss">{{ __('Each delivery carries a Dply-Signature header: a JWT (HS256) signed with the current signing key, whose sub is the URL and whose body claim is the base64url SHA-256 of the body. Check it with the current key, then the next one. Other headers: Dply-Callback, Dply-Failure-Callback, Dply-Method, Dply-Timeout, Dply-Deduplication-Id, Dply-Forward-*.') }}</p>
            <p class="mt-2 text-xs text-brand-moss">{{ __('Delivery is at least once, so make receivers safe to run twice. Cron is 5 fields, in UTC. Messages wait up to 7 days and retry up to 5 times. :price per 100,000 messages published, from the plan’s included usage credit first.', ['price' => $messagesPrice]) }}</p>
        </x-sheet.section>

        @if (is_array($messagesConnection))
            <x-sheet.section :title="__('Settings')">
                <div class="grid gap-3">
                    <div class="flex flex-wrap items-center justify-between gap-3">
                        <p class="min-w-0 flex-1 text-xs text-brand-moss">{{ __('Signing keys, other tokens and this month’s usage are on the organization’s Messages page. Rotating keys needs a redeploy here.') }}</p>
                        <x-sheet.button href="{{ route('edge.messages') }}">{{ __('Messages page') }}</x-sheet.button>
                    </div>
                    <div class="flex flex-wrap items-center justify-between gap-3">
                        <p class="min-w-0 flex-1 text-xs text-brand-moss">{{ $messagesAsleep ? __('Wake it, then deploy, to give the app its MESSAGES_* values again.') : __('Takes the MESSAGES_* values off the app on the next deploy. The token keeps working until you detach.') }}</p>
                        <x-sheet.button wire:click="sleepConnection({{ \Illuminate\Support\Js::from($messagesHost) }}, {{ $messagesAsleep ? 'false' : 'true' }})" wire:island="resources-messages">{{ $messagesAsleep ? __('Wake') : __('Sleep') }}</x-sheet.button>
                    </div>
                </div>
            </x-sheet.section>

            <x-sheet.danger :title="__('Remove')">
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <p class="min-w-0 flex-1 text-xs text-brand-moss">{{ __('Detach: revokes this app’s token now, so it stops publishing. Messages already queued are still delivered.') }}</p>
                    <x-sheet.button variant="danger" wire:click="removeConnection({{ \Illuminate\Support\Js::from($messagesHost) }})" wire:confirm="{{ __('Detach Messages? The app stops publishing right away.') }}" x-on:click="$dispatch('close-modal', 'resources-messages')">{{ __('Detach') }}</x-sheet.button>
                </div>
            </x-sheet.danger>
        @endif
    </x-sheet.body>
</x-sheet>
