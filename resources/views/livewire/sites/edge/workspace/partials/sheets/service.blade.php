@php
    $explained = collect($connections)->firstWhere('host', $explainConnectionHost);
    $explainedPeer = is_array($explained) ? ($servicePeers[$explained['target']] ?? null) : null;
    $explainedHost = is_array($explained) ? $explained['host'] : $explainConnectionHost;
@endphp
<x-sheet name="resources-service" :show="$explainConnectionHost !== ''" maxWidth="3xl" focusable>
    <x-sheet.header :eyebrow="$explainedHost" :title="__('Another app')" close-wire="$set('explainConnectionHost', '')">
        @if (is_array($explainedPeer))
            {{ __('This app calls :name. The address below belongs only to this app.', ['name' => $explainedPeer['label']]) }}
        @else
            {{ __('This app calls another app in this workspace. The address below belongs only to this app.') }}
        @endif
    </x-sheet.header>

    <x-sheet.body>
        <div class="grid gap-4" x-data="{ tab: 'how' }">
            <x-sheet.tabs>
                <button type="button" role="tab" x-on:click="tab = 'how'" :aria-selected="tab === 'how' ? 'true' : 'false'">{{ __('How it works') }}</button>
                <button type="button" role="tab" x-on:click="tab = 'implementation'" :aria-selected="tab === 'implementation' ? 'true' : 'false'">{{ __('Implementation') }}</button>
            </x-sheet.tabs>
            <div x-show="tab === 'how'">
                <ol class="list-decimal space-y-1.5 pl-4 text-xs leading-5 text-brand-ink">
                    <li>{{ __('App code sends any method to http://:host/path.', ['host' => $explainedHost]) }}</li>
                    <li>{{ __('The worker for this app receives that call. No other app can.') }}</li>
                    @if (is_array($explainedPeer))
                        <li>{{ __('The same path is sent to :name at :origin.', ['name' => $explainedPeer['label'], 'origin' => $explainedPeer['origin']]) }}</li>
                    @else
                        <li>{{ __('The same path is sent to the other app on its own address.') }}</li>
                    @endif
                    <li>{{ __('The other app’s response comes back to this app.') }}</li>
                    <li>{{ __('This starts working after the next deploy.') }}</li>
                </ol>
            </div>
            <div x-show="tab === 'implementation'" x-cloak class="grid gap-5">
                <x-sheet.section :title="__('Laravel')">
                    <p class="text-xs leading-5 text-brand-moss">{{ __('Call this address from the app. It is available after the next deploy.') }}</p>
                    <pre class="overflow-x-auto rounded-xl bg-brand-sand/40 p-3.5 text-xs text-brand-ink dark:bg-zinc-950">{{ "Http::get('http://{$explainedHost}/health');" }}</pre>
                </x-sheet.section>
                <x-sheet.section :title="__('Rails')">
                    <p class="text-xs leading-5 text-brand-moss">{{ __('Call this address from the app. It is available after the next deploy.') }}</p>
                    <pre class="overflow-x-auto rounded-xl bg-brand-sand/40 p-3.5 text-xs text-brand-ink dark:bg-zinc-950">{{ "Faraday.get('http://{$explainedHost}/health')" }}</pre>
                </x-sheet.section>
                <x-sheet.section :title="__('HTTP')">
                    <pre class="overflow-x-auto rounded-xl bg-brand-sand/40 p-3.5 text-xs text-brand-ink dark:bg-zinc-950">{{ "curl http://{$explainedHost}/health" }}</pre>
                </x-sheet.section>
            </div>
        </div>

        <x-sheet.section :title="__('Demo')" class="border-t border-brand-ink/10 pt-5 dark:border-brand-mist/15">
            <p class="text-xs leading-5 text-brand-moss">{{ __('Call one path on the other app from here. This does not call this app.') }}</p>
            <x-sheet.field :label="__('Path')" for="resources-service-path">
                <div class="flex gap-2">
                    <input id="resources-service-path" type="text" wire:model="serviceDemoPath" spellcheck="false" class="dply-input mt-0 min-w-0 flex-1 font-mono" />
                    <x-sheet.button variant="primary" wire:click="runServiceDemo" wire:loading.attr="disabled" wire:target="runServiceDemo">{{ __('Try') }}</x-sheet.button>
                </div>
            </x-sheet.field>
            <p wire:loading wire:target="runServiceDemo" class="text-xs text-brand-moss">{{ __('Calling the other app…') }}</p>
            @if ($serviceDemoLog !== [])
                <ol class="space-y-1 rounded-xl bg-brand-sand/40 p-3.5 font-mono text-xs text-brand-ink dark:bg-zinc-950">
                    @foreach ($serviceDemoLog as $line)
                        <li>{{ $line }}</li>
                    @endforeach
                </ol>
            @endif
            @if ($serviceDemoPreview !== '')
                <pre class="max-h-40 overflow-auto rounded-xl bg-brand-sand/40 p-3.5 text-xs text-brand-ink dark:bg-zinc-950">{{ $serviceDemoPreview }}</pre>
            @endif
        </x-sheet.section>
    </x-sheet.body>
</x-sheet>
