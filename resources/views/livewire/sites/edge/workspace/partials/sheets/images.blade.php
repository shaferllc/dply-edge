@php
    $imagesConnection = collect($connections)->firstWhere('host', $imagesHost);
    $imagesHostName = is_array($imagesConnection) ? $imagesConnection['host'] : $imagesHost;
    // Snippets for the call builder: {url} and {format} are filled in the browser.
    $imagesSnippets = array_filter([
        'curl' => "curl -X POST '{url}' \\\n  --data-binary @photo.jpg -o photo.{format}",
        'laravel' => $site->isLaravelFrameworkDetected() ? "\$image = Http::withBody(file_get_contents(\$path), 'application/octet-stream')\n    ->post('{url}')\n    ->body();" : null,
        'rails' => $site->isRailsFrameworkDetected() ? "image = Faraday.post(\n  '{url}',\n  File.binread(path),\n  'Content-Type' => 'application/octet-stream'\n).body" : null,
    ]);
    $imagesInfoSnippets = array_filter([
        'curl' => "curl -X POST '{url}' --data-binary @photo.jpg",
        'laravel' => isset($imagesSnippets['laravel']) ? "\$info = Http::withBody(file_get_contents(\$path), 'application/octet-stream')\n    ->post('{url}')\n    ->json();" : null,
        'rails' => isset($imagesSnippets['rails']) ? "info = JSON.parse(Faraday.post(\n  '{url}',\n  File.binread(path),\n  'Content-Type' => 'application/octet-stream'\n).body)" : null,
    ]);
    $imagesLangLabels = ['curl' => 'curl', 'laravel' => 'Laravel', 'rails' => 'Rails'];
    $imagesCode = 'block whitespace-pre-wrap break-all rounded-xl bg-brand-sand/40 p-3.5 pe-16 font-mono text-xs leading-5 text-brand-ink dark:bg-zinc-950';
