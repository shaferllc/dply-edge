@php
    $aiConnection = collect($connections)->firstWhere('host', $resourceHost);
    $aiConnection = is_array($aiConnection) && $aiConnection['kind'] === 'ai' ? $aiConnection : null;
@endphp
<x-sheet name="resources-ai" maxWidth="3xl" focusable>
    @if ($aiConnection)
        @php
            $aiHost = $aiConnection['host'];
            $aiName = $aiConnection['name'];
            $aiModels = \App\Livewire\Sites\Edge\Workspace\Resources::AI_MODELS;
        @endphp
        <x-sheet.header :eyebrow="$isWorker ? 'env.'.$aiName : $aiHost" :title="__('AI')" close-wire="$set('resourceHost', '')">
            {{ __('Run text and embedding models from this app. There is nothing to create: name a model on each call.') }}
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
                            <li>{{ __('Your Worker gets env.:name. Call env.:name.run(model, input).', ['name' => $aiName]) }}</li>
                        @else
                            <li>{{ __('POST http://:host/run with {"model", "input"}. The reply is the model\'s JSON.', ['host' => $aiHost]) }}</li>
                        @endif
                        <li>{{ __('Text models take {"prompt"} or {"messages"} and answer with {"response"}.') }}</li>
                        <li>{{ __('Embedding models take {"text": [...]} and answer with {"data": [[...]]}, one vector per text.') }}</li>
                        <li>{{ __('Any Workers AI model id works. The ones under Try it are examples.') }}</li>
                        <li>{{ __('This starts working after the next deploy.') }}</li>
                    </ol>
                    @include('livewire.sites.edge.workspace.partials.sheets.metered-usage', ['service' => 'ai'])
                    <p class="text-xs text-brand-moss">{{ __('Billed per neuron, at each model\'s published rate, from the tokens each call reports.') }}</p>
                </div>

                <div x-show="tab === 'connect'" x-cloak class="grid gap-5">
                    @if ($isWorker)
                        <x-sheet.section :title="__('Worker')">
                            <pre class="overflow-x-auto rounded-xl bg-brand-sand/40 p-3.5 text-xs text-brand-ink dark:bg-zinc-950">{{ "const { response } = await env.{$aiName}.run('@cf/meta/llama-3.1-8b-instruct', { prompt: 'Say hello' });\nconst { data } = await env.{$aiName}.run('@cf/baai/bge-base-en-v1.5', { text: ['a sentence to embed'] });" }}</pre>
                        </x-sheet.section>
                    @else
                        @if ($site->isLaravelFrameworkDetected())
                            <x-sheet.section :title="__('Laravel')">
                                <pre class="overflow-x-auto rounded-xl bg-brand-sand/40 p-3.5 text-xs text-brand-ink dark:bg-zinc-950">{{ "\$answer = Http::post('http://{$aiHost}/run', [\n    'model' => '@cf/meta/llama-3.1-8b-instruct',\n    'input' => ['prompt' => 'Say hello'],\n])->json('response');" }}</pre>
                            </x-sheet.section>
                        @endif
                        <x-sheet.section :title="__('Node')">
                            <pre class="overflow-x-auto rounded-xl bg-brand-sand/40 p-3.5 text-xs text-brand-ink dark:bg-zinc-950">{{ "const res = await fetch('http://{$aiHost}/run', {\n  method: 'POST',\n  headers: { 'content-type': 'application/json' },\n  body: JSON.stringify({ model: '@cf/meta/llama-3.1-8b-instruct', input: { prompt: 'Say hello' } }),\n});\nconst { response } = await res.json();" }}</pre>
                        </x-sheet.section>
                        <x-sheet.section :title="__('HTTP')">
                            <pre class="overflow-x-auto rounded-xl bg-brand-sand/40 p-3.5 text-xs text-brand-ink dark:bg-zinc-950">{{ "curl -X POST http://{$aiHost}/run -H 'content-type: application/json' \\\n  -d '".json_encode(['model' => '@cf/meta/llama-3.1-8b-instruct', 'input' => ['prompt' => 'Say hello']], JSON_UNESCAPED_SLASHES)."'" }}</pre>
                        </x-sheet.section>
                    @endif
                </div>

                <div x-show="tab === 'try'" x-cloak class="grid gap-3">
                    <p class="text-xs text-brand-moss">{{ __('Runs one prompt from here, the same way the app would. Example models:') }}</p>
                    <x-sheet.options>
                        @foreach ($aiModels as $id => $model)
                            <x-sheet.option :selected="$aiModel === $id" :title="$model['label']" :description="$id" :meta="$model['kind'] === 'embedding' ? __('embeddings') : __('text')" wire:click="$set('aiModel', {{ \Illuminate\Support\Js::from($id) }})" />
                        @endforeach
                    </x-sheet.options>
                    <x-sheet.field :label="__('Prompt')" for="ai-prompt">
                        <textarea id="ai-prompt" wire:model="aiPrompt" rows="3" maxlength="2000" class="dply-input mt-0"></textarea>
                    </x-sheet.field>
                    <div><x-sheet.button variant="primary" wire:click="runAiDemo" wire:loading.attr="disabled" wire:target="runAiDemo">{{ __('Run') }}</x-sheet.button></div>
                    <x-input-error :messages="$errors->get('aiDemo')" />
                    @if ($aiDemoLog !== [])
                        <ol class="space-y-1 rounded-xl bg-brand-sand/40 p-3.5 font-mono text-xs text-brand-ink dark:bg-zinc-950">
                            @foreach ($aiDemoLog as $line)
                                <li>{{ $line }}</li>
                            @endforeach
                        </ol>
                    @endif
                    @if ($aiDemoResult !== '')
                        <pre class="max-h-60 overflow-auto whitespace-pre-wrap rounded-xl bg-brand-sand/40 p-3.5 text-xs text-brand-ink dark:bg-zinc-950">{{ $aiDemoResult }}</pre>
                    @endif
                </div>

                <x-sheet.danger :title="__('Turn off')">
                    <p class="text-xs leading-5 text-brand-moss">{{ __('Removes AI from this app on the next deploy. There is nothing stored to lose.') }}</p>
                    <div><x-sheet.button variant="danger" wire:click="askDeleteConnection({{ \Illuminate\Support\Js::from($aiHost) }})" wire:island="resources-delete-connection" x-on:click="$dispatch('open-modal', 'resources-delete-connection')">{{ __('Remove from this app') }}</x-sheet.button></div>
                </x-sheet.danger>
            </div>
        </x-sheet.body>
    @endif
</x-sheet>
