@if ($showBrowser)
    <x-sheet name="resources-browser" :show="$panel === 'browser'" maxWidth="3xl" focusable>
        <x-sheet.header :title="__('Browser')" close-wire="$set('panel', '')">
            {{ __('This app gets its own browser at an address starting with dply, so it does not clash with a name the app already uses. Another app gets a different address.') }}
            <x-slot:actions>
                <div class="mt-0.5 flex items-center gap-2">
                    @if ($browserOn)
                        <span class="rounded-full bg-emerald-500/10 px-2 py-0.5 text-2xs font-semibold text-emerald-800 dark:text-emerald-300">{{ __('On') }}</span>
                        @if (! $browserDeployed && auth()->user()?->can('deploy', $site))
                            <x-sheet.button variant="primary" wire:click="redeployEdge">{{ __('Deploy') }}</x-sheet.button>
                        @endif
                        @can('update', $site)
                            <x-sheet.button variant="danger" wire:click="askRemoveBrowser" x-on:click="$dispatch('open-modal', 'resources-remove-browser')">{{ __('Remove') }}</x-sheet.button>
                        @endcan
                    @elseif (! $paidFeatures)
                        <span class="text-2xs text-brand-mist">{{ \App\Modules\Edge\Support\EdgeContainerConnections::paidOnlyReason() }}</span>
                    @else
                        <x-sheet.button variant="primary" wire:click="enableBrowser">{{ __('Turn on') }}</x-sheet.button>
                    @endif
                </div>
            </x-slot:actions>
        </x-sheet.header>

        <x-sheet.body>
            @if ($browserOn)
                @include('livewire.sites.edge.workspace.partials.sheets.metered-usage', ['service' => 'browser'])
            @endif
            @if ($browserOn && $isWorker)
                <x-sheet.note>{{ __('Your code reads it as env.BROWSER. Use it with @cloudflare/puppeteer: puppeteer.launch(env.BROWSER). It is added on the next deploy.') }}</x-sheet.note>
            @elseif ($browserOn)
                <div class="grid gap-4" x-data="{ tab: 'how' }">
                    <x-sheet.tabs>
                        <button type="button" role="tab" x-on:click="tab = 'how'" :aria-selected="tab === 'how' ? 'true' : 'false'">{{ __('How it works') }}</button>
                        <button type="button" role="tab" x-on:click="tab = 'implementation'" :aria-selected="tab === 'implementation' ? 'true' : 'false'">{{ __('Implementation') }}</button>
                    </x-sheet.tabs>
                    <div x-show="tab === 'how'" class="grid gap-3">
                        <p class="text-xs leading-5 text-brand-moss">{{ __('Post {"url":"https://example.com"}. /content returns the page, /screenshot returns a PNG, /pdf returns a PDF.') }}</p>
                        <ul class="space-y-1 rounded-xl bg-brand-sand/40 p-3.5 font-mono text-xs text-brand-ink dark:bg-zinc-950">
                            <li>http://{{ $browserHost }}/content</li>
                            <li>http://{{ $browserHost }}/screenshot</li>
                            <li>http://{{ $browserHost }}/pdf</li>
                        </ul>
                        @unless ($browserDeployed)
                            <x-sheet.note tone="warn">{{ __('Deploy this app before those calls work. Only this app can use this address.') }}</x-sheet.note>
                        @endunless
                    </div>
                    <div x-show="tab === 'implementation'" x-cloak class="grid gap-5">
                        <x-sheet.section :title="__('Laravel')">
                            <p class="text-xs leading-5 text-brand-moss">{{ __('Call this address from the app. It is available after the next deploy.') }}</p>
                            <pre class="overflow-x-auto rounded-xl bg-brand-sand/40 p-3.5 text-xs text-brand-ink dark:bg-zinc-950">{{ "Http::post('http://{$browserHost}/screenshot', [\n    'url' => 'https://example.com',\n]);" }}</pre>
                        </x-sheet.section>
                        <x-sheet.section :title="__('Rails')">
                            <p class="text-xs leading-5 text-brand-moss">{{ __('Call this address from the app. It is available after the next deploy.') }}</p>
                            <pre class="overflow-x-auto rounded-xl bg-brand-sand/40 p-3.5 text-xs text-brand-ink dark:bg-zinc-950">{{ "Faraday.post('http://{$browserHost}/screenshot', { url: 'https://example.com' }.to_json, 'Content-Type' => 'application/json')" }}</pre>
                        </x-sheet.section>
                        <x-sheet.section :title="__('HTTP')">
                            <pre class="overflow-x-auto rounded-xl bg-brand-sand/40 p-3.5 text-xs text-brand-ink dark:bg-zinc-950">{{ "curl -X POST http://{$browserHost}/screenshot \\\n  -H 'content-type: application/json' \\\n  -d '{\"url\":\"https://example.com\"}'" }}</pre>
                        </x-sheet.section>
                    </div>
                </div>
            @endif

            <x-sheet.section :title="__('Demo')" class="border-t border-brand-ink/10 pt-5 dark:border-brand-mist/15">
                <p class="text-xs leading-5 text-brand-moss">{{ __('Open a public page here and see the same three results. This does not change the app.') }}</p>
                <x-sheet.field :label="__('Page address')" for="resources-browser-url">
                    <input id="resources-browser-url" type="url" wire:model="browserDemoUrl" class="dply-input mt-0" />
                </x-sheet.field>
                <div class="flex flex-wrap gap-2">
                    <x-sheet.button wire:click="runBrowserDemo('content')" wire:loading.attr="disabled" wire:target="runBrowserDemo">{{ __('Show page') }}</x-sheet.button>
                    <x-sheet.button wire:click="runBrowserDemo('screenshot')" wire:loading.attr="disabled" wire:target="runBrowserDemo">{{ __('Show picture') }}</x-sheet.button>
                    <x-sheet.button wire:click="runBrowserDemo('pdf')" wire:loading.attr="disabled" wire:target="runBrowserDemo">{{ __('Show PDF') }}</x-sheet.button>
                </div>
                <p wire:loading wire:target="runBrowserDemo" class="text-xs text-brand-moss">{{ __('Opening the page…') }}</p>
                @if ($browserDemoLog !== [])
                    <ol class="space-y-1 rounded-xl bg-brand-sand/40 p-3.5 font-mono text-xs text-brand-ink dark:bg-zinc-950">
                        @foreach ($browserDemoLog as $line)
                            <li>{{ $line }}</li>
                        @endforeach
                    </ol>
                @elseif ($browserOn)
                    <ol class="space-y-1 rounded-xl bg-brand-sand/40 p-3.5 font-mono text-xs text-brand-ink dark:bg-zinc-950">
                        <li>{{ __('App code posts {"url":"https://example.com"} to http://:host/screenshot.', ['host' => $browserHost]) }}</li>
                        <li>{{ __('The worker for this app receives that call. No other app can.') }}</li>
                        <li>{{ __('The worker opens the page and returns a PNG, the HTML, or a PDF to the app.') }}</li>
                        <li>{{ __('This demo does the same open from here. It does not call the app.') }}</li>
                    </ol>
                @endif
                <x-input-error :messages="$errors->get('browserDemo')" />
                @if ($browserDemoKind === 'content' && $browserDemoPreview !== '')
                    <pre class="max-h-48 overflow-auto rounded-xl bg-brand-sand/40 p-3.5 text-xs text-brand-ink dark:bg-zinc-950">{{ $browserDemoPreview }}</pre>
                @elseif ($browserDemoKind === 'screenshot' && $browserDemoPreview !== '')
                    <img src="data:image/png;base64,{{ $browserDemoPreview }}" alt="{{ __('Picture of the page') }}" class="max-h-64 max-w-full rounded-xl border border-brand-ink/10 dark:border-brand-mist/15" />
                @elseif ($browserDemoKind === 'pdf' && $browserDemoPreview !== '')
                    <div><x-sheet.button href="data:application/pdf;base64,{{ $browserDemoPreview }}" download="page.pdf">{{ __('Download PDF') }}</x-sheet.button></div>
                @endif
            </x-sheet.section>
        </x-sheet.body>
    </x-sheet>
@endif
