@can('update', $site)
    @if (! $edgeIsPreviewChild)
        <section id="edge-previews-protection" class="scroll-mt-24 border-b border-brand-ink/10">
            <div class="border-b border-brand-ink/10 bg-brand-sand/15 px-5 py-3 sm:px-6">
                <p class="text-2xs font-semibold uppercase tracking-[0.16em] text-brand-mist">{{ __('Protection') }}</p>
                <p class="mt-0.5 text-xs text-brand-moss">{{ __('Locks preview URLs and the live site. Visitors must pass the gate before the app loads.') }}</p>
            </div>
            <form
                wire:submit.prevent="saveEdgePreviewProtection"
                x-data="{ mode: @entangle('buildForm.edge_preview_protection_mode').live }"
                class="space-y-4 px-5 py-4 sm:px-6"
            >
                <fieldset class="flex flex-wrap gap-4 text-sm text-brand-ink">
                    <legend class="sr-only">{{ __('Preview protection mode') }}</legend>
                    <label class="inline-flex items-center gap-2">
                        <input type="radio" x-model="mode" value="off" class="border-brand-ink/20 text-brand-sage focus:ring-brand-sage/40" />
                        <span class="font-medium">{{ __('Off') }}</span>
                    </label>
                    <label class="inline-flex items-center gap-2">
                        <input type="radio" x-model="mode" value="password" class="border-brand-ink/20 text-brand-sage focus:ring-brand-sage/40" />
                        <span class="font-medium">{{ __('Password') }}</span>
                    </label>
                    <label class="inline-flex items-center gap-2">
                        <input type="radio" x-model="mode" value="dply_account" class="border-brand-ink/20 text-brand-sage focus:ring-brand-sage/40" />
                        <span class="font-medium">{{ __('Dply account') }}</span>
                    </label>
                    @error('buildForm.edge_preview_protection_mode')
                        <p class="basis-full text-xs text-rose-700">{{ $message }}</p>
                    @enderror
                </fieldset>

                <div x-show="mode === 'password'" x-cloak>
                    <label class="block">
                        <span class="block text-xs font-semibold uppercase tracking-[0.12em] text-brand-moss">{{ __('Password') }}</span>
                        <input
                            type="password"
                            wire:model="buildForm.edge_preview_protection_password"
                            autocomplete="new-password"
                            placeholder="{{ __('Leave blank to keep current') }}"
                            class="mt-1.5 w-full max-w-md rounded-lg border border-brand-ink/15 bg-white px-3 py-2 text-sm text-brand-ink shadow-sm focus:border-brand-sage focus:ring-1 focus:ring-brand-sage dark:border-brand-mist/20 dark:bg-zinc-900"
                        />
                        @error('buildForm.edge_preview_protection_password')
                            <p class="mt-1 text-xs text-rose-700">{{ $message }}</p>
                        @enderror
                    </label>
                </div>

                <div x-show="mode === 'dply_account'" x-cloak>
                    <label class="block">
                        <span class="block text-xs font-semibold uppercase tracking-[0.12em] text-brand-moss">{{ __('Allowed emails') }}</span>
                        <textarea
                            wire:model="buildForm.edge_preview_protection_allowed_emails"
                            rows="3"
                            spellcheck="false"
                            placeholder="reviewer@example.com"
                            class="mt-1.5 w-full max-w-lg rounded-lg border border-brand-ink/15 bg-white px-3 py-2 font-mono text-xs text-brand-ink shadow-sm focus:border-brand-sage focus:ring-1 focus:ring-brand-sage dark:border-brand-mist/20 dark:bg-zinc-900"
                        ></textarea>
                        <p class="mt-1 text-xs text-brand-moss">{{ __('Optional. Empty = any signed-in user who can view this site.') }}</p>
                        @error('buildForm.edge_preview_protection_allowed_emails')
                            <p class="mt-1 text-xs text-rose-700">{{ $message }}</p>
                        @enderror
                    </label>
                </div>

                <button
                    type="submit"
                    wire:loading.attr="disabled"
                    wire:target="saveEdgePreviewProtection"
                    class="inline-flex items-center gap-1.5 rounded-lg bg-brand-ink px-4 py-2 text-xs font-semibold text-white shadow-sm hover:bg-brand-ink/90 disabled:cursor-wait disabled:opacity-60"
                >
                    <x-spinner variant="white" size="sm" wire:loading wire:target="saveEdgePreviewProtection" />
                    <span wire:loading.remove wire:target="saveEdgePreviewProtection">{{ __('Save protection') }}</span>
                    <span wire:loading wire:target="saveEdgePreviewProtection">{{ __('Saving…') }}</span>
                </button>
            </form>
        </section>

        <section class="border-b border-brand-ink/10">
            <form wire:submit.prevent="saveEdgeCommentWidget" class="flex flex-wrap items-center justify-between gap-3 px-5 py-4 sm:px-6">
                <label class="flex min-w-0 items-start gap-3 text-sm text-brand-ink">
                    <input type="checkbox" wire:model="buildForm.edge_comment_widget_enabled" class="mt-0.5 rounded border-brand-ink/20 text-brand-sage shadow-sm focus:ring-brand-sage/40" />
                    <span>
                        <span class="font-medium">{{ __('Comment widget on previews') }}</span>
                        <span class="mt-0.5 block text-xs text-brand-moss">{{ __('Floating review notes on preview URLs only.') }}</span>
                    </span>
                </label>
                <button
                    type="submit"
                    wire:loading.attr="disabled"
                    wire:target="saveEdgeCommentWidget"
                    class="inline-flex items-center gap-1.5 rounded-lg border border-brand-ink/15 bg-white px-3 py-1.5 text-xs font-semibold text-brand-ink hover:bg-brand-sand/40 disabled:cursor-wait disabled:opacity-60"
                >
                    <span wire:loading.remove wire:target="saveEdgeCommentWidget">{{ __('Save') }}</span>
                    <span wire:loading wire:target="saveEdgeCommentWidget">{{ __('Saving…') }}</span>
                </button>
            </form>
        </section>
    @endif
@endcan
