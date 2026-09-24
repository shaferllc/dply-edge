<section class="px-5 py-4 sm:px-6">
    <div class="flex flex-wrap items-baseline justify-between gap-2">
        <div>
            <p class="text-2xs font-semibold uppercase tracking-[0.16em] text-brand-mist">{{ __('Redirects') }}</p>
            <p class="mt-1 text-sm text-brand-moss">{{ __('HTTP redirects. Repo rows from :file are read-only; add dashboard rows below.', ['file' => $sourcePath]) }}</p>
        </div>
        <span wire:loading.inline-flex wire:target="addRedirect,removeRedirect,applyTemplate,importRedirects" class="inline-flex items-center gap-1.5 text-xs text-brand-moss">
            <x-spinner size="sm" variant="muted" />
            {{ __('Saving…') }}
        </span>
    </div>

    @if ($repoRedirects !== [])
        <div class="mt-3 rounded-xl border border-brand-ink/10 bg-brand-sand/20 px-3 py-2 text-xs text-brand-moss">
            {{ __(':count from :file', ['count' => count($repoRedirects), 'file' => $sourcePath]) }}
        </div>
    @endif

    @if ($dashboard_redirects === [])
        <x-empty-state
            borderless
            compact
            icon="heroicon-o-arrows-right-left"
            :title="__('No dashboard redirects yet')"
        />
    @else
        <div class="mt-3 overflow-x-auto rounded-xl border border-brand-ink/10">
            <table class="min-w-full divide-y divide-brand-ink/8 text-xs">
                <thead class="bg-brand-sand/30 text-left text-2xs font-semibold uppercase tracking-wide text-brand-mist">
                    <tr>
                        <th class="px-3 py-2">{{ __('From') }}</th>
                        <th class="px-3 py-2">{{ __('To') }}</th>
                        <th class="px-3 py-2 text-right">{{ __('Status') }}</th>
                        <th class="px-3 py-2 text-right">{{ __('Actions') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-brand-ink/8 text-brand-ink">
                    @foreach ($dashboard_redirects as $index => $rule)
                        <tr wire:key="dash-redirect-{{ $index }}">
                            <td class="px-3 py-2 font-mono break-all">{{ $rule['from'] }}</td>
                            <td class="px-3 py-2 font-mono break-all">{{ $rule['to'] }}</td>
                            <td class="px-3 py-2 text-right font-mono">{{ $rule['status'] }}</td>
                            <td class="px-3 py-2 text-right">
                                <button
                                    type="button"
                                    wire:click="openConfirmActionModal('removeRedirect', @js([$index]), @js(__('Remove redirect')), @js(__('Remove :from → :to?', ['from' => $rule['from'], 'to' => $rule['to']])), @js(__('Remove')), true)"
                                    class="inline-flex items-center gap-1.5 rounded-lg border border-rose-200 bg-white px-2.5 py-1 text-xs font-semibold uppercase tracking-wide text-rose-700 shadow-sm hover:bg-rose-50"
                                >{{ __('Remove') }}</button>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif

    <form wire:submit.prevent="addRedirect" class="mt-4 grid grid-cols-1 gap-2 sm:grid-cols-[2fr_2fr_6rem_auto] sm:items-end">
        <div>
            <label for="new-redir-from" class="text-2xs font-semibold uppercase tracking-wide text-brand-mist">{{ __('From') }}</label>
            <input id="new-redir-from" type="text" wire:model="new_redirect_from" class="mt-1 block w-full rounded-md border border-brand-ink/15 bg-white px-3 py-1.5 font-mono text-xs text-brand-ink focus:border-brand-forest focus:ring-brand-forest" placeholder="/old-page" autocomplete="off" />
            @error('new_redirect_from') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
        </div>
        <div>
            <label for="new-redir-to" class="text-2xs font-semibold uppercase tracking-wide text-brand-mist">{{ __('To') }}</label>
            <input id="new-redir-to" type="text" wire:model="new_redirect_to" class="mt-1 block w-full rounded-md border border-brand-ink/15 bg-white px-3 py-1.5 font-mono text-xs text-brand-ink focus:border-brand-forest focus:ring-brand-forest" placeholder="/new-page" autocomplete="off" />
        </div>
        <div>
            <label for="new-redir-status" class="text-2xs font-semibold uppercase tracking-wide text-brand-mist">{{ __('Status') }}</label>
            <select id="new-redir-status" wire:model="new_redirect_status" class="mt-1 block w-full rounded-md border border-brand-ink/15 bg-white px-2 py-1.5 font-mono text-xs text-brand-ink focus:border-brand-forest focus:ring-brand-forest">
                <option value="301">301</option>
                <option value="302">302</option>
                <option value="307">307</option>
                <option value="308">308</option>
            </select>
        </div>
        <button type="submit" class="rounded-lg bg-brand-ink px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-brand-ink/90">{{ __('Add') }}</button>
    </form>

    <details class="mt-4 rounded-xl border border-brand-ink/10 px-3 py-2" @if ($errors->has('bulk_redirects')) open @endif>
        <summary class="cursor-pointer text-xs font-semibold text-brand-ink">{{ __('Import in bulk') }}</summary>
        <p class="mt-2 text-xs text-brand-moss">
            {{ __('Paste a bulk-redirects CSV (source_url,target_url,status_code) or a Netlify _redirects block (/from /to 301). One rule per line; the host is dropped because rules are scoped to this site.') }}
        </p>

        <form wire:submit.prevent="importRedirects" class="mt-2">
            <label for="bulk-redirects" class="sr-only">{{ __('Redirects to import') }}</label>
            <textarea
                id="bulk-redirects"
                rows="6"
                wire:model.live.debounce.400ms="bulk_redirects"
                class="block w-full rounded-md border border-brand-ink/15 bg-white px-3 py-2 font-mono text-xs text-brand-ink focus:border-brand-forest focus:ring-brand-forest"
                placeholder="/old-page,/new-page,301&#10;example.com/blog/,https://example.com/news/,301&#10;/docs/*  /help/:splat  301"
                spellcheck="false"
            ></textarea>
            @error('bulk_redirects') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror

            @if ($bulkPreview !== null)
                <div class="mt-2 flex flex-wrap items-center gap-x-3 gap-y-1 text-xs">
                    @if ($bulkPreview['redirects'] !== [])
                        <span class="text-brand-moss">
                            {{ __('Detected :format', ['format' => $bulkPreview['format'] === 'csv' ? __('CSV') : __('list')]) }}
                            ·
                            {{ trans_choice('{1}1 rule|[2,*]:count rules', count($bulkPreview['redirects']), ['count' => count($bulkPreview['redirects'])]) }}
                        </span>
                    @endif
                    @if ($bulkPreview['errors'] !== [])
                        <span class="text-rose-600">{{ trans_choice('{1}1 line will be skipped|[2,*]:count lines will be skipped', count($bulkPreview['errors']), ['count' => count($bulkPreview['errors'])]) }}</span>
                    @endif
                </div>

                @if ($bulkPreview['errors'] !== [])
                    <ul class="mt-1 space-y-0.5 text-xs text-rose-600">
                        @foreach (array_slice($bulkPreview['errors'], 0, 5) as $error)
                            <li>{{ $error }}</li>
                        @endforeach
                        @if (count($bulkPreview['errors']) > 5)
                            <li class="text-brand-moss">{{ __('…and :count more', ['count' => count($bulkPreview['errors']) - 5]) }}</li>
                        @endif
                    </ul>
                @endif
            @endif

            <button
                type="submit"
                class="mt-2 rounded-lg bg-brand-ink px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-brand-ink/90"
            >{{ __('Import') }}</button>
        </form>
    </details>

    <details class="mt-5 border-t border-brand-ink/10 pt-4">
        <summary class="cursor-pointer text-xs font-semibold text-brand-ink">{{ __('Advanced') }}</summary>
        <div class="mt-3 space-y-4">
            <a
                href="{{ route('sites.edge.dply-yaml', ['server' => $site->server_id, 'site' => $site->id]) }}"
                class="inline-flex items-center gap-1 text-xs font-medium text-brand-sage hover:underline"
            >
                <x-heroicon-o-arrow-down-tray class="h-3.5 w-3.5" aria-hidden="true" />
                {{ __('Generate :file', ['file' => $sourcePath]) }}
            </a>
            <x-edge-yaml-example :file="$sourcePath">
redirects:
  - from: /old-page
    to: /new-page
    status: 301
  - from: /blog/*
    to: /news/:splat
    status: 301
            </x-edge-yaml-example>
            <div>
                <p class="text-2xs font-semibold uppercase tracking-wide text-brand-mist">{{ __('Templates') }}</p>
                <div class="mt-2 grid grid-cols-1 gap-2 sm:grid-cols-2">
                    @foreach (collect($templates)->only(['blog-migration']) as $key => $template)
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
