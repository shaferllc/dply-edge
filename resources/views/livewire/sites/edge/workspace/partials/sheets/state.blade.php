@php
    $stateConnection = collect($connections)->firstWhere('host', $resourceHost);
    $stateConnection = is_array($stateConnection) && $stateConnection['kind'] === 'durable_object' ? $stateConnection : null;
@endphp
<x-sheet name="resources-durable-object" maxWidth="3xl" focusable>
    @if ($stateConnection)
        @php
            $stateHost = $stateConnection['host'];
            $stateName = $stateConnection['name'];
        @endphp
        <x-sheet.header :eyebrow="$isWorker ? 'env.'.$stateName : $stateHost" :title="__('State')" close-wire="$set('resourceHost', '')">
            {{ __('Small values that stay put between requests and deploys: a counter, a lock, a flag. Every call goes to one place in order, so two requests never both win.') }}
        </x-sheet.header>

        <x-sheet.body>
            <div class="grid gap-4" x-data="{ tab: 'how' }">
                <x-sheet.tabs>
                    <button type="button" role="tab" x-on:click="tab = 'how'" :aria-selected="tab === 'how' ? 'true' : 'false'">{{ __('How it works') }}</button>
                    <button type="button" role="tab" x-on:click="tab = 'connect'" :aria-selected="tab === 'connect' ? 'true' : 'false'">{{ __('Connect') }}</button>
                    <button type="button" role="tab" x-on:click="tab = 'try'" :aria-selected="tab === 'try' ? 'true' : 'false'">{{ __('Try it') }}</button>
                </x-sheet.tabs>

                <div x-show="tab === 'how'" class="grid gap-3">
                    <ol class="list-decimal space-y-1.5 pl-4 text-xs leading-5 text-brand-ink">
                        @if ($isWorker)
                            <li>{{ __('Your Worker gets env.:name. Call env.:name.fetch() with a path; the host in the URL does not matter.', ['name' => $stateName]) }}</li>
                        @else
                            <li>{{ __('The app calls http://:host/. The address belongs only to this app.', ['host' => $stateHost]) }}</li>
                        @endif
                        <li>{{ __('GET /key reads a value. A missing key is a 404. GET / lists up to 100 keys.') }}</li>
                        <li>{{ __('PUT /key stores the request body as text. DELETE /key removes it.') }}</li>
                        <li>{{ __('POST /incr/key adds one and returns the new number. A missing key starts at 0.') }}</li>
                        <li>{{ __('Every State on this app shares one set of keys. The data outlives deploys.') }}</li>
                        <li>{{ __('This starts working after the next deploy.') }}</li>
                    </ol>
                </div>

                <div x-show="tab === 'connect'" x-cloak class="grid gap-5">
                    @if ($isWorker)
                        <x-sheet.section :title="__('Worker')">
                            <pre class="overflow-x-auto rounded-xl bg-brand-sand/40 p-3.5 text-xs text-brand-ink dark:bg-zinc-950">{{ "await env.{$stateName}.fetch('https://state/greeting', { method: 'PUT', body: 'hello' });\nconst value = await (await env.{$stateName}.fetch('https://state/greeting')).text();\nconst visits = Number(await (await env.{$stateName}.fetch('https://state/incr/visits', { method: 'POST' })).text());" }}</pre>
                        </x-sheet.section>
                    @else
                        @if ($site->isLaravelFrameworkDetected())
                            <x-sheet.section :title="__('Laravel')">
                                <pre class="overflow-x-auto rounded-xl bg-brand-sand/40 p-3.5 text-xs text-brand-ink dark:bg-zinc-950">{{ "Http::withBody('hello', 'text/plain')->put('http://{$stateHost}/greeting');\n\$value = Http::get('http://{$stateHost}/greeting')->body();\n\$visits = (int) Http::post('http://{$stateHost}/incr/visits')->body();" }}</pre>
                            </x-sheet.section>
                        @endif
                        <x-sheet.section :title="__('Node')">
                            <pre class="overflow-x-auto rounded-xl bg-brand-sand/40 p-3.5 text-xs text-brand-ink dark:bg-zinc-950">{{ "await fetch('http://{$stateHost}/greeting', { method: 'PUT', body: 'hello' });\nconst value = await (await fetch('http://{$stateHost}/greeting')).text();\nconst visits = Number(await (await fetch('http://{$stateHost}/incr/visits', { method: 'POST' })).text());" }}</pre>
                        </x-sheet.section>
                        <x-sheet.section :title="__('HTTP')">
                            <pre class="overflow-x-auto rounded-xl bg-brand-sand/40 p-3.5 text-xs text-brand-ink dark:bg-zinc-950">{{ "curl -X PUT http://{$stateHost}/greeting -d 'hello'\ncurl http://{$stateHost}/greeting\ncurl -X POST http://{$stateHost}/incr/visits\ncurl http://{$stateHost}/" }}</pre>
                        </x-sheet.section>
                    @endif
                </div>

                <div x-show="tab === 'try'" x-cloak class="grid gap-3">
                    <x-sheet.note>{{ __('State lives inside the running app, and only the app can reach it. This page cannot read or write it, so run one of these from the app instead.') }}</x-sheet.note>
                    <pre class="overflow-x-auto rounded-xl bg-brand-sand/40 p-3.5 text-xs text-brand-ink dark:bg-zinc-950">{{ $isWorker
                        ? "// In a request handler: count visits.\nconst n = await (await env.{$stateName}.fetch('https://state/incr/visits', { method: 'POST' })).text();\nreturn new Response('Visit #' + n);"
                        : "# From inside the app (a route, a job, or a shell in the container):\ncurl -X POST http://{$stateHost}/incr/visits\ncurl http://{$stateHost}/" }}</pre>
                </div>

                <x-sheet.danger :title="__('Delete')">
                    <p class="text-xs leading-5 text-brand-moss">{{ __('Removes :name from this app on the next deploy. The keys are not wiped.', ['name' => $stateName]) }}</p>
                    <div><x-sheet.button variant="danger" wire:click="askDeleteConnection({{ \Illuminate\Support\Js::from($stateHost) }})" wire:island="resources-delete-connection" x-on:click="$dispatch('open-modal', 'resources-delete-connection')">{{ __('Delete State') }}</x-sheet.button></div>
                </x-sheet.danger>
            </div>
        </x-sheet.body>
    @endif
</x-sheet>
