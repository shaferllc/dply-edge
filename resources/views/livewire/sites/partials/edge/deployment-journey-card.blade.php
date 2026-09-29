{{-- A running deploy's progress lives in the deploy pill at the bottom of
     every page (partials/deploy-pill). This is the pointer to it: pass
     $deployment (or $deploymentId), and $autoOpen to open the pill on load. --}}
@php $pillDeploymentId = (string) ($deploymentId ?? $deployment->id); @endphp
<div
    class="flex flex-wrap items-center justify-between gap-2 px-5 py-3 sm:px-6"
    x-data
    @if ($autoOpen ?? false) x-init="$nextTick(() => $dispatch('dply-deploy-pill-open', { id: @js($pillDeploymentId) }))" @endif
    {{-- Its deploy finished: redraw the page around it. A first deploy's page
         becomes the workspace, so load it again (after the publish job has
         marked the app active); elsewhere a Livewire refresh will do. --}}
    x-on:dply-deploy-finished.window="if ($event.detail.id !== @js($pillDeploymentId)) return; @if ($autoOpen ?? false) setTimeout(() => window.Livewire.navigate(window.location.href), 1500) @else try { $wire.$refresh() } catch (e) { window.Livewire.navigate(window.location.href) } @endif"
    wire:key="deploy-pill-note-{{ $pillDeploymentId }}"
>
    <p class="flex items-center gap-2 text-sm text-brand-moss">
        <span class="h-2 w-2 shrink-0 animate-pulse rounded-full bg-brand-sage" aria-hidden="true"></span>
        {{ __('Deploying. Follow it in the bar at the bottom of the page.') }}
    </p>
    <button type="button" x-on:click="$dispatch('dply-deploy-pill-open', { id: @js($pillDeploymentId) })" class="text-xs font-semibold text-brand-forest hover:underline dark:text-brand-sage">{{ __('Show progress') }}</button>
</div>
