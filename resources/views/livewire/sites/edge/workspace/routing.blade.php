<div>
    {{-- Read-only for anyone who cannot configure this app (org Deployer, app Viewer/Deployer). --}}
    <fieldset @disabled(! auth()->user()?->can('update', $site)) class="min-w-0">
    @php
        $tabs = [
            ['id' => 'domains', 'label' => __('Domains'), 'icon' => 'heroicon-o-globe-alt'],
            ['id' => 'redirects', 'label' => __('Redirects'), 'icon' => 'heroicon-o-arrow-uturn-right'],
            ['id' => 'rewrites', 'label' => __('Rewrites'), 'icon' => 'heroicon-o-arrows-right-left'],
            ['id' => 'headers', 'label' => __('Headers'), 'icon' => 'heroicon-o-shield-check'],
        ];
    @endphp

    <x-server-workspace-tablist
        :aria-label="__('Routing sections')"
        :scroll="true"
        class="mb-0 rounded-none border-0 border-b border-brand-ink/10 bg-transparent p-2 shadow-none sm:px-4"
    >
        @foreach ($tabs as $entry)
            <x-server-workspace-tab
                id="edge-routing-tab-{{ $entry['id'] }}"
                :active="$tab === $entry['id']"
                :icon="$entry['icon']"
                wire:click="setTab('{{ $entry['id'] }}')"
            >{{ $entry['label'] }}</x-server-workspace-tab>
        @endforeach
    </x-server-workspace-tablist>

    <div wire:key="edge-routing-tab-{{ $tab }}">
        <div class="hidden" wire:loading.class.remove="hidden" wire:target="setTab">
            @include('livewire.sites.partials._panel-skeleton')
        </div>
        <div wire:loading.class="hidden" wire:target="setTab">
            @includeWhen($tab === 'domains', 'livewire.sites.partials.edge.routing-domains')
            @includeWhen($tab === 'redirects', 'livewire.sites.partials.edge.routing-redirects')
            @includeWhen($tab === 'rewrites', 'livewire.sites.partials.edge.routing-rewrites')
            @includeWhen($tab === 'headers', 'livewire.sites.partials.edge.routing-headers')
        </div>
    </div>

    {{-- Add or edit one redirect, rewrite, or header rule --}}
    <x-modal name="routing-rule" maxWidth="xl" overlayClass="bg-brand-ink/40" focusable>
        <form wire:submit="saveRule" class="space-y-5 p-6 sm:p-7">
            <div class="flex items-start justify-between gap-4">
                <h2 class="text-lg font-semibold text-brand-ink">
                    {{ match ($ruleKind) {
                        'rewrites' => $editingRule === null ? __('Add a rewrite') : __('Edit rewrite'),
                        'headers' => $editingRule === null ? __('Add a header rule') : __('Edit header rule'),
                        default => $editingRule === null ? __('Add a redirect') : __('Edit redirect'),
                    } }}
                </h2>
                <button type="button" x-on:click="$dispatch('close-modal', 'routing-rule')" class="dply-icon-btn h-9 w-9" aria-label="{{ __('Close') }}">
                    <x-heroicon-o-x-mark class="h-5 w-5" aria-hidden="true" />
                </button>
            </div>

            @if ($ruleKind === 'redirects')
                <x-sheet.field :label="__('When someone visits')" for="rule-from" :help="__('A path like /old-page, or /blog/* for everything under it.')">
                    <input id="rule-from" type="text" wire:model="new_redirect_from" class="dply-input mt-0 font-mono text-sm" placeholder="/old-page" autocomplete="off" />
                </x-sheet.field>
                @error('new_redirect_from') <x-sheet.note tone="danger">{{ $message }}</x-sheet.note> @enderror
                <x-sheet.field :label="__('Send them to')" for="rule-to" :help="__('A path or a full URL. :splat carries over what * matched.')">
                    <input id="rule-to" type="text" wire:model="new_redirect_to" class="dply-input mt-0 font-mono text-sm" placeholder="/new-page" autocomplete="off" />
                </x-sheet.field>
                <x-sheet.field :label="__('For good, or for now?')" for="rule-status">
                    <select id="rule-status" wire:model="new_redirect_status" class="dply-input mt-0">
                        <option value="301">{{ __('Permanent (301): search engines update the link') }}</option>
                        <option value="308">{{ __('Permanent, keep the method (308)') }}</option>
                        <option value="302">{{ __('Temporary (302)') }}</option>
                        <option value="307">{{ __('Temporary, keep the method (307)') }}</option>
                    </select>
                </x-sheet.field>
            @elseif ($ruleKind === 'rewrites')
                <x-sheet.field :label="__('When someone visits')" for="rule-rw-from" :help="__('A path like /api/*. The address bar doesn’t change.')">
                    <input id="rule-rw-from" type="text" wire:model="new_rewrite_from" class="dply-input mt-0 font-mono text-sm" placeholder="/api/*" autocomplete="off" />
                </x-sheet.field>
                @error('new_rewrite_from') <x-sheet.note tone="danger">{{ $message }}</x-sheet.note> @enderror
                <x-sheet.field :label="__('Serve it from')" for="rule-rw-to" :help="__('Another path in this app, or a full URL to proxy.')">
                    <input id="rule-rw-to" type="text" wire:model="new_rewrite_to" class="dply-input mt-0 font-mono text-sm" placeholder="https://api.example.com/:splat" autocomplete="off" />
                </x-sheet.field>
            @else
                <x-sheet.field :label="__('For paths matching')" for="rule-h-for">
                    <input id="rule-h-for" type="text" wire:model="new_header_for" class="dply-input mt-0 font-mono text-sm" placeholder="/assets/*" autocomplete="off" />
                </x-sheet.field>
                @error('new_header_for') <x-sheet.note tone="danger">{{ $message }}</x-sheet.note> @enderror
                <x-sheet.field :label="__('Add these headers')" for="rule-h-pairs" :help="__('One per line, as Name: value.')">
                    <textarea id="rule-h-pairs" rows="4" wire:model="new_header_pairs" class="dply-input mt-0 font-mono text-xs" placeholder="Cache-Control: public, max-age=31536000, immutable"></textarea>
                </x-sheet.field>
            @endif

            <div class="flex items-center justify-between gap-2">
                @if ($editingRule !== null)
                    <button type="button" wire:click="removeOpenRule" wire:confirm="{{ __('Remove this rule?') }}" class="text-xs font-medium text-rose-600 hover:underline dark:text-rose-300">{{ __('Remove') }}</button>
                @else
                    <span></span>
                @endif
                <div class="flex gap-2">
                    <x-sheet.button type="button" x-on:click="$dispatch('close-modal', 'routing-rule')">{{ __('Cancel') }}</x-sheet.button>
                    <x-sheet.button type="submit" variant="primary" wire:loading.attr="disabled" wire:target="saveRule">{{ __('Save') }}</x-sheet.button>
                </div>
            </div>
        </form>
    </x-modal>

    {{-- Import redirects in bulk --}}
    <x-modal name="redirect-import" maxWidth="2xl" overlayClass="bg-brand-ink/40" focusable>
        <form wire:submit="importRedirects" class="space-y-4 p-6 sm:p-7">
            <div class="flex items-start justify-between gap-4">
                <div>
                    <h2 class="text-lg font-semibold text-brand-ink">{{ __('Import redirects') }}</h2>
                    <p class="mt-0.5 text-sm text-brand-moss">{{ __('Paste a bulk-redirects CSV (source_url,target_url,status_code) or a _redirects block (/from /to 301). One rule per line; hosts are dropped.') }}</p>
                </div>
                <button type="button" x-on:click="$dispatch('close-modal', 'redirect-import')" class="dply-icon-btn h-9 w-9" aria-label="{{ __('Close') }}">
                    <x-heroicon-o-x-mark class="h-5 w-5" aria-hidden="true" />
                </button>
            </div>
            <label for="bulk-redirects" class="sr-only">{{ __('Redirects to import') }}</label>
            <textarea id="bulk-redirects" rows="8" wire:model.live.debounce.400ms="bulk_redirects" class="dply-input mt-0 font-mono text-xs" spellcheck="false" placeholder="/old-page,/new-page,301&#10;/docs/*  /help/:splat  301"></textarea>
            @error('bulk_redirects') <x-sheet.note tone="danger">{{ $message }}</x-sheet.note> @enderror
            @if ($bulkPreview !== null)
                <p class="text-xs text-brand-moss">
                    @if ($bulkPreview['redirects'] !== [])
                        {{ trans_choice('{1}1 rule ready to import.|[2,*]:count rules ready to import.', count($bulkPreview['redirects']), ['count' => count($bulkPreview['redirects'])]) }}
                    @endif
                    @if ($bulkPreview['errors'] !== [])
                        <span class="text-rose-600">{{ trans_choice('{1}1 line will be skipped.|[2,*]:count lines will be skipped.', count($bulkPreview['errors']), ['count' => count($bulkPreview['errors'])]) }}</span>
                    @endif
                </p>
                @if ($bulkPreview['errors'] !== [])
                    <ul class="space-y-0.5 text-xs text-rose-600">
                        @foreach (array_slice($bulkPreview['errors'], 0, 5) as $error)
                            <li>{{ $error }}</li>
                        @endforeach
                    </ul>
                @endif
            @endif
            <div class="flex justify-end gap-2">
                <x-sheet.button type="button" x-on:click="$dispatch('close-modal', 'redirect-import')">{{ __('Cancel') }}</x-sheet.button>
                <x-sheet.button type="submit" variant="primary" wire:loading.attr="disabled" wire:target="importRedirects">{{ __('Import') }}</x-sheet.button>
            </div>
        </form>
    </x-modal>

    @include('livewire.partials.confirm-action-modal')
    </fieldset>
</div>
