@props([
    'name',
    'show' => false,
    'maxWidth' => '2xl',
    'overlayClass' => 'bg-black/50',
    'panelClass' => 'dply-modal-panel overflow-hidden shadow-xl',
])

@php
$maxWidth = [
    'sm' => 'sm:max-w-sm',
    'md' => 'sm:max-w-md',
    'lg' => 'sm:max-w-lg',
    'xl' => 'sm:max-w-xl',
    '2xl' => 'sm:max-w-2xl',
    '3xl' => 'sm:max-w-3xl',
    '4xl' => 'sm:max-w-4xl',
    '5xl' => 'sm:max-w-5xl',
    '6xl' => 'sm:max-w-6xl',
][$maxWidth];
@endphp

{{-- Teleport keeps fixed overlays above nested layout (sidebar, sticky header) stacking contexts. --}}
@teleport('body')
<div
    x-data="{
        show: @js($show),
        destroy() { document.body.classList.remove('overflow-y-hidden') },
    }"
    x-init="$watch('show', value => {
        if (value) {
            document.body.classList.add('overflow-y-hidden');
        } else {
            document.body.classList.remove('overflow-y-hidden');
        }
    })"
    x-on:open-modal.window="$event.detail == '{{ $name }}' ? show = true : null"
    x-on:close-modal.window="$event.detail == '{{ $name }}' ? show = false : null"
    x-on:close.stop="show = false"
    {{-- Teleported nodes survive a wire:navigate page swap, so without this the
         overlay stays fixed over the new page and the body keeps its scroll lock
         — which reads as a blank white screen. destroy() does not fire for
         teleported content here, so close on the navigation event itself. --}}
    x-on:livewire:navigating.window="show = false; document.body.classList.remove('overflow-y-hidden')"
    x-on:keydown.escape.window="show = false"
    {{-- x-trap (Alpine focus): holds Tab inside, joins the sheets' focus-trap
         stack so a modal over a sheet pauses it, and returns focus on close. --}}
    x-trap="show"
    x-show="show"
    class="fixed inset-0 isolate overflow-y-auto px-4 py-6 sm:px-0 z-[100]"
    style="display: {{ $show ? 'block' : 'none' }};"
>
    <div
        x-show="show"
        class="fixed inset-0 z-0 transform transition-all"
        x-on:click="show = false"
        x-transition:enter="ease-out duration-300"
        x-transition:enter-start="opacity-0"
        x-transition:enter-end="opacity-100"
        x-transition:leave="ease-in duration-200"
        x-transition:leave-start="opacity-100"
        x-transition:leave-end="opacity-0"
    >
        <div class="absolute inset-0 {{ $overlayClass }}"></div>
    </div>

    <div
        x-show="show"
        role="dialog"
        aria-modal="true"
        class="relative z-10 mb-6 transform transition-all sm:w-full {{ $maxWidth }} sm:mx-auto {{ $panelClass }}"
        x-transition:enter="ease-out duration-300"
        x-transition:enter-start="opacity-0 translate-y-4 sm:translate-y-0 sm:scale-95"
        x-transition:enter-end="opacity-100 translate-y-0 sm:scale-100"
        x-transition:leave="ease-in duration-200"
        x-transition:leave-start="opacity-100 translate-y-0 sm:scale-100"
        x-transition:leave-end="opacity-0 translate-y-4 sm:translate-y-0 sm:scale-95"
    >
        {{ $slot }}
    </div>
</div>
@endteleport
