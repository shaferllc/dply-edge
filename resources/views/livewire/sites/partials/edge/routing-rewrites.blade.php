<section class="px-5 py-4 sm:px-6">
    <div>
        <p class="text-2xs font-semibold uppercase tracking-[0.16em] text-brand-mist">{{ __('Rewrites') }}</p>
        <p class="mt-1 text-sm text-brand-moss">{{ __('Proxy or rewrite paths without changing the browser URL.') }}</p>
    </div>

    @if ($repoRewrites !== [])
        <div class="mt-3 rounded-xl border border-brand-ink/10 bg-brand-sand/20 px-3 py-2 text-xs text-brand-moss">
            {{ __(':count from :file', ['count' => count($repoRewrites), 'file' => $sourcePath]) }}
        </div>
    @endif

    @if ($dashboard_rewrites === [])
        <x-empty-state
            borderless
            compact
            icon="heroicon-o-arrows-right-left"
            :title="__('No dashboard rewrites yet')"
        />
    @else
        <div class="mt-3 overflow-x-auto rounded-xl border border-brand-ink/10">
            <table class="min-w-full divide-y divide-brand-ink/8 text-xs">
                <thead class="bg-brand-sand/30 text-left text-2xs font-semibold uppercase tracking-wide text-brand-mist">
                    <tr>
                        <th class="px-3 py-2">{{ __('From') }}</th>
                        <th class="px-3 py-2">{{ __('To') }}</th>
                        <th class="px-3 py-2 text-right">{{ __('Actions') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-brand-ink/8 text-brand-ink">
                    @foreach ($dashboard_rewrites as $index => $rule)
                        <tr wire:key="dash-rewrite-{{ $index }}">
                            <td class="px-3 py-2 font-mono break-all">{{ $rule['from'] }}</td>
                            <td class="px-3 py-2 font-mono break-all">{{ $rule['to'] }}</td>
                            <td class="px-3 py-2 text-right">
                                <button
                                    type="button"
                                    wire:click="openConfirmActionModal('removeRewrite', @js([$index]), @js(__('Remove rewrite')), @js(__('Remove :from → :to?', ['from' => $rule['from'], 'to' => $rule['to']])), @js(__('Remove')), true)"
                                    class="inline-flex items-center gap-1.5 rounded-lg border border-rose-200 bg-white px-2.5 py-1 text-xs font-semibold uppercase tracking-wide text-rose-700 shadow-sm hover:bg-rose-50"
                                >{{ __('Remove') }}</button>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif

    <form wire:submit.prevent="addRewrite" class="mt-4 grid grid-cols-1 gap-2 sm:grid-cols-[1fr_1fr_auto] sm:items-end">
        <div>
            <label for="new-rewrite-from" class="text-2xs font-semibold uppercase tracking-wide text-brand-mist">{{ __('From') }}</label>
            <input id="new-rewrite-from" type="text" wire:model="new_rewrite_from" class="mt-1 block w-full rounded-md border border-brand-ink/15 bg-white px-3 py-1.5 font-mono text-xs text-brand-ink focus:border-brand-forest focus:ring-brand-forest" placeholder="/api/*" autocomplete="off" />
            @error('new_rewrite_from') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
        </div>
        <div>
            <label for="new-rewrite-to" class="text-2xs font-semibold uppercase tracking-wide text-brand-mist">{{ __('To') }}</label>
            <input id="new-rewrite-to" type="text" wire:model="new_rewrite_to" class="mt-1 block w-full rounded-md border border-brand-ink/15 bg-white px-3 py-1.5 font-mono text-xs text-brand-ink focus:border-brand-forest focus:ring-brand-forest" placeholder="https://api.example.com/:splat" autocomplete="off" />
        </div>
        <button type="submit" class="rounded-lg bg-brand-ink px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-brand-ink/90">{{ __('Add') }}</button>
    </form>

    <details class="mt-5 border-t border-brand-ink/10 pt-4">
        <summary class="cursor-pointer text-xs font-semibold text-brand-ink">{{ __('Advanced') }}</summary>
        <div class="mt-3 space-y-4">
            <x-edge-yaml-example :file="$sourcePath">
rewrites:
  - from: /api/*
    to: https://api.example.com/:splat
            </x-edge-yaml-example>
            <div>
                <p class="text-2xs font-semibold uppercase tracking-wide text-brand-mist">{{ __('Templates') }}</p>
                <div class="mt-2 grid grid-cols-1 gap-2 sm:grid-cols-2">
                    @foreach (collect($templates)->only(['api-proxy']) as $key => $template)
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