@endphp
<x-sheet name="resources-images" :show="$imagesHost !== ''" maxWidth="3xl" focusable>
    <x-sheet.header :eyebrow="__('AI & media')" :title="__('Images')" close-wire="$set('imagesHost', '')">
        {{ __('Resize and convert pictures from your app. Send the image bytes, get details or a new image back.') }}
    </x-sheet.header>

    <x-sheet.body>
        {{-- The address, up front and copyable. --}}
        <div class="flex items-center justify-between gap-3 rounded-xl border border-brand-ink/10 px-3.5 py-2.5 dark:border-brand-mist/15" x-data="{ copied: false }">
            <div class="min-w-0">
                <p class="text-2xs font-semibold uppercase tracking-[0.16em] text-brand-mist">{{ __('Address') }}</p>
                <p class="truncate font-mono text-sm font-semibold text-brand-ink" title="http://{{ $imagesHostName }}">http://{{ $imagesHostName }}</p>
                <p class="text-2xs text-brand-moss">{{ __('Only this app can reach it. Works after the next deploy.') }}</p>
            </div>
            <x-sheet.button x-on:click="navigator.clipboard.writeText('http://{{ $imagesHostName }}'); copied = true; setTimeout(() => copied = false, 1500)">
                <span x-text="copied ? @js(__('Copied')) : @js(__('Copy'))"></span>
            </x-sheet.button>
        </div>

        {{-- The two calls, at a glance. --}}
        <x-sheet.section :title="__('Two calls')">
            <div class="grid gap-2 sm:grid-cols-2">
                <div class="rounded-xl border border-brand-ink/10 p-3.5 dark:border-brand-mist/15">
                    <p class="font-mono text-xs font-semibold text-brand-ink"><span class="text-brand-forest dark:text-brand-sage">POST</span> /info</p>
                    <p class="mt-1 text-xs text-brand-moss">{{ __('Returns the format, width, height and file size.') }}</p>
                </div>
                <div class="rounded-xl border border-brand-ink/10 p-3.5 dark:border-brand-mist/15">
                    <p class="font-mono text-xs font-semibold text-brand-ink"><span class="text-brand-forest dark:text-brand-sage">POST</span> /?width=…</p>
                    <p class="mt-1 text-xs text-brand-moss">{{ __('Returns the resized or converted image.') }}</p>
                </div>
            </div>
        </x-sheet.section>

        {{-- Build a call: pick options, copy the code, or run it on the live app. --}}
        <x-sheet.section :title="__('Build a call')">
            <div class="grid gap-3 rounded-xl border border-brand-ink/10 p-3.5 dark:border-brand-mist/15" x-data="{
                call: 'resize',
                lang: @js(array_key_last($imagesSnippets)),
                width: 800, height: '', fit: 'scale-down', format: 'webp', quality: 80,
                copied: false,
                running: false,
                result: null,
                async run() {
                    this.running = true;
                    this.result = null;
                    try {
                        this.result = await $wire.$island('resources-images').runImagesDemo(this.call, { width: this.width, height: this.height, fit: this.fit, format: this.format, quality: this.quality });
                    } catch (e) {
                        this.result = { ok: false, error: String(e) };
                    }
                    this.running = false;
                },
                kb(bytes) { return (bytes / 1024).toFixed(1) + ' KB'; },
                snippets: @js($imagesSnippets),
                infoSnippets: @js($imagesInfoSnippets),
                get url() {
                    if (this.call === 'info') return 'http://{{ $imagesHostName }}/info';
                    const q = new URLSearchParams(Object.entries({ width: this.width, height: this.height, fit: this.fit, format: this.format, quality: this.quality }).filter(([, v]) => v !== '' && v !== null)).toString();
                    return 'http://{{ $imagesHostName }}/?' + q;
                },
                get code() {
                    return (this.call === 'info' ? this.infoSnippets : this.snippets)[this.lang].replaceAll('{url}', this.url).replaceAll('{format}', this.format);
                },
            }">
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <div class="flex gap-1 rounded-lg bg-brand-sand/30 p-0.5 dark:bg-zinc-800/60">
                        <button type="button" x-on:click="call = 'resize'" :class="call === 'resize' ? 'bg-brand-ink text-brand-cream' : 'text-brand-moss hover:text-brand-ink'" class="rounded-md px-2.5 py-1 text-xs font-semibold">{{ __('Resize') }}</button>
                        <button type="button" x-on:click="call = 'info'" :class="call === 'info' ? 'bg-brand-ink text-brand-cream' : 'text-brand-moss hover:text-brand-ink'" class="rounded-md px-2.5 py-1 text-xs font-semibold">{{ __('Details') }}</button>
                    </div>
                    @if (count($imagesSnippets) > 1)
                        <div class="flex gap-1 rounded-lg bg-brand-sand/30 p-0.5 dark:bg-zinc-800/60">
                            @foreach (array_keys($imagesSnippets) as $lang)
                                <button type="button" x-on:click="lang = '{{ $lang }}'" :class="lang === '{{ $lang }}' ? 'bg-brand-ink text-brand-cream' : 'text-brand-moss hover:text-brand-ink'" class="rounded-md px-2.5 py-1 text-xs font-semibold">{{ $imagesLangLabels[$lang] }}</button>
                            @endforeach
                        </div>
                    @endif
                </div>

                <div x-show="call === 'resize'" class="grid grid-cols-2 gap-3 sm:grid-cols-5">
                    <x-sheet.field :label="__('Width')" for="images-try-width">
                        <input id="images-try-width" type="number" min="1" max="8000" x-model="width" placeholder="auto" class="dply-input mt-0" />
                    </x-sheet.field>
                    <x-sheet.field :label="__('Height')" for="images-try-height">
                        <input id="images-try-height" type="number" min="1" max="8000" x-model="height" placeholder="auto" class="dply-input mt-0" />
                    </x-sheet.field>
                    <x-sheet.field :label="__('Fit')" for="images-try-fit">
                        <select id="images-try-fit" x-model="fit" class="dply-input mt-0">
                            @foreach (['scale-down', 'contain', 'cover', 'crop', 'pad'] as $fit)
                                <option value="{{ $fit }}">{{ $fit }}</option>
                            @endforeach
                        </select>
                    </x-sheet.field>
                    <x-sheet.field :label="__('Format')" for="images-try-format">
                        <select id="images-try-format" x-model="format" class="dply-input mt-0">
                            @foreach (['webp', 'avif', 'jpeg', 'png', 'gif'] as $format)
                                <option value="{{ $format }}">{{ $format }}</option>
                            @endforeach
                        </select>
                    </x-sheet.field>
                    <x-sheet.field :label="__('Quality')" for="images-try-quality">
                        <input id="images-try-quality" type="number" min="1" max="100" x-model="quality" class="dply-input mt-0" />
                    </x-sheet.field>
                </div>

                <div class="relative">
                    <pre class="{{ $imagesCode }}" x-text="code"></pre>
                    <button type="button" x-on:click="navigator.clipboard.writeText(code); copied = true; setTimeout(() => copied = false, 1500)" class="absolute end-2 top-2 rounded-md border border-brand-ink/15 bg-white px-2 py-0.5 text-2xs font-semibold text-brand-ink hover:border-brand-ink/40 dark:border-brand-mist/25 dark:bg-zinc-900" x-text="copied ? @js(__('Copied')) : @js(__('Copy'))"></button>
                </div>

                {{-- Run it for real: a sample picture goes through the live app's Images binding. --}}
                @if ($isContainer && auth()->user()?->can('update', $site))
                    <div class="flex flex-wrap items-center gap-3 border-t border-brand-ink/10 pt-3 dark:border-brand-mist/15">
                        <x-sheet.button variant="primary" x-on:click="run()" x-bind:disabled="running">
                            <span x-text="running ? @js(__('Running on the app…')) : @js(__('Run it on the app'))"></span>
                        </x-sheet.button>
                        <p class="text-2xs text-brand-moss">{{ __('Sends a sample picture (the dply logo) through your live app with these options.') }}</p>
                    </div>

                    <template x-if="result && ! result.ok">
                        <x-sheet.note tone="warn"><span x-text="result.error"></span></x-sheet.note>
                    </template>

                    <template x-if="result && result.ok">
                        <div class="grid gap-3">
                            <div class="flex flex-wrap gap-x-4 gap-y-1 font-mono text-2xs text-brand-mist">
                                <span><b class="text-brand-ink" x-text="result.ms + ' ms'"></b> {{ __('round trip') }}</span>
                                <span>{{ __('sent') }} <b class="text-brand-ink" x-text="kb(result.in_bytes)"></b></span>
                                <template x-if="result.out_bytes">
                                    <span>{{ __('got back') }} <b class="text-brand-ink" x-text="kb(result.out_bytes)"></b> <span x-text="result.type"></span> (<b class="text-brand-ink" x-text="Math.round(100 - result.out_bytes / result.in_bytes * 100) + '%'"></b> {{ __('smaller') }})</span>
                                </template>
                            </div>
                            <template x-if="result.image">
                                <div class="grid gap-3 sm:grid-cols-2">
                                    <figure class="grid gap-1">
                                        <img src="{{ asset('images/og/dply-og.png') }}" alt="{{ __('Original sample picture') }}" class="max-h-56 w-full rounded-xl border border-brand-ink/10 bg-[repeating-conic-gradient(rgb(0_0_0/0.05)_0_25%,transparent_0_50%)] bg-[length:16px_16px] object-contain dark:border-brand-mist/15" />
                                        <figcaption class="text-2xs text-brand-moss">{{ __('Sent') }}</figcaption>
                                    </figure>
                                    <figure class="grid gap-1">
                                        <img :src="result.image" alt="{{ __('Picture the app sent back') }}" class="max-h-56 w-full rounded-xl border border-brand-forest/40 bg-[repeating-conic-gradient(rgb(0_0_0/0.05)_0_25%,transparent_0_50%)] bg-[length:16px_16px] object-contain" />
                                        <figcaption class="text-2xs text-brand-moss">{{ __('Back from the app') }}</figcaption>
                                    </figure>
                                </div>
                            </template>
                            <template x-if="result.info">
                                <pre class="{{ $imagesCode }}" x-text="JSON.stringify(result.info, null, 2)"></pre>
                            </template>
                        </div>
                    </template>
                @endif
            </div>
        </x-sheet.section>

        {{-- Limits, as a scannable list instead of prose. --}}
        <x-sheet.section :title="__('Options')">
            <dl class="divide-y divide-brand-ink/10 rounded-xl border border-brand-ink/10 text-xs dark:divide-brand-mist/15 dark:border-brand-mist/15">
                @foreach ([
                    ['width, height', __('Pixels, up to 8000. Leave one out to keep the aspect ratio.')],
                    ['fit', 'scale-down · contain · cover · crop · pad'],
                    ['format', __('webp (default) · avif · jpeg · png · gif')],
                    ['quality', __('1 to 100')],
                    [__('size'), __('Images up to 20 MB')],
                ] as [$option, $meaning])
                    <div class="grid grid-cols-[7rem_1fr] gap-3 px-3.5 py-2">
                        <dt class="font-mono font-semibold text-brand-ink">{{ $option }}</dt>
                        <dd class="text-brand-moss">{{ $meaning }}</dd>
                    </div>
                @endforeach
            </dl>
        </x-sheet.section>

        @if (is_array($imagesConnection))
            <x-sheet.danger :title="__('Turn off')">
                <p class="text-xs leading-5 text-brand-moss">{{ __('Removes Images from this app on the next deploy. There is nothing stored to lose.') }}</p>
                <div><x-sheet.button variant="danger" wire:click="askDeleteConnection({{ \Illuminate\Support\Js::from($imagesHostName) }})" wire:island="resources-delete-connection" x-on:click="$dispatch('open-modal', 'resources-delete-connection')">{{ __('Remove from this app') }}</x-sheet.button></div>
            </x-sheet.danger>
        @endif
    </x-sheet.body>
</x-sheet>
