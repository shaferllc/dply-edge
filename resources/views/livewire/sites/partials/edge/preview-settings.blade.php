{{-- Settings dialogs opened from the "How previews work" rows. --}}
@php
    $canEdit = auth()->user()?->can('update', $site) ?? false;
    $closeBtn = fn (string $m) => '<button type="button" x-on:click="$dispatch(\'close-modal\', \''.$m.'\')" class="dply-icon-btn h-9 w-9 shrink-0" aria-label="'.e(__('Close')).'">'.svg('heroicon-o-x-mark', 'h-5 w-5', ['aria-hidden' => 'true'])->toHtml().'</button>';
@endphp

@unless ($edgeIsPreviewChild)
    {{-- Which pushes and pull requests get a preview (dply.yaml) --}}
    <x-modal name="preview-policy" maxWidth="xl" overlayClass="bg-brand-ink/40" focusable>
        <div class="space-y-5 p-6 sm:p-7">
            <div class="flex items-start justify-between gap-4">
                <div>
                    <h2 class="text-lg font-semibold text-brand-ink">{{ __('Which changes get a preview') }}</h2>
                    <p class="mt-0.5 text-sm text-brand-moss">{{ __('Set in :file under previews:, so it changes with your code.', ['file' => $sourcePath]) }}</p>
                </div>
                {!! $closeBtn('preview-policy') !!}
            </div>
            <dl class="divide-y divide-brand-ink/10 border-y border-brand-ink/10 text-sm">
                @foreach ([
                    [__('Pull requests'), $previewPolicy['enabled'] ? __('Get a preview') : __('Don’t get a preview')],
                    [__('Pushes to other branches'), ! $previewPolicy['enabled'] || $previewPolicy['pr_only'] ? __('Don’t get a preview') : (($previewPolicy['branches'] ?? []) !== [] ? __('Only :list', ['list' => implode(', ', $previewPolicy['branches'])]) : __('Get a preview'))],
                    [__('Never preview'), ($previewPolicy['exclude_branches'] ?? []) !== [] ? implode(', ', $previewPolicy['exclude_branches']) : '—'],
                ] as [$label, $value])
                    <div class="flex justify-between gap-3 py-2.5">
                        <dt class="text-brand-moss">{{ $label }}</dt>
                        <dd class="text-right font-mono text-brand-ink">{{ $value }}</dd>
                    </div>
                @endforeach
            </dl>
            @if ($repoPreviews === [])
                <p class="text-xs text-brand-moss">{{ __('No previews: block yet, so every pull request gets a preview. Add one to change that:') }}</p>
            @endif
            <x-edge-yaml-example :file="$sourcePath">previews:
  enabled: true
  pr_only: false
  branches: ["staging"]
  exclude_branches: ["dependabot/*"]</x-edge-yaml-example>
            <p class="text-xs text-brand-moss">
                {{ __('Pull requests from forks never get a preview. Previews use this app’s environment variables and linked secrets.') }}
                <a href="{{ route('sites.edge.dply-yaml', ['server' => $site->server_id, 'site' => $site->id]) }}" class="font-medium text-brand-sage hover:underline">{{ __('Generate :file', ['file' => $sourcePath]) }}</a>
            </p>
        </div>
    </x-modal>

    {{-- Protection --}}
    <x-modal name="preview-protection" maxWidth="xl" overlayClass="bg-brand-ink/40" focusable>
        <form
            wire:submit.prevent="saveEdgePreviewProtection"
            x-data="{ mode: @entangle('buildForm.edge_preview_protection_mode').live }"
            x-on:edge-preview-protection-saved.window="$dispatch('close-modal', 'preview-protection')"
            class="space-y-5 p-6 sm:p-7"
        >
            <fieldset @disabled(! $canEdit) class="min-w-0 space-y-5">
                <div class="flex items-start justify-between gap-4">
                    <div>
                        <h2 class="text-lg font-semibold text-brand-ink">{{ __('Who can open previews') }}</h2>
                        <p class="mt-0.5 text-sm text-brand-moss">{{ __('This gate covers the preview URLs and the live site. Visitors pass it before the app loads.') }}</p>
                    </div>
                    {!! $closeBtn('preview-protection') !!}
                </div>
                <div class="grid gap-2">
                    @foreach (['off' => [__('Anyone with the link'), __('No gate.')], 'password' => [__('People with a password'), __('One shared password.')], 'dply_account' => [__('People signed in to dply'), __('Anyone who can view this app, or only the emails you list.')]] as $value => [$title, $help])
                        <label class="flex cursor-pointer items-start gap-3 rounded-xl border px-4 py-3" :class="mode === '{{ $value }}' ? 'border-brand-sage' : 'border-brand-ink/10'">
                            <input type="radio" x-model="mode" value="{{ $value }}" class="mt-1 accent-brand-sage">
                            <span><span class="block text-sm font-semibold text-brand-ink">{{ $title }}</span><span class="block text-xs text-brand-moss">{{ $help }}</span></span>
                        </label>
                    @endforeach
                    @error('buildForm.edge_preview_protection_mode') <x-sheet.note tone="danger">{{ $message }}</x-sheet.note> @enderror
                </div>
                <div x-show="mode === 'password'" x-cloak>
                    <x-sheet.field :label="__('Password')" for="preview-password">
                        <input id="preview-password" type="password" wire:model="buildForm.edge_preview_protection_password" autocomplete="new-password" placeholder="{{ __('Leave blank to keep the current one') }}" class="dply-input mt-0" />
                    </x-sheet.field>
                    @error('buildForm.edge_preview_protection_password') <x-sheet.note tone="danger">{{ $message }}</x-sheet.note> @enderror
                </div>
                <div x-show="mode === 'dply_account'" x-cloak>
                    <x-sheet.field :label="__('Only these emails (optional)')" for="preview-emails">
                        <textarea id="preview-emails" wire:model="buildForm.edge_preview_protection_allowed_emails" rows="3" spellcheck="false" placeholder="reviewer@example.com" class="dply-input mt-0 font-mono text-xs"></textarea>
                    </x-sheet.field>
                    @error('buildForm.edge_preview_protection_allowed_emails') <x-sheet.note tone="danger">{{ $message }}</x-sheet.note> @enderror
                </div>
                @if ($canEdit)
                    <div class="flex justify-end gap-2">
                        <x-sheet.button type="button" x-on:click="$dispatch('close-modal', 'preview-protection')">{{ __('Cancel') }}</x-sheet.button>
                        <x-sheet.button type="submit" variant="primary" wire:loading.attr="disabled" wire:target="saveEdgePreviewProtection">{{ __('Save') }}</x-sheet.button>
                    </div>
                @endif
            </fieldset>
        </form>
    </x-modal>

    {{-- Comment widget --}}
    <x-modal name="preview-comments" maxWidth="lg" overlayClass="bg-brand-ink/40" focusable>
        <form wire:submit.prevent="saveEdgeCommentWidget" class="space-y-5 p-6 sm:p-7">
            <fieldset @disabled(! $canEdit) class="min-w-0 space-y-5">
                <div class="flex items-start justify-between gap-4">
                    <h2 class="text-lg font-semibold text-brand-ink">{{ __('Review notes on previews') }}</h2>
                    {!! $closeBtn('preview-comments') !!}
                </div>
                <x-sheet.toggle wire:model="buildForm.edge_comment_widget_enabled" :label="__('Show the comment widget on preview pages')" :help="__('Reviewers pin notes to the page. It never appears on the live site.')" />
                @if ($canEdit)
                    <div class="flex justify-end gap-2">
                        <x-sheet.button type="button" x-on:click="$dispatch('close-modal', 'preview-comments')">{{ __('Cancel') }}</x-sheet.button>
                        <x-sheet.button type="submit" variant="primary" x-on:click="$dispatch('close-modal', 'preview-comments')" wire:loading.attr="disabled" wire:target="saveEdgeCommentWidget">{{ __('Save') }}</x-sheet.button>
                    </div>
                @endif
            </fieldset>
        </form>
    </x-modal>
@endunless
