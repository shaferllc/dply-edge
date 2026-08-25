<section class="px-5 py-4 sm:px-6">
    <div>
        <p class="text-2xs font-semibold uppercase tracking-[0.16em] text-brand-mist">{{ __('Headers') }}</p>
        <p class="mt-1 text-sm text-brand-moss">{{ __('Response header rules by path pattern.') }}</p>
    </div>

    @if ($repoHeaders !== [])
        <div class="mt-3 rounded-xl border border-brand-ink/10 bg-brand-sand/20 px-3 py-2 text-xs text-brand-moss">
            {{ __(':count from :file', ['count' => count($repoHeaders), 'file' => $sourcePath]) }}
        </div>
    @endif

    @if ($dashboard_headers === [])
        <x-empty-state
            borderless
            compact
            icon="heroicon-o-arrows-right-left"
            :title="__('No dashboard header rules yet')"
        />
    @else
        <ul class="mt-3 space-y-2">
            @foreach ($dashboard_headers as $index => $rule)
                <li wire:key="dash-headers-{{ $index }}" class="rounded-xl border border-brand-ink/10 bg-brand-sand/20 px-3 py-2 text-xs">
                    <div class="flex items-start justify-between gap-2">
                        <div class="min-w-0 flex-1">
                            <p class="font-mono text-brand-ink">{{ __('for') }} <span class="break-all">{{ $rule['for'] }}</span></p>
                            <dl class="mt-1 grid grid-cols-1 gap-x-3 sm:grid-cols-[12rem_1fr]">
                                @foreach ($rule['values'] as $name => $value)
                                    <dt class="font-mono text-brand-mist">{{ $name }}</dt>
                                    <dd class="font-mono text-brand-ink break-all">{{ $value }}</dd>
                                @endforeach
                            </dl>
                        </div>
                        <button
                            type="button"
                            wire:click="openConfirmActionModal('removeHeaderRule', @js([$index]), @js(__('Remove header rule')), @js(__('Remove headers for :pattern?', ['pattern' => $rule['for']])), @js(__('Remove')), true)"
                            class="inline-flex items-center gap-1.5 rounded-lg border border-rose-200 bg-white px-2.5 py-1 text-xs font-semibold uppercase tracking-wide text-rose-700 shadow-sm hover:bg-rose-50"
                        >{{ __('Remove') }}</button>
                    </div>
                </li>
            @endforeach
        </ul>
    @endif

    <form wire:submit.prevent="addHeaderRule" class="mt-4 space-y-2">
        <div>
            <label for="new-header-for" class="text-2xs font-semibold uppercase tracking-wide text-brand-mist">{{ __('Path pattern') }}</label>
            <input id="new-header-for" type="text" wire:model="new_header_for" class="mt-1 block w-full rounded-md border border-brand-ink/15 bg-white px-3 py-1.5 font-mono text-xs text-brand-ink focus:border-brand-forest focus:ring-brand-forest" placeholder="/assets/*" autocomplete="off" />
            @error('new_header_for') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
        </div>
        <div>
            <label for="new-header-pairs" class="text-2xs font-semibold uppercase tracking-wide text-brand-mist">{{ __('Headers (one per line, Name: value)') }}</label>
            <textarea id="new-header-pairs" rows="3" wire:model="new_header_pairs" class="mt-1 block w-full rounded-md border border-brand-ink/15 bg-white px-3 py-1.5 font-mono text-xs text-brand-ink focus:border-brand-forest focus:ring-brand-forest" placeholder="Cache-Control: public, max-age=31536000, immutable"></textarea>
        </div>
        <button type="submit" class="rounded-lg bg-brand-ink px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-brand-ink/90">{{ __('Add rule') }}</button>
    </form>

    <details class="mt-5 border-t border-brand-ink/10 pt-4">
        <summary class="cursor-pointer text-xs font-semibold text-brand-ink">{{ __('Advanced') }}</summary>
        <div class="mt-3 space-y-4">
            <x-edge-yaml-example :file="$sourcePath">
headers:
  - for: /assets/*
    values:
      Cache-Control: "public, max-age=31536000, immutable"
            </x-edge-yaml-example>
            <div>
                <p class="text-2xs font-semibold uppercase tracking-wide text-brand-mist">{{ __('Templates') }}</p>
                <div class="mt-2 grid grid-cols-1 gap-2 sm:grid-cols-2">
                    @foreach (collect($templates)->only(['security-headers', 'cache-assets']) as $key => $template)
                        <div class="rounded-lg border border-brand-ink/10 p-3">
                            <div class="flex items-baseline justify-between gap-2">
                                <p class="text-sm font-semibold text-brand-ink">{{ $template['label'] }}</p>
                                <button type="button" wire:click="applyTemplate('{{ $key }}')" class="rounded-lg border border-brand-ink/15 bg-white px-2 py-0.5 text-2xs font-semibold uppercase tracking-wide text-brand-ink hover:bg-brand-sand/40">{{ __('Apply') }}</button>
                            </div>
                            <p class="mt-1 text-xs text-brand-moss">{{ $template['hint'] }}</p>
                        </div>
                    @endforeach
                </div>
            </div>
        </div>
    </details>
</section>
