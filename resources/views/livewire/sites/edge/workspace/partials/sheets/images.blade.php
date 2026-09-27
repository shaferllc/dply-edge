@php
    $imagesConnection = collect($connections)->firstWhere('host', $imagesHost);
    $imagesHostName = is_array($imagesConnection) ? $imagesConnection['host'] : $imagesHost;
@endphp
<x-sheet name="resources-images" :show="$imagesHost !== ''" maxWidth="3xl" focusable>
    <x-sheet.header :eyebrow="$imagesHostName" :title="__('Images')" close-wire="$set('imagesHost', '')">
        {{ __('This app reads and resizes pictures at http://:host/. The address belongs only to this app. Send the image bytes. The reply is either the details or a new image.', ['host' => $imagesHostName]) }}
    </x-sheet.header>

    <x-sheet.body>
        <div class="grid gap-4" x-data="{ tab: 'how' }">
            <x-sheet.tabs>
                <button type="button" role="tab" x-on:click="tab = 'how'" :aria-selected="tab === 'how' ? 'true' : 'false'">{{ __('How it works') }}</button>
                <button type="button" role="tab" x-on:click="tab = 'implementation'" :aria-selected="tab === 'implementation' ? 'true' : 'false'">{{ __('Implementation') }}</button>
                <button type="button" role="tab" x-on:click="tab = 'try'" :aria-selected="tab === 'try' ? 'true' : 'false'">{{ __('Try it') }}</button>
            </x-sheet.tabs>
            <div x-show="tab === 'how'">
                <ol class="list-decimal space-y-1.5 pl-4 text-xs leading-5 text-brand-ink">
                    <li>{{ __('POST the image to http://:host/info. The reply is format, width, height, and file size.', ['host' => $imagesHostName]) }}</li>
                    <li>{{ __('POST the image to http://:host/ with width, height, fit, format, and quality. The reply is the new image.', ['host' => $imagesHostName]) }}</li>
                    <li>{{ __('Width and height are pixels, up to 8000. Fit is scale-down, contain, cover, crop, or pad.') }}</li>
                    <li>{{ __('Format is jpeg, png, webp, avif, or gif. Quality is 1 to 100. A call with no format comes back as webp.') }}</li>
                    <li>{{ __('An image can be up to 20 MB. This starts working after the next deploy.') }}</li>
                </ol>
            </div>
            <div x-show="tab === 'implementation'" x-cloak class="grid gap-5">
                @if ($site->isLaravelFrameworkDetected())
                    <x-sheet.section :title="__('Laravel')">
                        <p class="text-xs leading-5 text-brand-moss">{{ __('Send the file bytes. The address is on the app after the next deploy.') }}</p>
                        <pre class="overflow-x-auto rounded-xl bg-brand-sand/40 p-3.5 text-xs text-brand-ink dark:bg-zinc-950">{{ "\$bytes = file_get_contents(\$path);\n\$info = Http::withBody(\$bytes, 'application/octet-stream')->post('http://{$imagesHostName}/info')->json();\n\$image = Http::withBody(\$bytes, 'application/octet-stream')->post('http://{$imagesHostName}/?width=800&format=webp')->body();" }}</pre>
                    </x-sheet.section>
                @elseif ($site->isRailsFrameworkDetected())
                    <x-sheet.section :title="__('Rails')">
                        <p class="text-xs leading-5 text-brand-moss">{{ __('Send the file bytes. The address is on the app after the next deploy.') }}</p>
                        <pre class="overflow-x-auto rounded-xl bg-brand-sand/40 p-3.5 text-xs text-brand-ink dark:bg-zinc-950">{{ "bytes = File.binread(path)\nFaraday.post('http://{$imagesHostName}/info', bytes, 'Content-Type' => 'application/octet-stream')\nFaraday.post('http://{$imagesHostName}/?width=800&format=webp', bytes, 'Content-Type' => 'application/octet-stream').body" }}</pre>
                    </x-sheet.section>
                @endif
                <x-sheet.section :title="__('HTTP')">
                    <pre class="overflow-x-auto rounded-xl bg-brand-sand/40 p-3.5 text-xs text-brand-ink dark:bg-zinc-950">{{ "curl -X POST http://{$imagesHostName}/info --data-binary @photo.jpg\ncurl -X POST 'http://{$imagesHostName}/?width=800&height=600&fit=cover&format=webp&quality=80' --data-binary @photo.jpg -o photo.webp" }}</pre>
                </x-sheet.section>
            </div>
            {{-- The images address only answers inside the app, so this builds the call instead of running it. --}}
            <div x-show="tab === 'try'" x-cloak class="grid gap-4" x-data="{ width: 800, height: '', fit: 'scale-down', format: 'webp', quality: 80, get query() { return new URLSearchParams(Object.entries({ width: this.width, height: this.height, fit: this.fit, format: this.format, quality: this.quality }).filter(([, v]) => v !== '' && v !== null)).toString() } }">
                <x-sheet.note>{{ __('Only the app can reach this address, so this page cannot resize a picture for you. Pick the options and run the call from the app.') }}</x-sheet.note>
                <div class="grid grid-cols-2 gap-3 sm:grid-cols-5">
                    <x-sheet.field :label="__('Width')" for="images-try-width">
                        <input id="images-try-width" type="number" min="1" max="8000" x-model="width" class="dply-input mt-0" />
                    </x-sheet.field>
                    <x-sheet.field :label="__('Height')" for="images-try-height">
                        <input id="images-try-height" type="number" min="1" max="8000" x-model="height" class="dply-input mt-0" />
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
                <x-sheet.section :title="__('Resize URL')">
                    <pre class="overflow-x-auto rounded-xl bg-brand-sand/40 p-3.5 text-xs text-brand-ink dark:bg-zinc-950" x-text="'http://{{ $imagesHostName }}/?' + query"></pre>
                </x-sheet.section>
                <x-sheet.section :title="__('From the app')">
                    <pre class="overflow-x-auto rounded-xl bg-brand-sand/40 p-3.5 text-xs text-brand-ink dark:bg-zinc-950" x-text="'curl -X POST \'http://{{ $imagesHostName }}/?' + query + '\' --data-binary @photo.jpg -o photo.' + format"></pre>
                </x-sheet.section>
            </div>
        </div>

        @if (is_array($imagesConnection))
            <x-sheet.danger :title="__('Turn off')">
                <p class="text-xs leading-5 text-brand-moss">{{ __('Removes Images from this app on the next deploy. There is nothing stored to lose.') }}</p>
                <div><x-sheet.button variant="danger" wire:click="askDeleteConnection({{ \Illuminate\Support\Js::from($imagesHostName) }})" wire:island="resources-delete-connection" x-on:click="$dispatch('open-modal', 'resources-delete-connection')">{{ __('Remove from this app') }}</x-sheet.button></div>
            </x-sheet.danger>
        @endif
    </x-sheet.body>
</x-sheet>
