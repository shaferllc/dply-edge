@props([
    'message',
    'saveAction',
    'discardAction' => 'discardUnsavedChanges',
    /** Comma-separated Livewire property paths; omit for whole-component dirty state */
    'targets' => null,
    'saveDisabled' => false,
    'saveLabel' => null,
    'savePendingLabel' => null,
    'discardLabel' => null,
    /** Optional third action, shown as the primary button beside Save. */
    'extraAction' => null,
    'extraLabel' => null,
    /**
     * Livewire bool property (e.g. pipeline_form_edits_pending) for modal / conditional forms.
     * Uses Alpine x-bind alongside wire:dirty so the bar is not hidden when only the modal is dirty.
     */
    'formPendingWire' => null,
    /**
     * Track dirtiness fully client-side by diffing the targeted wire:model inputs
     * against their last-saved DOM values. Far more reliable than wire:dirty for
     * deferred checkboxes / selects (wire:dirty does not surface those here).
     * Requires `targets`; ORs in `formPendingWire` when set.
     */
    'clientDirty' => false,
    /** Modal name to close when Save or Discard is pressed, if that modal is open. */
    'closeModal' => null,
])

@php
    $saveLabel = $saveLabel ?? __('Save');
    $savePendingLabel = $savePendingLabel ?? __('Saving…');
    $discardLabel = $discardLabel ?? __('Discard');
    $useClientDirty = $clientDirty && filled($targets);
    $targetList = filled($targets)
        ? array_values(array_filter(array_map('trim', explode(',', (string) $targets))))
        : [];
@endphp

{{--
    Default `hidden` keeps the bar off until something is dirty.

    Two ways to flip it visible:
      • clientDirty: a SELF-CONTAINED inline Alpine tracker (config read from the
        data-* attributes below) diffs the targeted wire:model inputs against
        their last-saved values — reliable for deferred checkboxes/selects, which
        wire:dirty does not catch on this page. Inline x-data avoids any
        alpine:init registration-ordering race.
      • otherwise: Livewire wire:dirty.class for field edits + Alpine for modal
        forms (formPendingWire), each owning a SEPARATE show class so they never
        clobber one another.

    z-[110] sits above app modals (z-[100]) so the bar stays visible while editing in a modal.
--}}
<div
    {{ $attributes->class([
        'dply-unsaved-bar',
        'hidden',
        'pointer-events-auto fixed left-1/2 z-[110] w-[calc(100%-2rem)] max-w-3xl -translate-x-1/2 rounded-2xl border border-brand-mist/80 bg-white shadow-lg shadow-brand-forest/10',
        'bottom-24 sm:bottom-28',
    ]) }}
    @if ($useClientDirty)
        {{-- Plain comma-separated list (field names are safe identifiers). NOT
             @json — Blade's @json hex-escapes quotes, which silently breaks
             JSON.parse and leaves the tracker watching nothing. --}}
        data-unsaved-targets="{{ implode(',', $targetList) }}"
        @if (filled($formPendingWire)) data-unsaved-pending-prop="{{ $formPendingWire }}" @endif
        {{-- Dead-simple, can't-miss tracker: ANY input/change on a watched
             wire:model field flips `dirty`. Listens on `document` (capture) so
             root resolution is never a factor; resets after a Livewire commit
             (save/discard re-render). x-effect toggles the built `…-visible`
             class (display:block !important) over the base `hidden`. --}}
        x-data="{
            targets: '{{ implode(',', $targetList) }}'.split(',').filter(Boolean),
            pendingProp: '{{ $formPendingWire }}',
            dirty: false,
            init() {
                document.addEventListener('input', (e) => this.mark(e), true);
                document.addEventListener('change', (e) => this.mark(e), true);
                if (window.Livewire) {
                    window.Livewire.hook('commit', ({ succeed }) => { succeed(() => { this.dirty = false; }); });
                }
            },
            modelName(el) {
                if (! el || ! el.attributes) { return null; }
                for (let i = 0; i < el.attributes.length; i++) {
                    let a = el.attributes[i];
                    if (a.name === 'wire:model' || a.name.indexOf('wire:model.') === 0) { return a.value; }
                }
                return null;
            },
            mark(e) {
                let n = this.modelName(e.target);
                if (n && this.targets.includes(n)) { this.dirty = true; }
            }
        }"
        x-effect="$el.classList.toggle('dply-unsaved-bar-visible', dirty || (pendingProp ? !! $wire[pendingProp] : false))"
    @else
        @if (filled($targets))
            wire:target="{{ $targets }}"
            wire:dirty.class="dply-unsaved-bar-visible"
        @endif
        @if (filled($formPendingWire))
            {{-- Alpine drives its OWN show class (…-pending) so its object binding
                 never clobbers the wire:dirty.class (…-visible) that field/checkbox
                 edits set — both classes independently un-hide the bar. --}}
            x-data
            x-bind:class="{ 'dply-unsaved-bar-pending': $wire.{{ $formPendingWire }} }"
        @endif
    @endif
    role="region"
    aria-label="{{ __('Unsaved changes') }}"
>
    <div class="flex flex-col gap-3 px-4 py-3 sm:flex-row sm:items-center sm:justify-between sm:gap-4 sm:px-5 sm:py-3.5">
        <p class="text-sm text-brand-moss">{{ $message }}</p>
        <div class="flex shrink-0 flex-wrap items-center justify-end gap-2 sm:gap-2.5">
            <button
                type="button"
                wire:click="{{ $discardAction }}"
                @if (filled($closeModal)) x-on:click="$dispatch('close-modal', '{{ $closeModal }}')" @endif
                class="inline-flex items-center justify-center rounded-xl border border-brand-ink/20 bg-white px-4 py-2 text-sm font-semibold text-brand-ink shadow-sm transition hover:bg-brand-sand/40 focus:outline-none focus:ring-2 focus:ring-brand-sage/40"
            >
                <span wire:loading.remove wire:target="{{ $discardAction }}">{{ $discardLabel }}</span>
                <span wire:loading wire:target="{{ $discardAction }}" class="opacity-80">{{ __('Resetting…') }}</span>
            </button>
            <button
                type="button"
                wire:click="{{ $saveAction }}"
                @if (filled($closeModal)) x-on:click="$dispatch('close-modal', '{{ $closeModal }}')" @endif
                @disabled($saveDisabled)
                @class([
                    'inline-flex items-center justify-center rounded-xl px-4 py-2 text-sm font-semibold shadow-sm transition focus:outline-none focus:ring-2 focus:ring-brand-sage/50 disabled:cursor-not-allowed disabled:opacity-50',
                    'border border-brand-ink/20 bg-white text-brand-ink hover:bg-brand-sand/40' => filled($extraAction),
                    'bg-brand-sage text-white hover:bg-brand-sage/90' => ! filled($extraAction),
                ])
            >
                <span wire:loading.remove wire:target="{{ $saveAction }}">{{ $saveLabel }}</span>
                <span wire:loading wire:target="{{ $saveAction }}" class="inline-flex items-center gap-1.5">{{ $savePendingLabel }}</span>
            </button>
            @if (filled($extraAction))
                <button
                    type="button"
                    wire:click="{{ $extraAction }}"
                    class="inline-flex items-center justify-center rounded-xl bg-brand-sage px-4 py-2 text-sm font-semibold text-white shadow-sm transition hover:bg-brand-sage/90 focus:outline-none focus:ring-2 focus:ring-brand-sage/50"
                >
                    <span wire:loading.remove wire:target="{{ $extraAction }}">{{ $extraLabel }}</span>
                    <span wire:loading wire:target="{{ $extraAction }}" class="inline-flex items-center gap-1.5">{{ __('Redeploying…') }}</span>
                </button>
            @endif
        </div>
    </div>
</div>
